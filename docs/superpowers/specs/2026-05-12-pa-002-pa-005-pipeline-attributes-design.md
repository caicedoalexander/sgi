# PA-002 + PA-005 — Atributos de gating + `DenialReason`

**Fecha:** 2026-05-12
**Auditoría origen:** `docs/audits/permissions-audit-2026-05-11.md` (PA-002 🔴, PA-005 🟠)
**Hallazgos cubiertos:** PA-002, PA-005. Cierra como efecto colateral PA-006 (`$pipelineActions` repetido) y PA-010 (`_actionToPermission` lista plana).
**Severidad:** Critical (PA-002) + Major (PA-005)
**Esfuerzo estimado:** L (~+400 / -200 LoC, 8 commits, 1 PR)
**Predecesores:** PA-001 (commit `0d84bd7`), PA-003 (PR #4 / `1c73514`).

---

## Contexto

Hoy registrar una acción de pipeline en el SGI requiere tocar **tres lugares sin chequeo cruzado**:

1. `$pipelineActions` del controller — si se olvida, el gate CRUD se aplica de más.
2. `_actionToPermission` en `AppController` — si se olvida (post PA-001), `LogicException 500` al primer hit.
3. Llamada manual a `$this->pipelineAuth->canOperate(...)` dentro del método — si se olvida, **acción sin gate de paso**.

PA-001 cerró parcialmente el problema (lanza al menos en (2)), pero (1) y (3) siguen siendo "olvidos silenciosos" que materializan over-permission por configuración.

En paralelo, **PA-005** señala que `InvoicePipelineService::canAdvance/canRegress` y sus equivalentes en Novelty/PaymentScheduling/Refund devuelven un `bool` que **mezcla** dos motivos:

- "No hay next/previous status" (estado terminal).
- "El rol no tiene `pipeline_permission` del step".

El caller no puede distinguirlos y termina recalculando `getNextStatus(...)` para componer mensajes — patrón duplicado en cada acción de avance/regreso. Resolver PA-002 sin tocar PA-005 deja el área de código a medio refactorizar; resolverlos juntos permite que el nuevo gate del middleware delegue uniformemente.

## Objetivo

1. Reducir los **tres lugares de olvido** a **uno**: una anotación PHP en el método. Falta de anotación ⇒ `LogicException 500` al primer hit (loud-and-clear).
2. Reemplazar el `bool` revuelto de `canAdvance/canRegress` por `?DenialReason`, exponiendo el motivo de denegación a callers y templates.
3. Eliminar `$pipelineActions` y `_actionToPermission` por completo. `controllerModuleMap` se conserva (mapea controller → módulo y no es la fuente del bug).

## No-objetivos

- **PA-004** (`AuthorizationFacade` común entre `AuthorizationService` y `PipelineAuthorizationService`) — refactor independiente; el middleware nuevo habla directo contra los dos services hoy y se migrará al Facade cuando se haga PA-004.
- **PA-007** (admin bypass + seeder) — el bypass `ADMIN_BYPASS_MODULES` queda intacto.
- **PA-011** (Policy objects per dominio) — el patrón Advance (Policy objects) no se propaga aún; los controllers siguen llamando `canOperate` inline donde aplique (acciones dinámicas).
- **Tests automatizados** — política del proyecto (`CLAUDE.md` §Testing Policy). Validación manual al final.
- **Feature flag** — el cambio es loud-and-clear desde el merge; no se introduce fallback configurable.

## Decisiones de diseño

| Decisión | Elegido | Descartado |
|---|---|---|
| Nivel de ambición PA-002 | Medio: atributo con step explícito en estáticas, sin step en dinámicas | Mínimo (mantener llamada manual en todas), Máximo (inferir step del nombre de método) |
| Alcance del refactor | Big-bang: 7 controllers + 4 services en una PR multi-commit | Piloto Invoice + resto en PR posterior |
| Bundleo PA-002 + PA-005 | Juntas (PA-005 primero, PA-002 después dentro de la misma PR) | Atomizar cada una en PR separada |
| Ubicación del gate | Extender `AppController::_enforcePermission` con lectura de atributos por reflexión | Middleware nuevo, helper service externo |
| Manejo de excepciones | Atributo explícito en TODO método público (`#[NoAuthGate]` con motivo obligatorio para excepciones) | Allowlist de pares (controller, action) hardcoded |
| Steps dinámicos (`advanceStatus`/`regressStatus`/etc.) | `#[PipelineAction(pipeline)]` sin step → gate manual con `denialReasonForAdvance` | Resolver callable, atributo separado `#[DynamicPipelineAction]` |

## Diseño técnico

### 1. Atributos PHP

Tres clases en `src/Attribute/`:

```php
namespace App\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Permission
{
    public function __construct(public readonly string $action) {}
    // $action: 'view' | 'add' | 'edit' | 'delete'
}

#[\Attribute(\Attribute::TARGET_METHOD)]
final class PipelineAction
{
    public function __construct(
        public readonly string $pipeline,    // PipelineStepConstants::PIPELINE_*
        public readonly ?string $step = null, // null ⇒ gate manual
    ) {}
}

#[\Attribute(\Attribute::TARGET_METHOD)]
final class NoAuthGate
{
    public function __construct(public readonly string $reason) {}
    // $reason: motivo documentado (login flow, internal delegation, etc.)
}
```

### 2. Gate reescrito en `AppController::_enforcePermission`

Pseudocódigo del flujo:

```
identity = Authentication->getIdentity()
if !identity: return  // AuthenticationMiddleware ya redirige a login

action = request->param('action')
attribute = reflectActionMethod($this, $action)

switch (attribute):
  case NoAuthGate:
    return  // skip total

  case Permission(action):
    module = controllerModuleMap[$controllerName] ?? throw
    if !authService->isAllowed(roleId, roleName, module, attribute->action):
      throw ForbiddenException("No tiene permisos para {action} en {module}.")
    return

  case PipelineAction(pipeline, step):
    if step !== null:  // estática
      if !pipelineAuth->canOperate(roleId, pipeline, step):
        throw ForbiddenException("No tiene permisos para operar este paso.")
    // step === null ⇒ dinámica, el método decide internamente
    return

  case null:
    throw LogicException("Action '{controller}::{action}' has no auth attribute. " .
                         "Annotate with #[Permission], #[PipelineAction] or #[NoAuthGate].")
```

**Detalles:**
- La reflexión se hace sobre el método público del controller actual (`new ReflectionMethod($this, $action)`).
- Solo el **primer** atributo encontrado se aplica. No se permite combinar (p. ej. `#[Permission]` + `#[PipelineAction]` en el mismo método).
- Si el controller no extiende `AppController` (Pages, Error en CakePHP base), `beforeFilter` no se ejecuta y el gate no aplica — comportamiento idéntico al actual.

### 3. PA-005: enum `DenialReason`

Nuevo enum en `src/Constants/Domain/Pipeline/DenialReason.php`:

```php
namespace App\Constants\Domain\Pipeline;

enum DenialReason: string
{
    case TERMINAL_STATE = 'terminal_state';
    case UNAUTHORIZED   = 'unauthorized';
    case REJECTED       = 'rejected';
    case MISSING_FIELDS = 'missing_fields';

    public function message(): string
    {
        return match ($this) {
            self::TERMINAL_STATE => 'El registro ya está en su estado final.',
            self::UNAUTHORIZED   => 'No tiene permisos para avanzar este registro.',
            self::REJECTED       => 'El registro fue rechazado y no puede avanzar.',
            self::MISSING_FIELDS => 'Faltan campos requeridos para avanzar.',
        };
    }
}
```

**Nuevos métodos en los 4 services afectados:**

| Service | Método nuevo | Método legacy (delega) |
|---|---|---|
| `InvoicePipelineService` | `denialReasonForAdvance(Invoice $i, int $roleId): ?DenialReason` <br> `denialReasonForRegress(Invoice $i, int $roleId): ?DenialReason` | `canAdvance(...)`, `canRegress(...)` |
| `NoveltyService` | `denialReasonForAdvance(EmployeeNovelty $n, int $roleId): ?DenialReason` | `canAdvanceFromStatus(...)` |
| `PaymentSchedulingService` | `denialReasonForAdvance(PaymentScheduling $p, int $roleId): ?DenialReason` <br> `denialReasonForRegress(...)` | `canAdvance(...)`, `canReject(...)`, `canRegress(...)` |
| `RefundService` | `denialReasonForRegress(Refund $r, int $roleId): ?DenialReason` | `canRegress(...)` |

**Semántica:** `null` ⇒ puede avanzar. Cualquier `DenialReason` ⇒ no puede, con motivo explícito.

**Estrategia de compatibilidad:** durante commits 1–7 los métodos `canAdvance/canRegress` legacy delegan al nuevo método (`return $this->denialReasonForAdvance(...) === null`). Commit 8 elimina los delegadores y obliga a todos los callers a usar `DenialReason`.

### 4. Inventario de acciones de pipeline (action ↔ step)

Acciones recolectadas de los 7 `$pipelineActions` actuales (referencia: auditoría §PA-002).

**Estáticas — `#[PipelineAction(pipeline, step)]`:**

| Controller | Acción | Pipeline | Step |
|---|---|---|---|
| `InvoicePayments` | `addPayment`, `editPayment`, `deletePayment` | `PIPELINE_INVOICES` | `STATUS_TESORERIA` |
| `InvoicePayments` | `authorizePayment`, `rejectPayment` | `PIPELINE_INVOICES` | `STATUS_AUTORIZACION_PAGO` |
| `InvoicePayments` | `confirmPayment` | `PIPELINE_INVOICES` | `STATUS_VERIFICACION_PAGO` |
| `LiquidationDocPayments` | `addPayment` | `PIPELINE_LIQUIDATION_DOCS` | `STATUS_TESORERIA` |
| `LiquidationDocPayments` | `authorizePayment`, `rejectPayment` | `PIPELINE_LIQUIDATION_DOCS` | `STATUS_AUTORIZACION_PAGO` |
| `LiquidationDocPayments` | `confirmPayment` | `PIPELINE_LIQUIDATION_DOCS` | `STATUS_VERIFICACION_PAGO` |
| `PettyCashRecords` | `registerPayment` | `PIPELINE_PETTY_CASH` | `STATUS_TESORERIA` |
| `PettyCashRecords` | `authorizePayment`, `rejectPayment` | `PIPELINE_PETTY_CASH` | `STATUS_AUTORIZACION_PAGO` |
| `PettyCashRecords` | `confirmPayment` | `PIPELINE_PETTY_CASH` | `STATUS_VERIFICACION_PAGO` |
| `Refunds` | `registerPayment` | `PIPELINE_REFUNDS` | `STATUS_TESORERIA` |
| `Refunds` | `authorizePayment`, `rejectPayment` | `PIPELINE_REFUNDS` | `STATUS_AUTORIZACION_PAGO` |
| `Refunds` | `confirmPayment` | `PIPELINE_REFUNDS` | `STATUS_VERIFICACION_PAGO` |
| `PaymentSchedulings` | `confirmPayment` | `PIPELINE_PAYMENT_SCHEDULINGS` | `STATUS_VERIFICACION_PAGO` |
| `Advances` | `moveToRevision`, `markSigned`, `returnToValidacion`, `markExact` | `PIPELINE_LEGALIZATIONS` | `STATUS_REVISION_FIRMAS` |
| `Advances` | `linkCandidates`, `linkInvoices`, `unlinkInvoice`, `uploadRelationDocument` | `PIPELINE_LEGALIZATIONS` | a verificar por inspección del controller (probable `STATUS_TESORERIA` o `STATUS_VERIFICACION_PAGO`) |
| `Advances` | `registerShortage`, `confirmShortage`, `registerSurplus`, `registerRefund`, `confirmRefundPayment` | `PIPELINE_LEGALIZATIONS` | a verificar — probablemente `STATUS_VERIFICACION_PAGO` o `STATUS_REVISION_FIRMAS` |

**Dinámicas — `#[PipelineAction(pipeline)]` (sin step, gate manual con `denialReasonForAdvance/Regress` dentro):**

| Controller | Acciones | Pipeline |
|---|---|---|
| `Invoices` | `advanceStatus`, `regressStatus` | `PIPELINE_INVOICES` |
| `PettyCashRecords` | `advanceStatus`, `regressStatus` | `PIPELINE_PETTY_CASH` |
| `Refunds` | `advanceStatus`, `regressStatus`, `uploadDocument`, `deleteDocument` | `PIPELINE_REFUNDS` |
| `PaymentSchedulings` | `advance`, `reject`, `regressStatus` | `PIPELINE_PAYMENT_SCHEDULINGS` |

> **Nota:** las celdas marcadas "a verificar por inspección" en la tabla de Advances son intencionalmente provisionales en este spec. La resolución exacta de cada step (o reclasificación a dinámica) **es trabajo de commit 5**: se inspecciona el cuerpo de cada acción en `AdvancesController` y `AdvanceLegalizationActionPolicy`, se identifica contra qué step llama `canOperate`, y se decide:
> - Si el step es fijo (no depende de estado runtime) ⇒ `#[PipelineAction(PIPELINE_LEGALIZATIONS, step: STATUS_X)]`.
> - Si depende del estado del agregado ⇒ `#[PipelineAction(PIPELINE_LEGALIZATIONS)]` sin step + `canOperate` inline.
>
> Estas decisiones se documentan en el commit message correspondiente; no requieren cambio de spec.

### 5. Acciones no-pipeline anotadas con `#[Permission]` o `#[NoAuthGate]`

Todas las acciones públicas de controllers que extienden `AppController` reciben atributo. La distribución típica:

- `#[Permission(action: 'view')]` para `index`, `view`, `export`, `exportConfig`, `all`, `rejected`, `exportPdf`, `preview`, `active`, `activeEvents`, `allEvents`, `legalization`, `downloadDocument`, `pendingLegalization`, `overdue`, `pending`.
- `#[Permission(action: 'add')]` para `add`, `addFolder`, `uploadDocument`, `import`, `importExcel`, `importUpload`, `importProcess`, `previewImport`, `confirmImport`, `addItem`, `uploadAttachment`, `addPayment`, `uploadLiquidationDocument`.
- `#[Permission(action: 'edit')]` para `edit`, `addObservation`, `testSmtp`, `regenerateApiKey`, `approve`, `reject` (de catálogos / aprobación, no de pipeline), `deactivate`, `saveFields`, `removeInvoice`, `advance` (de catálogos, no de pipeline), `advanceGroup`, `addSignature`, `assignLiquidation`, `getFlags`, `sendApprovalLinks`, `modifyApprovers`, `resetFlow`, `upload`, `retryAllFailed`, `resendApproval`, `updateLiquidationDocument`.
- `#[Permission(action: 'delete')]` para `delete`, `deleteDocument` (cuando no es pipeline), `removeItem`, `deleteAttachment`.
- `#[NoAuthGate(reason: '...')]` para:
  - `Users::login` — "External flow before auth"
  - `Users::logout` — "Always available to logged-in users"
  - `EmailLogs::retry` — "Permission delegated internally to entity-specific module"
  - `PagesController::display` — "Static content; no domain module attached"
  - `ErrorController` (cualquier acción pública que pueda exponer) — "Error renderer; never authorized via permissions table"

### 6. Lo que desaparece

| Símbolo | Ubicación | Reemplazo |
|---|---|---|
| `$pipelineActions` | 7 controllers + AppController:74 | Atributo `#[PipelineAction]` por método |
| `_actionToPermission()` | AppController:113-127 | Atributo `#[Permission(action)]` por método |
| Bloques `if controllerName==='Users' && in_array(action, ['login', 'logout'])` | AppController:186-188 | `#[NoAuthGate(reason)]` en `Users::login/logout` |
| Bloque `if controllerName==='EmailLogs' && action==='retry'` | AppController:193-195 | `#[NoAuthGate(reason)]` en `EmailLogs::retry` |
| Bloque `if in_array(action, $this->pipelineActions, true)` | AppController:203-205 | Lógica del switch sobre atributos |
| `canAdvance/canRegress` (4 services) | varios | `denialReasonForAdvance/Regress` |

### 7. Lo que se mantiene

- `controllerModuleMap` — sigue siendo la fuente única "controller → módulo" para `#[Permission]`.
- `AuthorizationService::isAllowed(...)` y `PipelineAuthorizationService::canOperate(...)` — la lógica de consulta a BD no cambia.
- `ADMIN_BYPASS_MODULES` y todo el flujo del rol Administrador (es PA-007).
- (Eliminado) El skip "controller fuera de `controllerModuleMap` ⇒ return" desaparece: Pages y Error extienden `AppController` y reciben `#[NoAuthGate]` explícito en sus acciones públicas.
- La estrategia `Flash + redirect` que usan algunas acciones dinámicas para errores de pipeline no fatales — el método decide UX, el gate solo decide acceso.

## Orden de commits (PR multi-commit)

Cada commit debe dejar la app funcional. `composer cs-check` debe pasar en cada uno.

| # | Commit | Cambia | Ruptura |
|---|---|---|---|
| 1 | `feat(pipeline): DenialReason enum + denialReasonForAdvance/Regress en 4 services` | Añade enum y métodos. `canAdvance/canRegress` legacy delegan internamente al nuevo método. | Cero (additive). |
| 2 | `feat(auth): atributos Permission/PipelineAction/NoAuthGate` | Crea las 3 clases en `src/Attribute/`. Aún no se usan. | Cero. |
| 3 | `feat(auth): _enforcePermission lee atributos con fallback legacy` | Refactor del método: si la acción tiene atributo → nuevo flujo; si no → fallback al match + `$pipelineActions` actual. Permite migrar incrementalmente. | Cero. |
| 4 | `refactor(controllers): anotar controllers no-pipeline con #[Permission]/#[NoAuthGate]` | Todos los métodos públicos de los ~22 controllers no-pipeline reciben atributo. `Users::login/logout` y `EmailLogs::retry` reciben `#[NoAuthGate]`. | Cero (fallback activo). |
| 5 | `refactor(pipeline): anotar 7 controllers de pipeline con #[PipelineAction]` | Acciones estáticas reciben step explícito; dinámicas reciben atributo sin step y siguen llamando `canOperate`/`denialReasonForAdvance` inline. | Cero. |
| 6 | `refactor(auth): eliminar $pipelineActions, _actionToPermission y la rama legacy` | Borra propiedad y mapeo. Atributo faltante ⇒ `LogicException 500`. | **Punto de no retorno.** Si alguna acción quedó sin anotar, falla al primer hit. |
| 7 | `refactor(pipeline): migrar callers de canAdvance/canRegress a denialReason` | Templates, ViewModels y controllers usan `denialReasonForAdvance(...) === null` o `->message()` para Flash. | Cero (legacy delega). |
| 8 | `chore(pipeline): eliminar canAdvance/canRegress deprecados` | Borra los delegadores en los 4 services. | Si quedó un caller sin migrar, error en boot. |

**Rollback:** parcial es viable hasta antes del commit 6. Después, requiere hotfix anotando la acción olvidada.

## Criterios de validación manual

Sin tests automatizados (política `CLAUDE.md` §Testing Policy). Cuatro bloques:

### Bloque 1 — Smoke del nuevo gate (commits 1–3)

1. `php bin/cake server` arranca sin errores.
2. Login con `admin` y con un rol no-admin (Tesorería) — ambos llegan al dashboard.
3. CRUD básico (listado y view) en `/invoices`, `/refunds`, `/petty-cash-records`, `/employee-novelties` funciona idéntico para cada rol.

### Bloque 2 — Matriz de gating (commits 4–5)

Por cada fila, ejecutar la acción con un rol con permiso y un rol sin permiso. Verificar el comportamiento esperado:

| Acción | Rol con permiso | Rol sin permiso |
|---|---|---|
| `Users::login`, `Users::logout` | Acceso sin sesión | Acceso sin sesión |
| `EmailLogs::retry` (entity=invoice) | Rol con `invoices.can_edit` ⇒ ejecuta | Rol sin `invoices.can_edit` ⇒ 403 |
| `Invoices::edit` (`aprobacion`) | Registro ⇒ ejecuta | Tesorería ⇒ 403 |
| `Invoices::advanceStatus` (`tesoreria`) | Tesorería con pipeline_permission ⇒ avanza | Contabilidad ⇒ Flash + redirect con motivo |
| `InvoicePayments::authorizePayment` | Contador con pipeline_permission(`autorizacion_pago`) ⇒ ejecuta | Tesorería ⇒ 403 |
| `InvoicePayments::confirmPayment` | Tesorería con pipeline_permission(`verificacion_pago`) ⇒ ejecuta | Contador ⇒ 403 |
| `Advances::markSigned` | Rol con pipeline_permission(`revision_firmas`) ⇒ ejecuta | Otro rol ⇒ 403 |
| `Refunds::advanceStatus` (`tesoreria`) | Tesorería ⇒ avanza | Otro ⇒ Flash + redirect |
| `PettyCashRecords::registerPayment` | Tesorería ⇒ ejecuta | Otro ⇒ 403 |
| `PaymentSchedulings::advance` | Rol del step actual ⇒ avanza | Otro ⇒ Flash + redirect |
| `EmployeeNovelties::advance` | Auxiliar de Personal con permiso del step ⇒ avanza | Otro ⇒ 403 |

### Bloque 3 — `DenialReason` (commit 7)

Ejercitar las 4 ramas del enum desde la UI:

| Caso | Setup | Mensaje esperado |
|---|---|---|
| `TERMINAL_STATE` | Factura en `pagada`, botón "Avanzar" | "El registro ya está en su estado final." |
| `UNAUTHORIZED` | Factura en `aprobacion`, rol Tesorería sin pipeline_permission | "No tiene permisos para avanzar este registro." |
| `REJECTED` | Factura con `area_approval='Rechazada'` | Motivo de rechazo específico (no genérico) |
| `MISSING_FIELDS` | Factura en `tesoreria` sin pagos registrados | Lista de campos faltantes |

### Bloque 4 — Punto de no retorno (commit 6)

1. Añadir temporalmente `public function dummyMissing(): \Cake\Http\Response` en `InvoicesController` **sin atributo**.
2. Visitar `/invoices/dummy-missing` con sesión iniciada.
3. Confirmar response **500** con `LogicException` citando `InvoicesController::dummyMissing`.
4. Eliminar el método temporal.

### Bloque 5 — Happy path E2E por pipeline

Smoke completo del flujo principal en cada pipeline. Si los 6 completan sin Flash inesperado, la PR está lista para merge:

1. **Factura estándar**: crear → `aprobacion` → aprobación externa → `contabilidad` → datos contables → `tesoreria` → registrar pago → `autorizacion_pago` → autorizar → `verificacion_pago` → confirmar → `pagada`.
2. **Factura legalización** (`document_type='Legalización'`): flujo reducido hasta `legalizada`.
3. **Refund**: agrupación → contabilidad → tesoreria → autorizacion_pago → verificacion_pago → pagada.
4. **Petty Cash**: agrupación → contabilidad → tesoreria → autorizacion_pago → verificacion_pago → pagada.
5. **Advance legalization**: validacion → contabilidad → tesoreria → autorizacion_pago → verificacion_pago → revision_firmas → legalizada (con `markSigned`/`markExact`).
6. **PaymentScheduling**: borrador → tesoreria → autorizacion_pago → verificacion_pago → pagada.

## Cierre del audit doc

Tras merge, actualizar `docs/audits/permissions-audit-2026-05-11.md`:

- PA-002 ⇒ ✅ Resuelto (commit `<sha>`, fecha).
- PA-005 ⇒ ✅ Resuelto (mismo commit).
- PA-006 ⇒ ✅ Resuelto como efecto colateral.
- PA-010 ⇒ ✅ Resuelto como efecto colateral.

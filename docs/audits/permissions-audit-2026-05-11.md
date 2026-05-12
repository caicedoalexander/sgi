# Auditoría — Sistema de Permisos (RBAC + Pipeline)

**Fecha:** 2026-05-11
**Alcance:** `AuthorizationService`, `PipelineAuthorizationService`, `AppController::beforeFilter`/`_actionToPermission`/`_enforcePermission`, `PipelineStepConstants`, `InvoiceFieldAccessPolicy`, `NoveltyService::*Steps`, `AdvanceLegalizationActionPolicy`, los 8 controllers que declaran `$pipelineActions` y los ~55 call-sites de `pipelineAuth->canOperate(...)`.
**Modo:** Auditoría arquitectónica enfocada en simplificación.
**Nivel:** DEEP (Architecture + DX + Security surface).
**Objetivo del solicitante:** simplificar la validación de permisos para reducir el coste de añadir/editar módulos y bajar la superficie de bugs por configuración silenciosa.
**Verdicto global:** ⚠️ **NEEDS REWORK** — sistema funcional y bien tipado, pero con **3 lugares sin chequeo** para registrar una acción de pipeline (acoplamiento implícito) y un **default `'view'` silencioso** en el mapeo CRUD que puede materializar over-permission al añadir endpoints. Refactor de DX/seguridad recomendado en 10 pasos, ~150 LoC netas menos.

> **Nota:** los planes describen *cómo llegar* a la solución. El código concreto se decide al ejecutar cada plan.

---

## Resumen ejecutivo

| Hallazgo | Severidad | Esfuerzo | Impacto DX |
|---|:--:|:--:|:--:|
| PA-001 — `_actionToPermission` default `'view'` (over-permission silencioso) | 🔴 | XS | Medio |
| PA-002 — 3 lugares sin chequeo para registrar acción de pipeline | 🔴 | M | **Muy alto** |
| PA-003 — `$roleName` muerto en 55 call-sites + 13 firmas de policy | 🟠 | S | Alto |
| PA-004 — Sin abstracción común entre `AuthorizationService` y `PipelineAuthorizationService` | 🟠 | M | Alto |
| PA-005 — `canAdvance`/`canRegress` mezclan "no autorizado" con "estado terminal" | 🟠 | S | Medio |
| PA-006 — `pipelineActions` repetido en cada controller pipeline | 🟠 | S | Medio |
| PA-007 — Admin bypass duplicado (auth + sidebar) | 🟡 | XS | Bajo |
| PA-008 — `FIELDS_BY_STEP` / `SECTIONS_BY_STEP` con shape divergente entre Invoice y Novelty | 🟡 | S | Medio |
| PA-009 — `getPermissionsMatrix(0)` "funciona" por accidente en `RolesController::add` | 🟡 | XS | Bajo |
| PA-010 — `_actionToPermission` lista plana de 35+ acciones en `AppController` | 🟡 | M | Alto |
| PA-011 — Coexisten dos modelos de Policy (Advance con clase dedicada, resto inline) | 🟡 | M | Medio |
| PA-012 — Fallback `?? new PipelineAuthorizationService()` en 6 services | 🟢 | XS | Bajo |
| PA-013 — Cache per-request implícito sin documentar | 🟢 | XS | Bajo |
| PA-014 — `STEP_LABELS` duplica `STATUS_LABELS` de cada `*Constants` | 🟢 | S | Bajo |

---

## Estado de remediación

| ID | Severidad | Hallazgo | Estado | Resuelto en |
|----|-----------|----------|--------|-------------|
| PA-001 | 🔴 Critical | `_actionToPermission` cae a `'view'` por defecto cuando una acción no está mapeada → over-permission silencioso | ✅ Resuelto | commit `0d84bd7` (2026-05-12) |
| PA-002 | 🔴 Critical | 3 lugares sin chequeo para registrar una acción de pipeline (`$pipelineActions`, `_actionToPermission`, llamada manual a `canOperate`) | ✅ Resuelto | commits `882718a..a903f54` (2026-05-12) |
| PA-003 | 🟠 Major | `$roleName` declarado y propagado en 55 call-sites pero nunca consultado (cleanup 2026-05-02 inacabado) | ✅ Resuelto | PR #4 / commit `1c73514` (2026-05-12) |
| PA-004 | 🟠 Major | `AuthorizationService` y `PipelineAuthorizationService` duplican shape (cache, matrix, save, isAllowed/canOperate) sin contrato común | ✅ Resuelto | commits `424249d..3949528` (2026-05-12) |
| PA-005 | 🟠 Major | `canAdvance(...)` y `canRegress(...)` mezclan "no hay next/previous" con "rol sin permiso" en el mismo `bool` | ✅ Resuelto | commits `882718a..e9551aa` (2026-05-12) |
| PA-006 | 🟠 Major | `$pipelineActions` declarado per-controller; añadir una acción nueva exige tocarlo + recordar el patrón | ✅ Resuelto | commit `a903f54` (2026-05-12) |
| PA-007 | 🟡 Minor | `ADMIN_BYPASS_MODULES` ejecuta lógica en `isAllowed` y se re-aplica en `AppController::_setUserPermissions` para el sidebar | ⏳ Pendiente | — |
| PA-008 | 🟡 Minor | `InvoiceFieldAccessPolicy::SECTION_BY_STEP` (string) vs `NoveltyService::SECTIONS_BY_STEP` (array) — divergencia gratuita | ⏳ Pendiente | — |
| PA-009 | 🟡 Minor | `RolesController::add` llama `getPermissionsMatrix(0)` con `role_id` inexistente; funciona porque `?? false` casts a `false` | ✅ Resuelto | commit `d294aaf` (2026-05-12) |
| PA-010 | 🟡 Minor | `_actionToPermission` agrupa 35+ acciones en un `match` plano que vive en `AppController`; cada acción nueva en cualquier controller exige tocar la base | ✅ Resuelto | commit `a903f54` (2026-05-12) |
| PA-011 | 🟡 Minor | `AdvanceLegalizationActionPolicy` modela bien el Policy pattern; Refund/PettyCash/Invoice/Novelty/PaymentScheduling siguen llamando `canOperate` inline → dos estilos coexistiendo | ⏳ Pendiente | — |
| PA-012 | 🟢 Sugerencia | 6 services hacen `$pipelineAuth ?? new PipelineAuthorizationService()`; cada instancia tiene su propia cache si el DI falla | ✅ Resuelto | colateral de PA-004 (verificado 2026-05-12) |
| PA-013 | 🟢 Sugerencia | Cache de `private array $cache` depende del scope per-request del contenedor de DI; no documentado en el docblock | ✅ Resuelto | commit `d9bebf5` (2026-05-12) |
| PA-014 | 🟢 Sugerencia | `PipelineStepConstants::STEP_LABELS` repite strings que ya existen en `STATUS_LABELS` de cada `*Constants`; drift posible | ✅ Resuelto | commit `0c66096` (2026-05-12) |

---

## PA-001 — `_actionToPermission` cae a `'view'` 🔴 ✅ Resuelto (2026-05-12)

> **Cierre:** commit `0d84bd7` mapeó las 7 acciones huérfanas detectadas en el inventario (5 a `'view'`, 1 a `'add'`, 1 a `'edit'`) y reemplazó `default => 'view'` por `LogicException` con mensaje accionable. Precheck en BD confirmó cero impacto runtime: solo `Administrador` tiene fila en `permissions` para `employee_novelties` y `novelty_liquidation_docs` con `can_view = can_create = can_edit = 1`, los demás roles no tienen fila (403 antes y después). Validación manual: throw probado con acción dummy en `InvoicesController` (HTTP 500 + mensaje citando `dummyMissing`), exceptions de `_enforcePermission` (login/logout, `EmailLogs::retry`, Pages, Error) intactas, smoke E2E del pipeline de facturas sin regresiones.



**Ubicación:** `src/Controller/AppController.php:112-121`

```php
return match ($action) {
    'index', 'view', ... => 'view',
    'add', ...           => 'add',
    'edit', 'advanceStatus', ... (30+ acciones) => 'edit',
    'delete', ...        => 'delete',
    default => 'view',   // ← BUG WAITING TO HAPPEN
};
```

**Por qué duele**

Si un dev añade `mergeInvoices()` a `InvoicesController` y olvida registrar la acción en el `match`, cae al default `'view'`. Cualquier rol con `invoices.can_view=true` (Tesorería, Contabilidad, Contador, Registro, Auxiliar de Personal, etc.) puede invocar la fusión destructiva. El test manual en el navegador **pasa con todos los roles probados** porque todos tienen view. El bug solo aparece cuando alguien repara en que un rol que no debía pudo ejecutarla.

**Plan**

Cambiar `default => 'view'` por `default => throw new \LogicException("Action '$action' has no permission mapping. Register it in _actionToPermission or mark it as #[NoAuthGate].")`. Te obliga a registrar cada acción **antes** del primer request. Cero impacto en producción si el catálogo está completo; el dev se entera en su primer click.

**Criterio de validación manual**

1. Añadir temporalmente una acción `dummyMissing()` en cualquier controller con módulo mapeado.
2. Visitar `/{controller}/dummy-missing` con sesión iniciada.
3. Confirmar que el response es **500 con la `LogicException`** (no 200 ni 403).
4. Quitar la acción temporal.

---

## PA-002 — 3 lugares sin chequeo para registrar una acción de pipeline 🔴 ✅ Resuelto (2026-05-12)

> **Cierre:** commits `882718a` (DenialReason + `denialReasonForAdvance/Regress` en 5 services) → `3121ccc` (atributos `#[Permission]`/`#[PipelineAction]`/`#[NoAuthGate]`) → `9cb056c` (`_enforcePermission` lee atributos con fallback legacy) → `c354119` (anotar 22 controllers no-pipeline) → `dcf0710` (anotar 7 controllers de pipeline) → `a903f54` (punto de no retorno: eliminar `$pipelineActions`, `_actionToPermission`, rama legacy). Resultado: ahora cada acción nueva exige **un solo lugar** (`#[Permission(...)]` o `#[PipelineAction(...)]` sobre el método); olvidarlo lanza `LogicException 500` en el primer hit (loud-and-clear). Validación manual: `bin/cake routes` carga sin errores; sintaxis OK en todos los archivos editados.



**Ubicación:** `src/Controller/AppController.php:73,197` + cada controller pipeline (`InvoicesController.php:48-51`, `RefundsController.php:34-45`, `PettyCashRecordsController.php:34-41`, `PaymentSchedulingsController.php:35-40`, `InvoicePaymentsController.php:21-28`, `LiquidationDocPaymentsController.php:21-26`, `AdvancesController.php:33-41`).

**Por qué duele**

Para una acción nueva tipo `markReviewed` en `InvoicesController`, hay que:

1. Añadirla al `$pipelineActions` del controller → si te olvidas, el gate CRUD se aplica y un rol con `can_edit=false` (correcto en CRUD) pero `pipeline_permissions(rol, invoices, X)=true` (correcto en pipeline) **es bloqueado por error**.
2. Añadirla a `_actionToPermission` (o cae a `'view'` por PA-001).
3. Llamar `$this->pipelineAuth->canOperate(...)` dentro del método → si te olvidas, **la acción queda sin gate de paso**: el `$pipelineActions` del paso 1 hizo que `_enforcePermission` se salte el chequeo CRUD, y nadie más valida.

**Combinación tóxica**: si te olvidas del paso 1 *y* del paso 3, sólo te protege el gate CRUD del módulo, que no diferencia por paso del pipeline.

**Plan**

Reemplazar `$pipelineActions` + `_actionToPermission` por atributos sobre las acciones:

```php
#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES)]
public function markReviewed(int $id): Response { ... }

#[Permission(action: 'edit')]
public function edit(int $id): Response { ... }

#[NoAuthGate]  // explícito para login/logout/health-check/etc.
public function login(): Response { ... }
```

Un `AuthorizationMiddleware` (o `AppController::beforeFilter` extendido) lee el atributo de la acción resuelta y:
- `#[Permission]` → aplica el gate CRUD del módulo (deducido por `$controllerModuleMap`).
- `#[PipelineAction]` → salta el gate CRUD; la responsabilidad de llamar `canOperate` queda dentro del método, pero está **explícitamente marcada** y un linter/grep encuentra cuáles acciones lo necesitan.
- Si falta el atributo → `throw` al primer hit.

**Bonus**: la evolución natural es que `#[PipelineAction]` lleve también el step (o que se infiera del nombre del método: `authorizePayment` → `STATUS_AUTORIZACION_PAGO`) y el middleware llame `canOperate` automáticamente, eliminando incluso el paso 3.

**Criterio de validación manual**

1. Crear acción decorada con `#[PipelineAction]` y `pipeline_permissions(rol_X, step) = true` pero rol sin `can_edit` del módulo → la acción debe ser ejecutable.
2. Crear acción sin atributo en un controller con módulo mapeado → 500 al hit.
3. Marcar la acción con `#[NoAuthGate]` → ejecutable sin sesión.
4. Probar que las 8 acciones de pipeline existentes (advanceStatus, regressStatus, authorizePayment, confirmPayment, rejectPayment, registerPayment, markSigned, markExact) siguen comportándose igual.

---

## PA-003 — `$roleName` muerto en 55 call-sites + 13 firmas de policy 🟠 ✅ Resuelto (2026-05-12)

> **Cierre:** PR #4 (commit `1c73514`) eliminó `$roleName` de `PipelineAuthorizationService::canOperate/getOperableSteps` y de la cascada de servicios/policies/controllers que lo propagaban. Verificación manual (`php bin/cake server` + flujos E2E por rol) sin regresiones. `AuthorizationService::isAllowed($roleId, $roleName, ...)` se conserva porque sí consulta `$roleName` para el admin bypass (ver PA-007).


**Ubicación:**
- `src/Service/PipelineAuthorizationService.php:25,30,39,43` (declarado "conservado para compat, no se consulta tras cleanup 2026-05-02").
- `src/Service/InvoicePipelineService.php` (`canAdvance`, `canRegress`).
- `src/Service/InvoiceFieldAccessPolicy.php` (`getEditableFields`, `getVisibleSections`).
- `src/Service/InvoiceTransitionValidator.php` (`filterErrorsForRole`).
- `src/Service/NoveltyService.php` (`getEditableFields`, `getVisibleSections`, `canAdvanceFromStatus`).
- `src/Service/PaymentSchedulingService.php` (`canAdvance`, `canReject`, `canRegress`).
- **13 métodos de `src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php`** (`canLinkInvoices`, `canUnlinkInvoice`, `canUploadRelationDocument`, `canMoveToRevision`, `canMarkSigned`, `canReturnToValidacion`, `canMarkExact`, `canRegisterShortage`, `canRegisterSurplus`, `canConfirmShortage`, `canRegisterRefund`, `canAuthorizeRefundPayment`, `canConfirmRefundPayment`).
- `RefundService::canRegress` (línea 358) ya lo eliminó — inconsistente con el resto.

**Por qué duele**

Cada vez que escribes un caller nuevo, pierdes 30 segundos verificando que `$roleName` es muerto. Es el tipo de ruido que hace que el código se sienta complicado aunque la lógica no lo sea. Además invita al patrón cargo-cult: nuevos callers pasan `$roleName` porque "todos lo hacen", sin saber que el parámetro no se consulta.

**Plan**

Search-and-delete mecánico. Una sola PR. `RefundService::canRegress` ya tiene la firma limpia: úsalo de plantilla. Conserva `AuthorizationService::isAllowed($roleId, $roleName, ...)` porque sí lo consulta para el admin bypass (línea 57).

**Criterio de validación manual**

1. `php bin/cake server` arranca sin errores tras el refactor.
2. Cada controller con pipeline (Invoices, Refunds, PettyCash, Advances, PaymentSchedulings, EmployeeNovelties) → ejecutar un advance/regress y un upload de documento. Comportamiento idéntico.
3. Roles de prueba con permisos parciales siguen viendo/no viendo las mismas secciones de los formularios edit.

---

## PA-004 — Sin abstracción común entre los dos servicios 🟠 ✅ Resuelto (2026-05-12)

> **Cierre:** commits `424249d` (capa contractual: `UserContext` VO + `CrudAction` enum + `AuthorizationFacade` interface + `DefaultAuthorizationFacade` impl + DI registry) → `07c7d05` (método público `invalidate(int $roleId)` en ambos services, reemplaza `unset` inline en `save*`) → `d8be20e` (AppController + 9 services/policies migran al Facade; resolución colateral del fallback `?? new PipelineAuthorizationService()` PA-012 en 5 services) → `3949528` (8 controllers migran sus call-sites inline a `authFacade->canOperate`/`canCrud`; AppController limpia su propiedad heredada `$pipelineAuth`) → este commit (docblocks `@internal` + comentarios delimitando que la dependencia directa solo es legal en `RolesController` y `AppController::_setUserPermissions`). Resultado: una sola fachada inyectable para chequeos; matrices/save quedan como detalle interno consumido solo en los 2 puntos previstos.

> Validación manual: server arranca limpio (`php bin/cake routes` carga sin errores). E2E queda pendiente para validación humana (login multi-rol + flow completo pipeline en facturas/reintegros/caja menor/anticipos/novedades/programación).

**Ubicación:** `src/Service/AuthorizationService.php` y `src/Service/PipelineAuthorizationService.php`.

**Por qué duele**

Ambos:
- guardan `private array $cache = []`,
- exponen un par `getXxxMatrix(roleId)` para alimentar la UI,
- exponen un par `savePermissions(roleId, $data)` con la misma estructura POST,
- exponen un par `isAllowed(...)` / `canOperate(...)`,
- invalidan cache con `unset($this->cache[$roleId])`.

**No comparten interfaz, ni clase base, ni helpers**. Si surge una tercera dimensión (p. ej., permisos por centro de operación), nace un tercer servicio paralelo con el mismo patrón duplicado.

**Plan**

Introducir contrato común:

```php
interface AuthorizationFacade
{
    public function canCrud(UserContext $u, string $module, CrudAction $a): bool;
    public function canOperate(UserContext $u, string $pipeline, string $step): bool;
    public function operableSteps(UserContext $u, string $pipeline): array;
    public function invalidate(int $roleId): void;
}
```

Una sola fachada inyectable que internamente delega a los dos services actuales. Controllers y services dependen del contrato. La duplicación de cache y `*Matrix`/`save*` queda como detalle de implementación, no fuga al resto del código.

**Criterio de validación manual**

1. Refactor sin cambio de comportamiento: ejercitar `roles/edit`, `roles/add`, login/logout, y un flujo end-to-end (crear factura → avanzar → autorizar pago → confirmar) con cada rol.
2. La matriz mostrada en `roles/view/{id}` es idéntica antes y después.

---

## PA-005 — `canAdvance`/`canRegress` mezclan motivos 🟠 ✅ Resuelto (2026-05-12)

> **Cierre:** commits `882718a` (enum `DenialReason` + métodos `denialReasonForAdvance/Regress` en `InvoicePipelineService`/`PettyCashService`/`PaymentSchedulingService`/`RefundService`/`NoveltyService`) → `e9551aa` (migrar callers externos + internos a `denialReasonFor*`) → `<commit_task8>` (eliminar métodos legacy `canAdvance`/`canRegress`/`canAdvanceFromStatus`/`canReject` de los 5 services). Resultado: el caller recibe `null` (puede operar) o un caso enum (`TERMINAL_STATE`/`UNAUTHORIZED`/`REJECTED`/`MISSING_FIELDS`) con `->message()` listo para Flash. Cero ambigüedad. `InvoicePipelineService::denialReasonForAdvance` detecta `REJECTED` en línea con la regla "facturas rechazadas no avanzan ni regresan". `PaymentSchedulingService::canReject` se inlineó en los 2 callers (chequeo trivial: status === STATUS_AUTORIZACION_PAGO && canOperate).



**Ubicación:** `src/Service/InvoicePipelineService.php:121-189` (también `RefundService::canRegress:358`, `NoveltyService::canAdvanceFromStatus:457`, `PaymentSchedulingService::canRegress:84`).

```php
public function canAdvance(int $roleId, ..., string $currentStatus, ?string $documentType = null): bool
{
    if ($this->getNextStatus($currentStatus, $documentType) === null) {
        return false;   // ← "no hay next"
    }
    return $this->pipelineAuth->canOperate(...);   // ← "sin permiso"
}
```

**Por qué duele**

El caller no puede distinguir "ya está terminal" de "no tienes permisos". El consumidor (`InvoicePipelineService::advance`, `regress`, los ViewModels) tiene que recalcular `getNextStatus`/`getPreviousStatus` para componer mensajes correctos. `InvoicePipelineService::regress()` ya hace la doble llamada (línea 354-357) para distinguir los dos casos. Es código que se va repitiendo por cada acción nueva.

**Plan**

Reemplazar `bool` por motivo opcional:

```php
public function denialReasonForAdvance(UserContext $u, Invoice $i): ?DenialReason;
// enum DenialReason { TerminalState; Unauthorized; Rejected; case MissingFields(array $fields); }
```

`null` = puede avanzar. UI/templates muestran mensaje específico sin recalcular. Empuja la lógica de "qué falta para avanzar" hacia un único punto y elimina el patrón de `validateTransitionRequirements` + `canAdvance` llamados por separado.

**Criterio de validación manual**

1. En un invoice ya en `pagada`, el botón de avanzar muestra "Esta factura ya está en el estado final." (no "No tienes permisos").
2. En un invoice en `aprobacion` con rol sin permiso de pipeline para `aprobacion`, el botón muestra "No tiene permisos para avanzar esta factura." (no "Estado final").
3. En un invoice rechazado, ambos casos anteriores muestran el motivo de rechazo, no el genérico de permisos.

---

## PA-006 — `pipelineActions` repetido en cada controller 🟠 ✅ Resuelto (2026-05-12)

> **Cierre:** efecto colateral del refactor PA-002. El commit `a903f54` eliminó la propiedad `protected array $pipelineActions` de los 7 controllers de pipeline (`InvoicesController`, `InvoicePaymentsController`, `LiquidationDocPaymentsController`, `PettyCashRecordsController`, `RefundsController`, `PaymentSchedulingsController`, `AdvancesController`) y la declaración base `$pipelineActions = []` en `AppController`. Sustituidos por `#[PipelineAction(pipeline: ..., step: ...)]` directamente sobre cada método.



**Ubicación:** 8 controllers (ver PA-002).

**Por qué duele**

Las listas son razonablemente cortas pero el conocimiento "esto se gatea por pipeline_permissions, no por CRUD" se replica sin checkup central. Si añades un payment-controller nuevo, hay que recordar el patrón completo.

**Plan**

Queda resuelto automáticamente al implementar PA-002. Cada método se declara a sí mismo con el atributo. `AppController::pipelineActions` desaparece. Cero acoplamiento entre controllers para mantener la convención.

**Criterio de validación manual**

Cubierto por el plan de validación de PA-002.

---

## PA-007 — Admin bypass duplicado 🟡

**Ubicación:** `src/Service/AuthorizationService.php:19,57` (constante + uso en `isAllowed`) y `src/Controller/AppController.php:152-161` (merge manual en `_setUserPermissions`).

**Por qué duele**

`ADMIN_BYPASS_MODULES = ['users', 'roles']` se consulta en `isAllowed()` para el gate runtime **y** en `_setUserPermissions()` para "rellenar" el matrix de UI con `true`. Es la misma información expresada dos veces. La razón es que la BD no tiene filas de `permissions(admin, users, ...)`, así que la matriz devuelta desde `getPermissionsForRoleAsMatrix` los muestra como `false` para el sidebar, lo que rompería la UX del admin.

**Plan**

Migration seeder que inserta `(admin_role_id, 'users', true, true, true, true)` y `(admin_role_id, 'roles', ...)`. Elimina `ADMIN_BYPASS_MODULES`, elimina el bloque `if ($roleName === ROLE_ADMIN)` en `_setUserPermissions`, simplifica `isAllowed` a una rama. El admin pasa por el mismo flujo que cualquier otro rol — más consistente, menos código.

**Criterio de validación manual**

1. Migrate, login como admin → sidebar muestra los módulos "Usuarios" y "Roles" igual que antes.
2. Acceder a `/users` y `/roles` con admin → permitido.
3. Acceder con cualquier otro rol → bloqueado igual que antes (porque la fila correspondiente sigue con `can_view=false`).

---

## PA-008 — Shape divergente de `FIELDS_BY_STEP` / `SECTIONS_BY_STEP` 🟡

**Ubicación:**
- `src/Service/InvoiceFieldAccessPolicy.php:45-53` → `STATUS_X => 'one_section'` (string).
- `src/Service/NoveltyService.php:28-36` → `STATUS_X => ['s1', 's2', ...]` (array).

**Por qué duele**

Mismo concepto, dos formas. Si quieres entender ambos a la vez tienes que mantener dos modelos mentales. Invoice podría trivialmente usar arrays de un solo elemento.

**Plan**

1. Unificar a array (Invoice → wrap en `[...]`).
2. Extraer base abstracta:

```php
abstract class PipelineFieldPolicy {
    abstract protected static function fieldsByStep(): array;
    abstract protected static function sectionsByStep(): array;  // STATUS_X => string[]
    abstract protected static function pipelineKey(): string;

    final public function getEditableFields(UserContext $u, string $step): array { ... }
    final public function getVisibleSections(UserContext $u, string $step): array { ... }
    final public function filterEntityData(array $data, UserContext $u, string $step): array { ... }
}
```

`InvoiceFieldAccessPolicy` (150 LoC) y la sección equivalente de `NoveltyService` (~80 LoC) se reducirían a ~30 LoC de subclase + la base reusable.

**Criterio de validación manual**

1. Cada rol ve las mismas secciones que antes en `invoices/edit` y `employee-novelties/edit`.
2. Los campos editables son idénticos en cada combinación rol×estado.

---

## PA-009 — `getPermissionsMatrix(0)` "funciona" por accidente 🟡 ✅ Resuelto (2026-05-12)

> **Cierre:** commit `d294aaf` añadió `PipelineAuthorizationService::getEmptyMatrix()` que itera `STEPS_BY_PIPELINE` sin tocar BD. `RolesController::add` ahora usa el método explícito en lugar de `getPermissionsMatrix(0)`. No se añadió simétrico `getEmptyPermissionsMatrix()` en `AuthorizationService` porque el template `templates/Roles/add.php` no consume `$permissionsMatrix` (solo itera `$modules`), por lo que sería abstracción especulativa (YAGNI). Validación: render del formulario `/roles/add` idéntico al previo (todos los checkboxes desmarcados); creación de rol y edición posterior sin regresión.

**Ubicación:** `src/Controller/RolesController.php:74`.

```php
$pipelineMatrix = $this->pipelineAuth->getPermissionsMatrix(0);
```

`role_id = 0` no existe. El método itera `STEPS_BY_PIPELINE` y rellena con `?? false`. Hace lo correcto porque `null` casteado a `bool` da `false`, no porque sea un caso documentado.

**Plan**

Añadir método explícito `PipelineAuthorizationService::getEmptyMatrix(): array` que reuse la estructura sin acceder a BD. Usar en `RolesController::add`. Misma robustez, intención clara.

**Criterio de validación manual**

`/roles/add` muestra la matriz con todos los checkboxes desmarcados (igual que antes).

---

## PA-010 — `_actionToPermission` lista plana de 35+ acciones 🟡 ✅ Resuelto (2026-05-12)

> **Cierre:** efecto colateral del refactor PA-002. El commit `a903f54` eliminó el método `_actionToPermission` completo de `AppController`. El mapeo de acción → permiso CRUD ahora se declara por método con `#[Permission(action: 'view'|'add'|'edit'|'delete')]`, distribuyendo la decisión al sitio donde vive la acción (no en una tabla central de 35+ entradas).



**Ubicación:** `src/Controller/AppController.php:112-121` (línea de `'edit'` tiene >30 acciones inline).

**Por qué duele**

- Es la "tabla de conversión" universal del sistema. Cada controller añade entradas aquí en lugar de en su propio archivo.
- `advanceStatus` está marcada como `'edit'` aquí pero también está en `$pipelineActions` que la salta — el mapeo es **dead code** para esas acciones.
- Cuando una acción solo existe en un controller (`linkCandidates` solo en Advances), declararla en una lista global es ruido para los demás controllers.

**Plan**

Queda resuelto con el atributo `#[Permission(action: 'add'|'edit'|'delete'|'view')]` de PA-002. Cada método declara su acción semántica. La lista central desaparece.

**Criterio de validación manual**

Cubierto por el plan de validación de PA-002.

---

## PA-011 — Dos modelos de Policy coexisten 🟡

**Ubicación:**
- **Modelo A** (preferido implícito): `src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php` — objeto policy con `canX(entity, roleId, roleName)` que compone `pipelineAuth->canOperate(...)` con `entity->canX()`.
- **Modelo B** (usado mayoritariamente): controllers/services llaman `pipelineAuth->canOperate(...)` directamente (a veces vía wrapper privado tipo `RefundsController::_canOperateRefundStep:74`, a veces inline como en `InvoicesController::edit`).

**Por qué duele**

La existencia del Modelo A indica que alguien intentó introducir la abstracción correcta, pero solo se aplicó a Advance. Refund, PettyCash, Invoice, Novelty, PaymentScheduling siguen en Modelo B. Decidir uno y migrar el resto elimina la inconsistencia y reduce la carga cognitiva al onboarding.

**Plan**

Migrar todo al Modelo A (Policy objects per dominio). Razones:
- Centraliza la combinación "puede operar el paso + el estado del agregado lo permite" en un solo objeto.
- Se inyecta fácilmente en ViewModels (`canX` para gating de botones).
- Reduce el ruido de `_canOperateRefundStep` y similares que aparecen ad hoc.

Crear `RefundActionPolicy`, `PettyCashActionPolicy`, `InvoiceActionPolicy`, `NoveltyActionPolicy`, `PaymentSchedulingActionPolicy` con el mismo shape que `AdvanceLegalizationActionPolicy`.

**Criterio de validación manual**

1. Cada acción cubierta por las policies nuevas se comporta igual que antes para cada rol.
2. Los ViewModels que hoy reciben `canX` calculado inline pasan a recibirlo desde la policy.

---

## PA-012 — Fallback `?? new PipelineAuthorizationService()` 🟢 ✅ Resuelto (2026-05-12)

> **Cierre:** verificación negativa tras PA-004. Tres búsquedas (`?? new (Pipeline)?AuthorizationService(\|Facade)`, parámetros con default `= null` tipados `?(Pipeline)?AuthorizationService(\|Facade)`, y firmas nullable `?{Service}`) no encontraron coincidencias en `src/`. La migración a `AuthorizationFacade` (commits `424249d..3949528`) ya había eliminado los 6 fallbacks listados en el inventario original. Sin cambios de código en PR1.

**Ubicación:** `RefundService:45`, `NoveltyService:49`, `PaymentSchedulingService:31`, `PettyCashService:53`, `RefundPaymentService:36`, `InvoiceFieldAccessPolicy:62`.

**Por qué duele**

Si dos services se instancian sin DI (tests futuros, scripts CLI ad hoc), cada uno tendrá su propia cache y consultarán BD por separado. En producción es siempre vía DI, pero el fallback existe y puede activar bugs raros.

**Plan**

Si se implementa PA-004 (`AuthorizationFacade`), eliminar los fallbacks y hacer el constructor required. Aprovechar la PR del Facade.

**Criterio de validación manual**

`php bin/cake server` arranca; los flujos E2E funcionan. (El cambio es estructural, no runtime.)

---

## PA-013 — Cache per-request implícito sin documentar 🟢 ✅ Resuelto (2026-05-12)

> **Cierre:** commit `d9bebf5` añadió docblock explícito en `AuthorizationService` y `PipelineAuthorizationService` describiendo la política de caché: invalidación vía `invalidate(int $roleId)` y tras `save*Permissions()`, no persiste entre requests por scope del container de DI, y warning explícito contra promover a caché global sin invalidación cross-request.

**Ubicación:** `PipelineAuthorizationService:21` y `AuthorizationService:52`.

**Por qué duele**

Cada service tiene su propio `private array $cache`. CakePHP 5 con DI usa un container por request (la instancia se garbage-collecta al cerrar la respuesta), así que esto no es bug — pero es un detalle implícito. El reflejo de añadir cache global más adelante sería natural y peligroso.

**Plan**

Docblock explícito en la clase: "Caché per-request, invalidada en `savePermissions`. No persiste entre requests por diseño." Evita el reflejo de promover a cache global.

**Criterio de validación manual**

Cambio puramente documental.

---

## PA-014 — `STEP_LABELS` duplica `STATUS_LABELS` 🟢 ✅ Resuelto (2026-05-12)

> **Cierre:** commit `0c66096` migró `PipelineStepConstants::STEP_LABELS` a referenciar `STATUS_LABELS` de cada `*Constants` donde el label coincide exactamente. Se aprovecha PHP 8.4 (array element access en class constants). De los 38 pares (pipeline, step), 36 quedan delegando y 2 conservan literal por divergencia intencional documentada inline: `STATUS_REVISION_FIRMAS` en pipelines `novelties` y `liquidation_docs` (NoveltyConstants usa `'Revisión y Firmas de documentos'` pero la UI de matriz requiere el label corto `'Revisión y Firmas'`). Drift entre fuentes eliminado para los matches. Validación runtime: las 38 entradas resuelven exactamente igual a antes del refactor.

**Ubicación:** `src/Constants/PipelineStepConstants.php:98-152`.

**Por qué duele**

Cada `*Constants` (InvoiceConstants, NoveltyConstants, etc.) suele exponer su propio `STATUS_LABELS`. `PipelineStepConstants::STEP_LABELS` repite las etiquetas en español. Si una etiqueta cambia, hay dos sitios donde modificar.

**Plan**

En `STEP_LABELS`, referenciar las constantes del dominio:

```php
self::PIPELINE_INVOICES => [
    InvoiceConstants::STATUS_APROBACION => InvoiceConstants::STATUS_LABELS[InvoiceConstants::STATUS_APROBACION] ?? '',
    ...
],
```

O eliminar `STEP_LABELS` y resolver en runtime con `static labelFor(string $pipeline, string $step): string`.

**Criterio de validación manual**

La UI de `roles/edit` muestra las mismas etiquetas en español que antes.

---

## Plan de migración sugerido

Orden recomendado, una PR por paso:

| Paso | Cambio | LoC aprox | Riesgo |
|---|---|---:|---|
| 1 | `default => throw` en `_actionToPermission` (PA-001) + cubrir cualquier acción huérfana que aparezca | +5 | Bajo |
| 2 | Eliminar `$roleName` de `PipelineAuthorizationService` + cascada (PA-003) | -70 / +0 | Bajo (mecánico) |
| 3 | Crear `UserContext` value object + helper `AppController::_userContext()` | +25 | Bajo |
| 4 | Crear `AuthorizationFacade` interface + delegación a los 2 services (PA-004) | +80 | Medio |
| 5 | Migrar controllers Refund/PettyCash/Invoice/Novelty/PaymentScheduling al Modelo A (PA-011) | ±0 (reorg) | Medio |
| 6 | Reemplazar `_actionToPermission` + `$pipelineActions` por atributos (PA-002, PA-006, PA-010) | -50 / +120 | Medio-alto |
| 7 | `denialReasonForAdvance` enum, deprecar `canAdvance` (PA-005) | +60 / -20 | Bajo |
| 8 | Seeder admin + remover `ADMIN_BYPASS_MODULES` (PA-007) | -15 | Bajo |
| 9 | `PipelineFieldPolicy` base + reescribir Invoice/Novelty (PA-008) | -100 / +60 | Medio |
| 10 | Limpiezas PA-009, PA-012, PA-013, PA-014 | -20 | Bajo |

**Saldo neto:** ~150 LoC menos, una abstracción extra (`AuthorizationFacade`/`UserContext`), tres lugares de "olvido" reducidos a uno (atributo del método) con fallo loud-and-clear al primer hit.

---

## Antes vs. después — añadir un módulo de pipeline

**Hoy (en orden, sin chequeo automático de completitud):**

1. Añadir a `controllerModuleMap`.
2. Añadir las 4-5 acciones a `_actionToPermission`.
3. Añadir las 2-6 acciones de pipeline a `$pipelineActions`.
4. Añadir el módulo a `AuthorizationService::MODULES`.
5. Sembrar `permissions` para cada rol.
6. Declarar `STEPS_BY_PIPELINE`.
7. Declarar `STEP_LABELS`.
8. Sembrar `pipeline_permissions` para cada rol.
9. Llamar `canOperate(...)` en cada método pipeline (riesgo de olvidar).
10. Declarar `FIELDS_BY_STEP` y `SECTIONS_BY_STEP` (shape divergente).

**Después del refactor:**

1. Anotar cada método con `#[Permission]` o `#[PipelineAction]` (o `#[NoAuthGate]`).
2. Declarar `STEPS_BY_PIPELINE` (única fuente para UI + validación).
3. Subclasear `PipelineFieldPolicy` (shape único).
4. Sembrar BD (CRUD + pipeline).

Las acciones que falten **fallan en boot**, no en producción.

---

## Observación final

El sistema actual no es incorrecto — funciona, está parcialmente documentado, y los docblocks de cleanup (2026-05-02) indican esfuerzo de simplificación previo. Pero está en un punto medio: la primera ola de refactor extrajo bien los conceptos (`PipelineAuthorizationService`, `*ActionPolicy`, `PipelineStepConstants`), y luego se detuvo antes de eliminar las duplicaciones que justificaban la extracción.

El **mayor enemigo de simplificar la implementación de módulos nuevos** no es la complejidad de la lógica, sino el **número de archivos que un dev debe tocar sin que el compilador le avise**. Hoy son ~7-10. Con PA-001 + PA-002 + PA-003 + el atributo `#[PipelineAction]` con step inferido, bajan a **2-3** con fallo loud-and-clear al primer hit.

Prioridad sugerida: **PA-001, PA-002, PA-003** en ese orden. Son los que más mueven la aguja con el menor riesgo de regresión.

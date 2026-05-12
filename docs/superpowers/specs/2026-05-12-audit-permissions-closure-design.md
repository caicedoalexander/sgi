# Spec — Cierre auditoría `permissions-audit-2026-05-11`

**Fecha:** 2026-05-12
**Audit fuente:** [`docs/audits/permissions-audit-2026-05-11.md`](../../audits/permissions-audit-2026-05-11.md)
**Estado audit al inicio:** 11/14 ✅ resueltos · 3 ⏳ pendientes (PA-007, PA-008, PA-011)
**Objetivo:** cerrar los 3 pendientes en 3 PRs independientes para llevar el audit a un estado terminal (13 ✅ / 1 🟢 WONTFIX / 0 ⏳).

---

## Contexto y decisiones tomadas durante brainstorming

| Decisión | Elección | Razón |
|---|---|---|
| Objetivo | Cerrar los 3 pendientes en orden | Audit completo, sin deuda residual cruda. |
| Agrupamiento | 1 PR por hallazgo (3 PRs) | Coherente con la cadencia previa del audit. Revertir uno no afecta los otros. |
| PA-007 enfoque | **WONTFIX** (solo doc) | Bypass acotado a 2 sitios y 2 módulos. Migrar a seeder introduce 2 filas permanentes en BD para resolver duplicación menor — costo/beneficio negativo. |
| PA-008 alcance | **Plan ampliado a 4 dominios** | Hallazgo en exploración: audit subestimaba consumidores. Realidad: Invoice y Novelty tienen el patrón explícito; PettyCash (`_filterEditPatch`) y Refund (inline en controller) tienen la misma necesidad implementada de forma distinta. Extraer `PipelineFieldPolicy` base + 4 subclases unifica los 4 dominios. |
| PA-011 alcance | **Plan completo** (5 policies nuevas) | Hoy solo Advance usa el patrón Policy dedicado; los demás llaman `canOperate` inline o vía wrapper privado. Modelo único reduce carga cognitiva. |
| Orden de PRs | PR1 → PR2 → PR3 | PR1 cierra discusión. PR2 prepara terreno visual (Policy uniforme por dominio). PR3 cierra con action policies sobre el mismo terreno. |

---

## PR 1 — PA-007 WONTFIX (solo documentación)

### Alcance

Un único commit que edita `docs/audits/permissions-audit-2026-05-11.md`.

### Cambios

**Tabla "Estado de remediación" (líneas ~36-53):**
- Fila PA-007: `⏳ Pendiente` → `🟢 WONTFIX (2026-05-12)`.

**Sección "## PA-007 — Admin bypass duplicado" (líneas ~276-293):**
- Cambiar título: `## PA-007 — Admin bypass duplicado 🟡` → `## PA-007 — Admin bypass duplicado 🟡 🟢 WONTFIX (2026-05-12)`.
- Insertar bloque `> **Cierre:**` justo antes de "**Ubicación:**":

> **Cierre:** marcado como WONTFIX. Bypass acotado a 2 sitios (`AuthorizationService::isAllowed:72` + `AppController::_setUserPermissions:139`) y 2 módulos (`users`, `roles`). Migrar a seeder introduce 2 filas permanentes en BD (`admin_role × users`, `admin_role × roles`) para resolver una duplicación menor que está bien delimitada. Criterio de reapertura: si surge un 3er módulo que requiera `ADMIN_BYPASS_MODULES`, migrar a seeder y eliminar el bypass por código.

**Resumen ejecutivo (líneas ~14-32):**
- Sin cambios en la tabla (PA-007 se queda como hallazgo válido).

**Verdicto global (línea 8):**
- Mantener `⚠️ NEEDS REWORK` durante PR1 (los otros 2 pendientes siguen abiertos). Pasar a `✅ RESUELTO` al cerrar PR3.

### Criterio de validación manual

1. Render del markdown en GitHub/IDE muestra el bloque `> **Cierre:**` con la justificación.
2. La tabla "Estado de remediación" muestra el ícono 🟢 WONTFIX para PA-007.

### Commit message sugerido

`docs(auth): cerrar PA-007 como WONTFIX (admin bypass duplicado)`

---

## PR 2 — PA-008 ampliado: `PipelineFieldPolicy` + 4 subclases

### Hallazgo exploratorio

La auditoría declaraba "2 consumidores" (Invoice, Novelty). En la exploración previa al spec se confirmó que **4 dominios tienen la misma necesidad** de "campos editables por estado del pipeline" pero implementada de forma incoherente:

| Módulo | Implementación actual | Ubicación |
|---|---|---|
| Invoice | Patrón explícito | `src/Service/InvoiceFieldAccessPolicy.php` (clase dedicada, `SECTION_BY_STEP` shape singular) |
| Novelty | Patrón explícito | `src/Service/NoveltyService.php` líneas 22-39 (`SECTIONS_BY_STEP` shape plural) |
| PettyCash | Patrón **implícito** | `src/Service/PettyCashService.php::_filterEditPatch` líneas 120-154 (chequeos `if ($record->isAgrupacion()) {}`) |
| Refund | Patrón **implícito** | `src/Controller/RefundsController.php::edit` líneas 332-362 (chequeos `if ($record->isAgrupacion()) {}` en controller) |

(PaymentScheduling y Advance no aplican: el primero tiene edición trivial de metadata; el segundo no patchea por estado.)

### Alcance

Extraer un contrato común `PipelineFieldPolicy` y migrar los 4 dominios.

### Archivos nuevos

**`src/Service/Pipeline/PipelineFieldPolicy.php`** — clase abstracta:

```php
namespace App\Service\Pipeline;

abstract class PipelineFieldPolicy
{
    public function __construct(
        protected readonly AuthorizationFacade $auth,
    ) {}

    /** @return array<string, string[]> step => editable field names */
    abstract protected static function fieldsByStep(): array;

    /** @return array<string, string[]> step => visible section keys */
    abstract protected static function sectionsByStep(): array;

    /** @return string PipelineStepConstants::PIPELINE_* */
    abstract protected static function pipelineKey(): string;

    /** Secciones siempre visibles independientemente del rol/estado. */
    protected static function alwaysVisibleSections(): array { return []; }

    final public function getEditableFields(UserContext $u, string $step): array;
    final public function getVisibleSections(UserContext $u, string $step): array;
    final public function filterEntityData(array $data, UserContext $u, string $step): FilterResult;
}
```

**`src/Service/Pipeline/FilterResult.php`** — DTO inmutable:

```php
final class FilterResult
{
    public function __construct(
        public readonly array $patch,
        public readonly array $errors,
    ) {}

    public function hasErrors(): bool { return $this->errors !== []; }
}
```

**`src/Service/Pipeline/Novelty/Policy/NoveltyFieldAccessPolicy.php`** — extends `PipelineFieldPolicy`. Copia `FIELDS_BY_STEP` y `SECTIONS_BY_STEP` desde `NoveltyService` (líneas 22-39). `pipelineKey()` retorna `PipelineStepConstants::PIPELINE_NOVELTIES` (verificar nombre exacto).

**`src/Service/Pipeline/PettyCash/Policy/PettyCashFieldAccessPolicy.php`** — extends `PipelineFieldPolicy`:

```php
private const FIELDS_BY_STEP = [
    PettyCashConstants::STATUS_AGRUPACION => ['notes'],
    PettyCashConstants::STATUS_CONTABILIDAD => ['notes', 'accrued', 'accrual_date', 'ready_for_payment'],
];
// Section keys deben coincidir con las usadas en templates/PettyCashRecords/edit.php
// (notes, invoices, accounting, treasury). Mapeo del estado del pipeline a las
// secciones que la template debe renderizar como editables.
private const SECTIONS_BY_STEP = [
    PettyCashConstants::STATUS_AGRUPACION => ['notes', 'invoices'],
    PettyCashConstants::STATUS_CONTABILIDAD => ['notes', 'accounting'],
];
```

Override de `filterEntityData()` para preservar la validación inline existente: `accrual_date` requerida cuando `accrued === true` → `FilterResult` con error.

**`src/Service/Pipeline/Refund/Policy/RefundFieldAccessPolicy.php`** — análogo a PettyCash. Section keys reales del template `templates/Refunds/edit.php`: `beneficiary`, `invoices`, `accounting`, `treasury`. Mapea las dos ramas del controller (`isAgrupacion` con beneficiary fields, `isContabilidad` con accounting fields).

### Archivos modificados

**`src/Service/InvoiceFieldAccessPolicy.php`** — extiende `PipelineFieldPolicy`:
- Cambia `private const SECTION_BY_STEP` (string por step) a `private const SECTIONS_BY_STEP` (array de un elemento por step).
- Elimina los métodos `getEditableFields`, `getVisibleSections`, `filterEntityData` (heredados de la base).
- Conserva `getCollapsibleSections` (no es parte del contrato base).
- Decisión movimiento de archivo (mantener en `src/Service/` vs mover a `src/Service/Pipeline/Invoice/Policy/`): mantener ubicación actual para minimizar diff. Resolver en writing-plans.

**`src/Service/NoveltyService.php`** — inyecta `NoveltyFieldAccessPolicy` por constructor:
- Métodos `getEditableFields(int $roleId, string $status)` y `getVisibleSections(int $roleId, string $status)` (líneas ~430-450) se mantienen como wrappers que delegan en la policy (preserva API pública para los callers actuales).
- `filterEntityData` análogo si lo expone.
- Constantes `FIELDS_BY_STEP` y `SECTIONS_BY_STEP` se eliminan del service (viven solo en la policy).

**`src/Service/PettyCashService.php`** — inyecta `PettyCashFieldAccessPolicy`:
- `_filterEditPatch(PettyCashRecord $record, array $data): array` (líneas 120-154) se elimina.
- `saveAndAdvance()` llama `$this->fieldPolicy->filterEntityData($data, $userContext, $record->status)` y revisa `hasErrors()` antes de patchear.

**`src/Controller/RefundsController.php`** — inyecta `RefundFieldAccessPolicy`:
- `edit()` líneas 332-362 colapsan a:
  ```php
  $result = $this->refundFieldPolicy->filterEntityData($data, $this->_userContext(), $record->status);
  if ($result->hasErrors()) {
      foreach ($result->errors as $err) { $this->Flash->error($err); }
      return $this->redirect(['action' => 'edit', $id]);
  }
  $patchData = $result->patch;
  ```

### Cableado de DI

CakePHP 5 con `League\Container` **no usa autowiring**. Cada servicio se registra explícitamente en `src/Application.php::services()` con `$container->addShared(Clase::class)->addArgument(Dependencia::class)`.

Cambios en `src/Application.php::services()`:

```php
$container->addShared(PipelineFieldPolicy::class); // no, es abstracta — saltar
$container->addShared(NoveltyFieldAccessPolicy::class)
    ->addArgument(AuthorizationFacade::class);
$container->addShared(PettyCashFieldAccessPolicy::class)
    ->addArgument(AuthorizationFacade::class);
$container->addShared(RefundFieldAccessPolicy::class)
    ->addArgument(AuthorizationFacade::class);
// InvoiceFieldAccessPolicy ya está registrado; verificar que su firma sigue OK
// tras heredar de PipelineFieldPolicy.
```

Inyectar las nuevas policies en los services/controllers que las consumen:
- `NoveltyService` → añadir argumento `NoveltyFieldAccessPolicy`
- `PettyCashService` → añadir argumento `PettyCashFieldAccessPolicy`
- `RefundsController` → añadir argumento `RefundFieldAccessPolicy` vía constructor

### Realidad de los templates `edit.php` (decisión de alcance)

Los templates de los 4 dominios consumen "secciones visibles" de forma **no uniforme**:

| Módulo | Patrón en template | Consume |
|---|---|---|
| Invoice | array de keys | `$visibleSections` (array de strings) — itera con `in_array($key, $visibleSections)` |
| Novelty | array de keys | similar a Invoice |
| PettyCash | flags booleanos | `$showAccounting`, `$canEditAccounting`, `$showTreasury`, `$canEditTreasury` calculados en ViewModel |
| Refund | flags booleanos | `$showAccounting`, `$canEditAccounting`, `$showTreasury`, `$canEditTreasury` calculados en ViewModel |

**Decisión:** PR2 unifica `getEditableFields()` y `filterEntityData()` (donde la duplicación es dolorosa). `getVisibleSections()` queda en el contrato base de `PipelineFieldPolicy` y es consumida directamente por Invoice/Novelty. Para PettyCash/Refund, el ViewModel sigue computando los flags booleanos como hoy, pero **delegando en `policy->getVisibleSections()` internamente** (en lugar de calcular `isAgrupacion()`/`isContabilidad()` manualmente). Esto preserva el contrato de la template y unifica la lógica.

Ejemplo en `PettyCashRecordsController::_buildEditViewModel`:
```php
$visibleSections = $this->fieldPolicy->getVisibleSections($userContext, $record->status);
$showAccounting = in_array('accounting', $visibleSections, true);
$showTreasury = in_array('treasury', $visibleSections, true);
```

Refactorizar las templates para consumir el array de keys directamente (eliminando los flags) **queda fuera del alcance** de esta auditoría y se documentará como deuda pendiente si se decide hacerlo después.

### LoC neto estimado

`+220` (base 80 + 3 subclases nuevas × ~45 LoC) `-80` (filterEditPatch eliminado, inline de RefundsController eliminado, lógica duplicada de Invoice/Novelty) ≈ **+140 LoC** con 4 dominios uniformes.

### Criterio de validación manual

1. `php bin/cake server` arranca sin errores tras el refactor.
2. Por cada rol con permisos parciales (Contabilidad, Tesorería, Registro/Revisión, Contador, Auxiliar de Personal), ejercitar el form `edit` de los 4 dominios en cada paso del pipeline que vea:
   - `/invoices/edit/{id}` — campos editables y secciones visibles **idénticas** antes/después.
   - `/employee-novelties/edit/{id}` — idem.
   - `/petty-cash-records/edit/{id}` — idem. Validar que el error "La fecha de causación es requerida cuando el registro está marcado como causado." sigue apareciendo cuando `accrued=true` y `accrual_date` vacía.
   - `/refunds/edit/{id}` — idem. Validar la rama beneficiary en `agrupacion` y la rama accounting en `contabilidad`.
3. Smoke test admin: todas las pantallas anteriores se ven igual.

### Commit message sugerido

`refactor(auth): PA-008 — PipelineFieldPolicy base + 4 subclases (Invoice, Novelty, PettyCash, Refund)`

---

## PR 3 — PA-011: 5 ActionPolicies nuevas

### Alcance

Migrar los 5 dominios que hoy llaman `authFacade->canOperate(...)` inline al patrón Policy dedicado, siguiendo el shape ya establecido en `src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php`.

### Archivos nuevos

- `src/Service/Pipeline/Invoice/Policy/InvoiceActionPolicy.php`
- `src/Service/Pipeline/Refund/Policy/RefundActionPolicy.php`
- `src/Service/Pipeline/PettyCash/Policy/PettyCashActionPolicy.php`
- `src/Service/Pipeline/Novelty/Policy/NoveltyActionPolicy.php`
- `src/Service/Pipeline/PaymentScheduling/Policy/PaymentSchedulingActionPolicy.php`

Cada policy expone métodos `canX(Entity $e, UserContext $u): bool` que componen:

```php
public function canAdvance(Invoice $i, UserContext $u): bool
{
    if ($i->isRejected()) return false;
    return $this->auth->canOperate($u, PipelineStepConstants::PIPELINE_INVOICES, $i->pipeline_status);
}
```

Inventario de métodos por policy a definir en writing-plans (mapeo 1:1 con los `canOperate` inline actuales + condiciones del agregado).

### Archivos modificados

- `src/Controller/InvoicesController.php` — los `authFacade->canOperate(...)` inline de `edit`, `advance`, `regress`, `register-payment`, etc. migran a `$this->invoicePolicy->canX(...)`.
- `src/Controller/RefundsController.php` — `_canOperateRefundStep()` (línea ~74) se elimina; sus 2 callers usan `$this->refundPolicy->canX($record, $userContext)`.
- `src/Controller/PettyCashRecordsController.php` — idem.
- `src/Controller/EmployeeNoveltiesController.php` — idem.
- `src/Controller/PaymentSchedulingsController.php` — idem.
- View-models que hoy reciben flags `canX` calculados inline en el controller pasan a recibirlos desde la policy.

### Criterio de validación manual

1. Por cada controller, ejecutar las 3-5 acciones de pipeline (advance, regress, register-payment, authorize-payment, etc.) con un rol con permiso y un rol sin permiso.
2. Comportamiento idéntico antes/después en cada caso: mismo redirect, mismo flash, mismos botones visibles/deshabilitados en el view.
3. Verificar que `_canOperateRefundStep` y otros wrappers privados desaparecen sin que aparezca un `canOperate` huérfano (`grep "canOperate" src/Controller/` debe mostrar 0 matches tras el refactor).

### Commit message sugerido

`refactor(auth): PA-011 — 5 ActionPolicies para Invoice/Refund/PettyCash/Novelty/PaymentScheduling`

### Audit doc post-merge

Tras mergear PR3:
- PA-011: `⏳ Pendiente` → `✅ Resuelto` con `> **Cierre:**` referenciando los 5 archivos nuevos.
- Verdicto global (línea 8): `⚠️ NEEDS REWORK` → `✅ RESUELTO`.

---

## Cierre

Tras PR1 + PR2 + PR3 el audit `permissions-audit-2026-05-11.md` queda con:

- **13** hallazgos ✅ resueltos
- **1** hallazgo 🟢 WONTFIX (PA-007) con criterio explícito de reapertura
- **0** hallazgos pendientes

Sin dependencias entre PRs; pueden mergearse en cualquier orden, aunque el orden sugerido (1 → 2 → 3) minimiza confusión sobre qué falta por cerrar.

## Política de testing

Este proyecto **no usa tests automatizados** (ver `CLAUDE.md` → "Testing Policy"). Cada PR documenta su `Criterio de validación manual` arriba. No se añaden archivos en `tests/`.

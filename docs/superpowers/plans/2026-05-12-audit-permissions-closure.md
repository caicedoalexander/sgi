# Cierre auditoría permissions-audit-2026-05-11 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cerrar los 3 hallazgos pendientes (PA-007, PA-008, PA-011) del audit `docs/audits/permissions-audit-2026-05-11.md` en 3 PRs independientes, llevando el audit de `11/14 ✅` a `13/14 ✅ + 1 🟢 WONTFIX`.

**Architecture:** PR1 doc-only (WONTFIX). PR2 introduce `PipelineFieldPolicy` abstracta + `FilterResult` DTO y migra 4 dominios (Invoice/Novelty/PettyCash/Refund) al mismo contrato; los templates que consumen flags booleanos siguen funcionando porque el ViewModel los computa vía `policy->getVisibleSections()`. PR3 crea 5 `*ActionPolicy` siguiendo el shape ya establecido por `AdvanceLegalizationActionPolicy`, eliminando los `canOperate` inline en los controllers/view-models.

**Tech Stack:** CakePHP 5.3, PHP 8.4, `League\Container` (DI explícito en `src/Application.php::services()`), `composer cs-check`/`cs-fix` para code style.

**Testing Policy:** Este proyecto **no usa tests automatizados** (ver `CLAUDE.md` → "Testing Policy"). Cada PR documenta su `Criterio de validación manual`. No se añaden archivos en `tests/`.

**Spec fuente:** [`docs/superpowers/specs/2026-05-12-audit-permissions-closure-design.md`](../specs/2026-05-12-audit-permissions-closure-design.md)

---

## File Structure

### Archivos nuevos (PR2)

```
src/Service/Pipeline/
├── FilterResult.php                                       (DTO inmutable: patch + errors)
├── PipelineFieldPolicy.php                                (clase abstracta base)
├── Novelty/Policy/NoveltyFieldAccessPolicy.php            (extends PipelineFieldPolicy)
├── PettyCash/Policy/PettyCashFieldAccessPolicy.php        (extends PipelineFieldPolicy)
└── Refund/Policy/RefundFieldAccessPolicy.php              (extends PipelineFieldPolicy)
```

### Archivos modificados (PR2)

```
src/Service/InvoiceFieldAccessPolicy.php                   (extiende base, SECTION_BY_STEP → SECTIONS_BY_STEP)
src/Service/NoveltyService.php                             (inyecta policy, delega field-access)
src/Service/PettyCashService.php                           (inyecta policy, elimina _filterEditPatch)
src/Controller/RefundsController.php                       (inyecta policy, edit() colapsa los `if isAgrupacion/isContabilidad`)
src/Controller/PettyCashRecordsController.php              (ViewModel computa flags vía policy)
src/Application.php                                        (registrar 3 nuevas policies en services())
```

### Archivos nuevos (PR3)

```
src/Service/Pipeline/
├── Invoice/Policy/InvoiceActionPolicy.php
├── Refund/Policy/RefundActionPolicy.php
├── PettyCash/Policy/PettyCashActionPolicy.php
├── Novelty/Policy/NoveltyActionPolicy.php
└── PaymentScheduling/Policy/PaymentSchedulingActionPolicy.php
```

### Archivos modificados (PR3)

```
src/Controller/InvoicesController.php                      (canX flags vía policy)
src/Controller/RefundsController.php                       (elimina _canOperateRefundStep)
src/Controller/PettyCashRecordsController.php              (canX flags vía policy)
src/Controller/EmployeeNoveltiesController.php             (canX flags vía policy)
src/Controller/PaymentSchedulingsController.php            (canX flags vía policy)
src/Application.php                                        (registrar 5 nuevas policies)
docs/audits/permissions-audit-2026-05-11.md                (PA-011 → ✅ Resuelto, verdicto global → RESUELTO)
```

---

## PR 1 — PA-007 WONTFIX (doc-only)

### Task 1: Marcar PA-007 como WONTFIX en el audit doc

**Files:**
- Modify: `docs/audits/permissions-audit-2026-05-11.md`

- [ ] **Step 1.1: Editar la tabla "Estado de remediación"**

En `docs/audits/permissions-audit-2026-05-11.md`, ubicar la fila de PA-007 en la tabla "Estado de remediación" (alrededor de la línea 45). Reemplazar:

```markdown
| PA-007 | 🟡 Minor | `ADMIN_BYPASS_MODULES` ejecuta lógica en `isAllowed` y se re-aplica en `AppController::_setUserPermissions` para el sidebar | ⏳ Pendiente | — |
```

por:

```markdown
| PA-007 | 🟡 Minor | `ADMIN_BYPASS_MODULES` ejecuta lógica en `isAllowed` y se re-aplica en `AppController::_setUserPermissions` para el sidebar | 🟢 WONTFIX | 2026-05-12 |
```

- [ ] **Step 1.2: Añadir bloque de cierre en la sección PA-007**

Ubicar el encabezado `## PA-007 — Admin bypass duplicado 🟡` (alrededor de la línea 276). Reemplazarlo por:

```markdown
## PA-007 — Admin bypass duplicado 🟡 🟢 WONTFIX (2026-05-12)

> **Cierre:** marcado como WONTFIX. Bypass acotado a 2 sitios (`AuthorizationService::isAllowed:72` + `AppController::_setUserPermissions:139`) y 2 módulos (`users`, `roles`). Migrar a seeder introduce 2 filas permanentes en BD (`admin_role × users`, `admin_role × roles`) para resolver una duplicación menor que está bien delimitada. Criterio de reapertura: si surge un 3er módulo que requiera `ADMIN_BYPASS_MODULES`, migrar a seeder y eliminar el bypass por código.
```

- [ ] **Step 1.3: Verificar render en local**

Abrir el archivo en VSCode (o IDE con preview markdown). Confirmar visualmente:
1. La fila de PA-007 muestra 🟢 WONTFIX y la fecha 2026-05-12.
2. La sección PA-007 muestra el bloque `> **Cierre:**` con la justificación, antes de "**Ubicación:**".

- [ ] **Step 1.4: Commit**

```powershell
git add docs/audits/permissions-audit-2026-05-11.md
git commit -m "docs(audit): cerrar PA-007 como WONTFIX (admin bypass duplicado)"
```

---

## PR 2 — PA-008 ampliado: `PipelineFieldPolicy` + 4 subclases

### Task 2: Crear `FilterResult` DTO

**Files:**
- Create: `src/Service/Pipeline/FilterResult.php`

- [ ] **Step 2.1: Crear el DTO inmutable**

Crear `src/Service/Pipeline/FilterResult.php` con este contenido exacto:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline;

/**
 * Resultado de `PipelineFieldPolicy::filterEntityData()`: contiene los campos
 * editables ya filtrados (`patch`) y los errores de validación inline detectados
 * (`errors`). Es inmutable por diseño.
 */
final class FilterResult
{
    /**
     * @param array<string, mixed> $patch Campos a aplicar vía patchEntity().
     * @param array<int, string> $errors Mensajes de validación inline (bloquean save).
     */
    public function __construct(
        public readonly array $patch,
        public readonly array $errors,
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
```

- [ ] **Step 2.2: Verificar sintaxis**

```powershell
php -l src/Service/Pipeline/FilterResult.php
```

Esperado: `No syntax errors detected in src/Service/Pipeline/FilterResult.php`.

- [ ] **Step 2.3: Commit**

```powershell
git add src/Service/Pipeline/FilterResult.php
git commit -m "feat(auth): FilterResult DTO para PipelineFieldPolicy::filterEntityData (PA-008)"
```

---

### Task 3: Crear `PipelineFieldPolicy` clase abstracta

**Files:**
- Create: `src/Service/Pipeline/PipelineFieldPolicy.php`

- [ ] **Step 3.1: Crear la clase abstracta**

Crear `src/Service/Pipeline/PipelineFieldPolicy.php` con este contenido exacto:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline;

use App\Authorization\AuthorizationFacade;
use App\ValueObject\UserContext;

/**
 * Base abstracta para las políticas de acceso a campos editables y secciones
 * visibles de cada pipeline. Cada subclase declara su mapeo `step → fields` y
 * `step → sections`. La autorización rol×paso se delega a `AuthorizationFacade`.
 *
 * Audit PA-008 — unifica el patrón que antes existía duplicado entre
 * InvoiceFieldAccessPolicy (clase dedicada), NoveltyService (constantes en
 * service), PettyCashService::_filterEditPatch (lógica inline) y
 * RefundsController::edit (lógica inline en controller).
 */
abstract class PipelineFieldPolicy
{
    public function __construct(
        protected readonly AuthorizationFacade $auth,
    ) {
    }

    /**
     * Campos editables por paso del pipeline (sin acoplamiento a rol).
     *
     * @return array<string, array<int, string>> step => editable field names
     */
    abstract protected static function fieldsByStep(): array;

    /**
     * Secciones del formulario asociadas a cada paso (unión cuando el rol opera varios).
     *
     * @return array<string, array<int, string>> step => visible section keys
     */
    abstract protected static function sectionsByStep(): array;

    /**
     * Identificador del pipeline para consultar `AuthorizationFacade`.
     *
     * @return string PipelineStepConstants::PIPELINE_*
     */
    abstract protected static function pipelineKey(): string;

    /**
     * Secciones siempre visibles independientemente del rol/estado. Las
     * subclases override si necesitan, p. ej. Invoice retorna `['ledger']`.
     *
     * @return array<int, string>
     */
    protected static function alwaysVisibleSections(): array
    {
        return [];
    }

    /**
     * Campos editables que el rol puede tocar en el step actual del registro.
     *
     * @return array<int, string>
     */
    final public function getEditableFields(int $roleId, string $step): array
    {
        if (!$this->auth->canOperate(new UserContext($roleId), static::pipelineKey(), $step)) {
            return [];
        }

        return static::fieldsByStep()[$step] ?? [];
    }

    /**
     * Secciones visibles para el rol — unión de las secciones de todos los steps
     * en los que el rol puede operar, más las siempre visibles.
     *
     * El segundo argumento `$currentStep` es opcional y se ignora; existe para
     * retrocompatibilidad con callers legacy de `InvoiceFieldAccessPolicy` y
     * `NoveltyService` que lo pasaban antes del refactor PA-008.
     *
     * @return array<int, string>
     */
    final public function getVisibleSections(int $roleId, string $currentStep = ''): array
    {
        unset($currentStep);
        $sections = static::alwaysVisibleSections();
        $operableSteps = $this->auth->operableSteps(
            new UserContext($roleId),
            static::pipelineKey(),
        );

        foreach ($operableSteps as $step) {
            $sections = array_merge($sections, static::sectionsByStep()[$step] ?? []);
        }

        return array_values(array_unique($sections));
    }

    /**
     * Filtra los datos crudos del POST a solo los campos editables y aplica
     * validación inline específica del dominio. Las subclases override para
     * añadir reglas (p. ej. PettyCash exige `accrual_date` cuando `accrued=true`).
     */
    public function filterEntityData(array $data, int $roleId, string $step): FilterResult
    {
        $allowed = $this->getEditableFields($roleId, $step);
        $patch = array_intersect_key($data, array_flip($allowed));

        return new FilterResult(patch: $patch, errors: []);
    }
}
```

- [ ] **Step 3.2: Verificar sintaxis**

```powershell
php -l src/Service/Pipeline/PipelineFieldPolicy.php
```

Esperado: `No syntax errors detected`.

- [ ] **Step 3.3: Commit**

```powershell
git add src/Service/Pipeline/PipelineFieldPolicy.php
git commit -m "feat(auth): PipelineFieldPolicy abstracta (PA-008)"
```

---

### Task 4: Migrar `InvoiceFieldAccessPolicy` a heredar de la base

**Files:**
- Modify: `src/Service/InvoiceFieldAccessPolicy.php`

- [ ] **Step 4.1: Reescribir `InvoiceFieldAccessPolicy`**

Reemplazar el contenido completo de `src/Service/InvoiceFieldAccessPolicy.php` por:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Service\Pipeline\PipelineFieldPolicy;

/**
 * Calcula qué campos puede editar un usuario en una factura y qué secciones
 * del formulario debe ver, dado su rol y el estado actual del pipeline.
 *
 * Audit PA-008 — antes implementaba todo inline; ahora extiende
 * `PipelineFieldPolicy` y aporta solo el mapeo específico de Invoice. El shape
 * de secciones pasó de `step => 'section'` a `step => ['section', ...]` para
 * unificar con Novelty/PettyCash/Refund.
 */
class InvoiceFieldAccessPolicy extends PipelineFieldPolicy
{
    /**
     * Campos editables por paso del pipeline (sin acoplamiento a rol).
     */
    private const FIELDS_BY_STEP = [
        InvoiceConstants::STATUS_APROBACION => [
            'invoice_number', 'issue_date', 'due_date',
            'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
            'detail', 'amount', 'expense_type_id', 'cost_center_id',
            'confirmed_by',
            'dian_validation',
        ],
        InvoiceConstants::STATUS_CONTABILIDAD => [
            'accrued', 'accrual_date', 'ready_for_payment',
        ],
        InvoiceConstants::STATUS_TESORERIA => [],
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => [],
        // Verificación de pago es read-only: la transición a Pagada se hace
        // exclusivamente vía InvoicePaymentService::confirmPayment.
        InvoiceConstants::STATUS_VERIFICACION_PAGO => [],
    ];

    /**
     * Secciones del formulario asociadas a cada paso (shape unificado con
     * Novelty/PettyCash/Refund — array, no string).
     */
    private const SECTIONS_BY_STEP = [
        InvoiceConstants::STATUS_APROBACION => ['revision'],
        InvoiceConstants::STATUS_CONTABILIDAD => ['accounting'],
        InvoiceConstants::STATUS_TESORERIA => ['treasury'],
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => ['payment_authorization'],
        // Verificación de pago reusa la sección de autorización (read-only),
        // donde aparece el botón "Pasar a Pagada".
        InvoiceConstants::STATUS_VERIFICACION_PAGO => ['payment_authorization'],
    ];

    protected static function fieldsByStep(): array
    {
        return self::FIELDS_BY_STEP;
    }

    protected static function sectionsByStep(): array
    {
        return self::SECTIONS_BY_STEP;
    }

    protected static function pipelineKey(): string
    {
        return PipelineStepConstants::PIPELINE_INVOICES;
    }

    protected static function alwaysVisibleSections(): array
    {
        return ['ledger'];
    }

    /**
     * Guard adicional sobre la base: si el status no es un valor válido del
     * enum, retorna vacío (la base ya lo hace por canOperate(false), pero el
     * guard explícito documenta la intención).
     */
    final public function getEditableFields(int $roleId, string $status): array
    {
        if (PipelineStatus::tryFrom($status) === null) {
            return [];
        }

        return parent::getEditableFields($roleId, $status);
    }

    /**
     * @return array Secciones colapsables — contrato heredado del audit previo;
     * no se usa hoy pero el caller (`InvoicePipelineService`) lo invoca.
     */
    public function getCollapsibleSections(int $roleId, string $status): array
    {
        return [];
    }
}
```

- [ ] **Step 4.2: Verificar sintaxis**

```powershell
php -l src/Service/InvoiceFieldAccessPolicy.php
```

Esperado: `No syntax errors detected`.

- [ ] **Step 4.3: Verificar que los callers de `getVisibleSections` siguen funcionando**

La firma de la base es `getVisibleSections(int $roleId, string $currentStep = '')` — el segundo argumento es opcional y se ignora, por lo que callers legacy que pasan `$status` siguen funcionando sin cambios. Verificar con:

```powershell
grep -rn "InvoiceFieldAccessPolicy\|->getVisibleSections" src/ --include="*.php"
```

No es necesario modificar callers, solo confirmar que ninguno está rota la firma (p. ej. pasando un 3er argumento). Si todos los callers usan 1 o 2 argumentos, OK.

- [ ] **Step 4.4: Lint del proyecto**

```powershell
composer cs-check
```

Esperado: `0 errors`. Si hay errores, ejecutar `composer cs-fix`.

- [ ] **Step 4.5: Commit**

```powershell
git add src/Service/InvoiceFieldAccessPolicy.php
git commit -m "refactor(auth): InvoiceFieldAccessPolicy extiende PipelineFieldPolicy + shape unificado (PA-008)"
```

---

### Task 5: Crear `NoveltyFieldAccessPolicy` y migrar `NoveltyService`

**Files:**
- Create: `src/Service/Pipeline/Novelty/Policy/NoveltyFieldAccessPolicy.php`
- Modify: `src/Service/NoveltyService.php`

- [ ] **Step 5.1: Crear `NoveltyFieldAccessPolicy`**

Crear `src/Service/Pipeline/Novelty/Policy/NoveltyFieldAccessPolicy.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\Policy;

use App\Constants\NoveltyConstants;
use App\Constants\PipelineStepConstants;
use App\Service\Pipeline\PipelineFieldPolicy;

/**
 * Campos editables y secciones visibles por estado del pipeline de novedades.
 *
 * Audit PA-008 — extraído de `NoveltyService::FIELDS_BY_STEP/SECTIONS_BY_STEP`
 * para unificar con el resto de dominios bajo `PipelineFieldPolicy`.
 */
final class NoveltyFieldAccessPolicy extends PipelineFieldPolicy
{
    private const FIELDS_BY_STEP = [
        NoveltyConstants::STATUS_APROBACION => ['approver_id'],
        NoveltyConstants::STATUS_RRHH => ['passes_payroll'],
        NoveltyConstants::STATUS_CONTABILIDAD => ['liquidation_doc_id'],
    ];

    private const SECTIONS_BY_STEP = [
        NoveltyConstants::STATUS_APROBACION => ['informacion', 'fechas', 'motivo', 'aprobacion', 'firmas'],
        NoveltyConstants::STATUS_RRHH => ['informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'firmas'],
        NoveltyConstants::STATUS_CONTABILIDAD => ['informacion', 'fechas', 'contabilidad'],
        NoveltyConstants::STATUS_REVISION_FIRMAS => ['informacion', 'fechas', 'firmas'],
        NoveltyConstants::STATUS_GDP => ['informacion', 'fechas', 'firmas'],
        NoveltyConstants::STATUS_TESORERIA => ['informacion'],
        NoveltyConstants::STATUS_AUTORIZACION_PAGO => ['informacion'],
    ];

    protected static function fieldsByStep(): array
    {
        return self::FIELDS_BY_STEP;
    }

    protected static function sectionsByStep(): array
    {
        return self::SECTIONS_BY_STEP;
    }

    protected static function pipelineKey(): string
    {
        return PipelineStepConstants::PIPELINE_NOVELTIES;
    }
}
```

- [ ] **Step 5.2: Verificar nombre exacto del constante de pipeline**

```powershell
grep -n "PIPELINE_NOVELTIES\|PIPELINE_NOVELTY" src/Constants/PipelineStepConstants.php
```

Si el constante se llama distinto (p. ej. `PIPELINE_EMPLOYEE_NOVELTIES`), ajustar en el archivo recién creado.

- [ ] **Step 5.3: Modificar `NoveltyService` para inyectar la policy**

En `src/Service/NoveltyService.php`:

1. Eliminar las constantes `FIELDS_BY_STEP` (líneas 22-26) y `SECTIONS_BY_STEP` (líneas 31-39).
2. Eliminar el `use` de `AuthorizationFacade` solo si ningún otro método lo usa (verificar; probablemente sí lo usa `denialReasonForAdvance` — mantenerlo en ese caso).
3. Añadir el `use`:

```php
use App\Service\Pipeline\Novelty\Policy\NoveltyFieldAccessPolicy;
```

4. Añadir propiedad y argumento de constructor:

```php
private NoveltyFieldAccessPolicy $fieldPolicy;
```

5. Modificar el constructor para inyectar `NoveltyFieldAccessPolicy` (mantener el orden de argumentos retrocompatible si es posible):

```php
public function __construct(
    AuthorizationFacade $auth,
    NoveltyFieldAccessPolicy $fieldPolicy,
    ?NoveltyPipelineStateRegistry $stateRegistry = null,
) {
    $this->auth = $auth;
    $this->fieldPolicy = $fieldPolicy;
    $this->stateRegistry = $stateRegistry ?? new NoveltyPipelineStateRegistry();
}
```

6. Reemplazar el método `getEditableFields` (líneas 420-433) por:

```php
public function getEditableFields(int $roleId, string $status): array
{
    return $this->fieldPolicy->getEditableFields($roleId, $status);
}
```

7. Reemplazar el método `getVisibleSections` (líneas 438-451) por:

```php
public function getVisibleSections(int $roleId, string $status): array
{
    // El parámetro $status se conserva por compatibilidad con callers; la
    // base computa la unión de operable steps sin necesitar el status actual.
    unset($status);

    return $this->fieldPolicy->getVisibleSections($roleId);
}
```

8. Reemplazar el método `filterEntityData` (líneas 478-483) por:

```php
public function filterEntityData(array $data, int $roleId, string $status): array
{
    return $this->fieldPolicy->filterEntityData($data, $roleId, $status)->patch;
}
```

- [ ] **Step 5.4: Verificar sintaxis**

```powershell
php -l src/Service/Pipeline/Novelty/Policy/NoveltyFieldAccessPolicy.php
php -l src/Service/NoveltyService.php
```

Esperado: `No syntax errors detected` en ambos.

- [ ] **Step 5.5: Commit (sin DI todavía — esa pieza se hace en Task 9)**

```powershell
git add src/Service/Pipeline/Novelty/Policy/NoveltyFieldAccessPolicy.php src/Service/NoveltyService.php
git commit -m "refactor(auth): extraer NoveltyFieldAccessPolicy de NoveltyService (PA-008)"
```

---

### Task 6: Crear `PettyCashFieldAccessPolicy` y refactorizar `PettyCashService`

**Files:**
- Create: `src/Service/Pipeline/PettyCash/Policy/PettyCashFieldAccessPolicy.php`
- Modify: `src/Service/PettyCashService.php`

- [ ] **Step 6.1: Crear `PettyCashFieldAccessPolicy`**

Crear `src/Service/Pipeline/PettyCash/Policy/PettyCashFieldAccessPolicy.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\Policy;

use App\Constants\PettyCashConstants;
use App\Constants\PipelineStepConstants;
use App\Service\Pipeline\FilterResult;
use App\Service\Pipeline\PipelineFieldPolicy;

/**
 * Audit PA-008 — extraído de `PettyCashService::_filterEditPatch` para alinear
 * caja menor con el patrón unificado. La validación inline de `accrual_date`
 * cuando `accrued=true` se conserva en el override de `filterEntityData`.
 */
final class PettyCashFieldAccessPolicy extends PipelineFieldPolicy
{
    private const FIELDS_BY_STEP = [
        PettyCashConstants::STATUS_AGRUPACION => ['notes'],
        PettyCashConstants::STATUS_CONTABILIDAD => ['notes', 'accrued', 'accrual_date', 'ready_for_payment'],
    ];

    // Section keys deben coincidir con templates/PettyCashRecords/edit.php:
    // notes / invoices / accounting / treasury. El ViewModel convierte este
    // array en los flags booleanos $showAccounting/$showTreasury que la template
    // consume.
    private const SECTIONS_BY_STEP = [
        PettyCashConstants::STATUS_AGRUPACION => ['notes', 'invoices'],
        PettyCashConstants::STATUS_CONTABILIDAD => ['notes', 'accounting'],
        PettyCashConstants::STATUS_TESORERIA => ['treasury'],
        PettyCashConstants::STATUS_AUTORIZACION_PAGO => ['treasury'],
        PettyCashConstants::STATUS_VERIFICACION_PAGO => ['treasury'],
    ];

    protected static function fieldsByStep(): array
    {
        return self::FIELDS_BY_STEP;
    }

    protected static function sectionsByStep(): array
    {
        return self::SECTIONS_BY_STEP;
    }

    protected static function pipelineKey(): string
    {
        return PipelineStepConstants::PIPELINE_PETTY_CASH;
    }

    /**
     * Override: replica el comportamiento exacto de
     * `PettyCashService::_filterEditPatch` original — filtra por **estado**,
     * sin gate de rol. El gate de rol vive en `denialReasonForAdvance` después
     * del save (preserva la API actual). La validación inline de `accrual_date`
     * se conserva.
     *
     * El parámetro `$roleId` se conserva por contrato heredado de la base.
     */
    public function filterEntityData(array $data, int $roleId, string $step): FilterResult
    {
        unset($roleId);
        $patch = [];
        $errors = [];

        // El código original hacía `$data['notes'] ?? $record->notes` para
        // preservar el valor previo si el POST no incluía el campo. Como la
        // firma de la base no recibe `$record`, usamos array_key_exists para
        // distinguir "no enviado" (no se patchea) de "enviado vacío" (se
        // patchea con cadena vacía). En producción la template siempre envía
        // `notes` aunque esté vacío.
        if (($step === PettyCashConstants::STATUS_AGRUPACION || $step === PettyCashConstants::STATUS_CONTABILIDAD)
            && array_key_exists('notes', $data)
        ) {
            $patch['notes'] = $data['notes'];
        }

        if ($step === PettyCashConstants::STATUS_CONTABILIDAD) {
            $isAccrued = !empty($data['accrued']);
            $patch['accrued'] = $isAccrued;

            if ($isAccrued) {
                $submittedDate = !empty($data['accrual_date']) ? $data['accrual_date'] : null;
                if (empty($submittedDate)) {
                    $errors[] = 'La fecha de causación es requerida cuando el registro está marcado como causado.';
                }
                $patch['accrual_date'] = $submittedDate;
            } else {
                $patch['accrual_date'] = null;
            }

            $patch['ready_for_payment'] = $data['ready_for_payment'] ?? null;
        }

        return new FilterResult(patch: $patch, errors: $errors);
    }
}
```

- [ ] **Step 6.2: Verificar nombre exacto del constante de pipeline**

```powershell
grep -n "PIPELINE_PETTY_CASH" src/Constants/PipelineStepConstants.php
```

Si el constante se llama distinto, ajustar.

- [ ] **Step 6.3: Modificar `PettyCashService` para usar la policy**

En `src/Service/PettyCashService.php`:

1. Añadir el `use`:

```php
use App\Service\Pipeline\PettyCash\Policy\PettyCashFieldAccessPolicy;
```

2. Añadir propiedad:

```php
private PettyCashFieldAccessPolicy $fieldPolicy;
```

3. Modificar el constructor — añadir el argumento al final de los nullable:

```php
public function __construct(
    HistoryServiceInterface $historyService,
    AuthorizationFacade $auth,
    PettyCashFieldAccessPolicy $fieldPolicy,
    ?PettyCashHistoryService $history = null,
    ?PettyCashPipelineStateRegistry $stateRegistry = null,
    ?EventManagerInterface $events = null,
) {
    $this->grouped = new GroupedInvoiceService(
        documentType: InvoiceConstants::DOCTYPE_CAJA_MENOR,
        fkField: 'petty_cash_record_id',
        recordTableName: 'PettyCashRecords',
        fkLabel: 'Caja Menor',
        historyService: $historyService,
    );
    $this->invoiceHistory = $historyService;
    $this->auth = $auth;
    $this->fieldPolicy = $fieldPolicy;
    $this->history = $history ?? new PettyCashHistoryService();
    $this->stateRegistry = $stateRegistry ?? new PettyCashPipelineStateRegistry();
    $this->events = $events;
}
```

4. Eliminar el método `_filterEditPatch` completo (líneas 120-154).

5. En `saveAndAdvance` (línea ~174), reemplazar:

```php
$filtered = $this->_filterEditPatch($record, $data);
if (!empty($filtered['errors'])) {
    return ServiceResult::fail($filtered['errors']);
}
```

por:

```php
$filtered = $this->fieldPolicy->filterEntityData($data, $roleId, $record->status);
if ($filtered->hasErrors()) {
    return ServiceResult::fail($filtered->errors);
}
```

6. En el bloque siguiente (línea ~181), reemplazar:

```php
if (!empty($filtered['patch'])) {
    $record = $recordsTable->patchEntity($record, $filtered['patch']);
```

por:

```php
if (!empty($filtered->patch)) {
    $record = $recordsTable->patchEntity($record, $filtered->patch);
```

- [ ] **Step 6.4: Modificar `PettyCashRecordsController::_buildEditViewModel` para computar flags vía policy**

En `src/Controller/PettyCashRecordsController.php`:

1. Añadir `use` al inicio:

```php
use App\Service\Pipeline\PettyCash\Policy\PettyCashFieldAccessPolicy;
```

2. Añadir propiedad:

```php
private PettyCashFieldAccessPolicy $fieldPolicy;
```

3. En `initialize()` (~línea 34), añadir tras `$this->documentService = ...`:

```php
$this->fieldPolicy = $container->get(PettyCashFieldAccessPolicy::class);
```

4. En `_buildEditViewModel` (~línea 299), antes del bloque `$canRegisterPayment = ...`, computar:

```php
$visibleSections = $this->fieldPolicy->getVisibleSections($roleId);
$showAccounting = in_array('accounting', $visibleSections, true);
$canEditAccounting = $showAccounting && $record->isContabilidad();
$showTreasury = in_array('treasury', $visibleSections, true);
$canEditTreasury = $showTreasury && $record->isTesoreria();
```

5. Pasar estos flags al ViewModel — verificar la firma actual de `PettyCashEditViewModel` y añadir los 4 nuevos argumentos si no existen. Si los flags ya se computan inline más arriba en el método, sustituir esos cálculos por los nuevos.

Buscar la firma actual del ViewModel:

```powershell
grep -n "showAccounting\|canEditAccounting\|showTreasury\|canEditTreasury" src/ViewModel/PettyCashEditViewModel.php templates/PettyCashRecords/
```

Si la template usa `$showAccounting` y compañía pero el ViewModel no los expone explícitamente, exponerlos como public readonly properties.

- [ ] **Step 6.5: Verificar sintaxis**

```powershell
php -l src/Service/Pipeline/PettyCash/Policy/PettyCashFieldAccessPolicy.php
php -l src/Service/PettyCashService.php
php -l src/Controller/PettyCashRecordsController.php
```

Esperado: `No syntax errors detected` en los 3.

- [ ] **Step 6.6: Commit**

```powershell
git add src/Service/Pipeline/PettyCash/Policy/PettyCashFieldAccessPolicy.php src/Service/PettyCashService.php src/Controller/PettyCashRecordsController.php
git commit -m "refactor(auth): extraer PettyCashFieldAccessPolicy + eliminar _filterEditPatch (PA-008)"
```

---

### Task 7: Crear `RefundFieldAccessPolicy` y refactorizar `RefundsController::edit`

**Files:**
- Create: `src/Service/Pipeline/Refund/Policy/RefundFieldAccessPolicy.php`
- Modify: `src/Controller/RefundsController.php`

- [ ] **Step 7.1: Crear `RefundFieldAccessPolicy`**

Crear `src/Service/Pipeline/Refund/Policy/RefundFieldAccessPolicy.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\Policy;

use App\Constants\PipelineStepConstants;
use App\Constants\RefundConstants;
use App\Service\Pipeline\FilterResult;
use App\Service\Pipeline\PipelineFieldPolicy;

/**
 * Audit PA-008 — extraído de `RefundsController::edit` (lógica inline
 * `if ($record->isAgrupacion()) {} if ($record->isContabilidad()) {}`). La
 * validación inline de `accrual_date` se conserva en el override de
 * `filterEntityData`.
 */
final class RefundFieldAccessPolicy extends PipelineFieldPolicy
{
    private const FIELDS_BY_STEP = [
        RefundConstants::STATUS_AGRUPACION => ['beneficiary_type', 'beneficiary_employee_id', 'beneficiary_provider_id'],
        RefundConstants::STATUS_CONTABILIDAD => ['accrued', 'accrual_date', 'ready_for_payment'],
    ];

    // Section keys: beneficiary / invoices / accounting / treasury — coinciden
    // con templates/Refunds/edit.php.
    private const SECTIONS_BY_STEP = [
        RefundConstants::STATUS_AGRUPACION => ['beneficiary', 'invoices'],
        RefundConstants::STATUS_CONTABILIDAD => ['beneficiary', 'invoices', 'accounting'],
        RefundConstants::STATUS_TESORERIA => ['beneficiary', 'invoices', 'accounting', 'treasury'],
        RefundConstants::STATUS_AUTORIZACION_PAGO => ['beneficiary', 'invoices', 'accounting', 'treasury'],
        RefundConstants::STATUS_VERIFICACION_PAGO => ['beneficiary', 'invoices', 'accounting', 'treasury'],
    ];

    protected static function fieldsByStep(): array
    {
        return self::FIELDS_BY_STEP;
    }

    protected static function sectionsByStep(): array
    {
        return self::SECTIONS_BY_STEP;
    }

    protected static function pipelineKey(): string
    {
        return PipelineStepConstants::PIPELINE_REFUNDS;
    }

    /**
     * Override: aplica las dos ramas (beneficiary en AGRUPACION, accounting en
     * CONTABILIDAD) que antes vivían inline en `RefundsController::edit`.
     * Preserva la validación de `accrual_date` requerida cuando `accrued=true`.
     *
     * No realiza chequeo de rol aquí — el controller original tampoco lo hacía
     * (solo verificaba estado). El `_ensureExpectedStatus` del controller sigue
     * siendo el gate de status; el gate de rol queda como deuda separada
     * (fuera del alcance de PA-008).
     *
     * El parámetro `$roleId` se conserva por contrato heredado de la base.
     */
    public function filterEntityData(array $data, int $roleId, string $step): FilterResult
    {
        unset($roleId);
        $patch = [];
        $errors = [];

        if ($step === RefundConstants::STATUS_AGRUPACION) {
            $beneficiaryType = $data['beneficiary_type'] ?? null;
            $patch['beneficiary_type'] = $beneficiaryType ?: null;
            $patch['beneficiary_employee_id'] = $beneficiaryType === RefundConstants::BENEFICIARY_TYPE_EMPLOYEE
                && !empty($data['beneficiary_employee_id'])
                ? (int)$data['beneficiary_employee_id']
                : null;
            $patch['beneficiary_provider_id'] = $beneficiaryType === RefundConstants::BENEFICIARY_TYPE_PROVIDER
                && !empty($data['beneficiary_provider_id'])
                ? (int)$data['beneficiary_provider_id']
                : null;
        }

        if ($step === RefundConstants::STATUS_CONTABILIDAD) {
            $isAccrued = !empty($data['accrued']);
            $patch['accrued'] = $isAccrued;

            if ($isAccrued) {
                $submittedDate = !empty($data['accrual_date']) ? $data['accrual_date'] : null;
                if (empty($submittedDate)) {
                    $errors[] = 'La fecha de causación es requerida cuando el registro está marcado como causado.';
                }
                $patch['accrual_date'] = $submittedDate;
            } else {
                $patch['accrual_date'] = null;
            }

            $patch['ready_for_payment'] = $data['ready_for_payment'] ?? null;
        }

        return new FilterResult(patch: $patch, errors: $errors);
    }
}
```

- [ ] **Step 7.2: Verificar nombre exacto del constante de pipeline**

```powershell
grep -n "PIPELINE_REFUNDS\|PIPELINE_REFUND" src/Constants/PipelineStepConstants.php
```

Si el constante se llama distinto, ajustar.

- [ ] **Step 7.3: Refactorizar `RefundsController::edit`**

En `src/Controller/RefundsController.php`:

1. Añadir `use` al inicio:

```php
use App\Service\Pipeline\Refund\Policy\RefundFieldAccessPolicy;
```

2. Añadir propiedad:

```php
private RefundFieldAccessPolicy $fieldPolicy;
```

3. En `initialize()` (~línea 36), añadir tras `$this->documentService = ...`:

```php
$this->fieldPolicy = $container->get(RefundFieldAccessPolicy::class);
```

4. En el método `edit()` (línea 306), reemplazar el bloque completo de líneas 329-362 (desde `$data = $this->request->getData();` hasta el final del primer `if (!empty($patchData)) {`) por:

```php
$data = $this->request->getData();
$roleId = (int)$this->_getCurrentUser()->role_id;
$filtered = $this->fieldPolicy->filterEntityData($data, $roleId, $record->status);

if ($filtered->hasErrors()) {
    foreach ($filtered->errors as $err) {
        $this->Flash->error($err);
    }

    return $this->redirect(['action' => 'edit', $id]);
}

$patchData = $filtered->patch;

if (!empty($patchData)) {
```

(El `if (!empty($patchData)) {` y el resto del bloque hasta el cierre del método permanecen sin cambios.)

- [ ] **Step 7.4: Verificar sintaxis**

```powershell
php -l src/Service/Pipeline/Refund/Policy/RefundFieldAccessPolicy.php
php -l src/Controller/RefundsController.php
```

Esperado: `No syntax errors detected`.

- [ ] **Step 7.5: Commit**

```powershell
git add src/Service/Pipeline/Refund/Policy/RefundFieldAccessPolicy.php src/Controller/RefundsController.php
git commit -m "refactor(auth): extraer RefundFieldAccessPolicy de RefundsController (PA-008)"
```

---

### Task 8: Cablear DI en `src/Application.php`

**Files:**
- Modify: `src/Application.php`

- [ ] **Step 8.1: Añadir imports de las 3 policies nuevas**

En `src/Application.php`, alrededor de las líneas 60-77 (zona de `use App\Service\Pipeline\...`), añadir los 3 nuevos imports en orden alfabético:

```php
use App\Service\Pipeline\Novelty\Policy\NoveltyFieldAccessPolicy;
use App\Service\Pipeline\PettyCash\Policy\PettyCashFieldAccessPolicy;
use App\Service\Pipeline\Refund\Policy\RefundFieldAccessPolicy;
```

- [ ] **Step 8.2: Registrar `NoveltyFieldAccessPolicy` en el container**

En el bloque `=== Novelty domain ===` (~línea 321), antes de `$container->addShared(NoveltyService::class)`, añadir:

```php
$container->addShared(NoveltyFieldAccessPolicy::class)
    ->addArgument(AuthorizationFacade::class);
```

Luego ajustar la registración de `NoveltyService` para pasar la policy:

```php
$container->addShared(NoveltyService::class)
    ->addArguments([
        AuthorizationFacade::class,
        NoveltyFieldAccessPolicy::class,
    ]);
```

- [ ] **Step 8.3: Registrar `PettyCashFieldAccessPolicy`**

En el bloque `=== Petty cash / payment scheduling / advances ===` (~línea 332), antes de `$container->addShared(PettyCashService::class)`, añadir:

```php
$container->addShared(PettyCashFieldAccessPolicy::class)
    ->addArgument(AuthorizationFacade::class);
```

Ajustar la registración de `PettyCashService`:

```php
$container->addShared(PettyCashService::class)
    ->addArguments([
        InvoiceHistoryService::class,
        AuthorizationFacade::class,
        PettyCashFieldAccessPolicy::class,
    ]);
```

- [ ] **Step 8.4: Registrar `RefundFieldAccessPolicy`**

Inmediatamente después del bloque de PettyCash, antes de `$container->addShared(RefundDocumentService::class)`, añadir:

```php
$container->addShared(RefundFieldAccessPolicy::class)
    ->addArgument(AuthorizationFacade::class);
```

(`RefundsController` la resuelve vía `$container->get(...)` en `initialize()`, así que `RefundService` no necesita cambiar su firma. Verificar.)

- [ ] **Step 8.5: Verificar sintaxis**

```powershell
php -l src/Application.php
```

Esperado: `No syntax errors detected`.

- [ ] **Step 8.6: Verificar arranque del server**

```powershell
php bin/cake server
```

Esperado: el server arranca en `http://localhost:8765` sin errores. Detener con `Ctrl+C` tras ver el mensaje "Welcome to CakePHP" o similar en logs.

Si arroja error tipo `Argument N passed to ... must be an instance of ...`, revisar el orden de argumentos en `addArguments()`.

- [ ] **Step 8.7: Commit**

```powershell
git add src/Application.php
git commit -m "chore(auth): cablear DI de los 3 *FieldAccessPolicy nuevos (PA-008)"
```

---

### Task 9: Validación manual PR2 + cierre en audit doc

**Files:**
- Modify: `docs/audits/permissions-audit-2026-05-11.md`

- [ ] **Step 9.1: Lint global**

```powershell
composer cs-check
```

Esperado: `0 errors`. Si hay errores: `composer cs-fix`, revisar diff, commitear como `style: fix CS in PA-008 refactor`.

- [ ] **Step 9.2: Smoke test del server**

```powershell
php bin/cake server
```

En otra terminal, abrir el navegador (o `curl -I`) contra:

- `http://localhost:8765/login` → 200
- Login con `admin / Admin2024*` → 302 → dashboard
- `/invoices` → 200
- `/employee-novelties` → 200
- `/petty-cash-records` → 200
- `/refunds` → 200

Detener server.

- [ ] **Step 9.3: Validación manual por rol y dominio**

Levantar `php bin/cake server` y ejercitar los 4 dominios con al menos 3 roles distintos (Administrador, Contabilidad, Tesorería). En cada combinación:

| Dominio | URL | Verificar |
|---|---|---|
| Invoice | `/invoices/edit/{id}` con un id en cada paso del pipeline | Mismos campos editables y secciones visibles que antes del refactor |
| Novelty | `/employee-novelties/edit/{id}` con un id en cada paso | Idem |
| PettyCash | `/petty-cash-records/edit/{id}` en `agrupacion` y `contabilidad` | Idem. **Probar:** marcar `accrued=true` sin `accrual_date` y enviar → Flash error "La fecha de causación es requerida..." |
| Refund | `/refunds/edit/{id}` en `agrupacion` y `contabilidad` | Idem. **Probar:** misma validación de `accrual_date` que en PettyCash |

Si alguna combinación se comporta distinto, abrir issue, no proseguir.

- [ ] **Step 9.4: Marcar PA-008 como Resuelto en el audit doc**

En `docs/audits/permissions-audit-2026-05-11.md`, tabla "Estado de remediación", fila PA-008:

```markdown
| PA-008 | 🟡 Minor | `InvoiceFieldAccessPolicy::SECTION_BY_STEP` (string) vs `NoveltyService::SECTIONS_BY_STEP` (array) — divergencia gratuita | ✅ Resuelto | <commit-hash> (2026-05-12) |
```

Añadir bloque `> **Cierre:**` justo bajo el encabezado `## PA-008 — Shape divergente...`:

```markdown
> **Cierre:** alcance ampliado durante implementación: el audit declaraba 2 consumidores (Invoice, Novelty) pero la exploración previa identificó 4 dominios con la misma necesidad (PettyCash con `_filterEditPatch` inline en service, Refund con la misma lógica inline en controller). Refactor unifica los 4 bajo `PipelineFieldPolicy` abstracta + `FilterResult` DTO. Shape de secciones unificado a `array<step, string[]>`. Templates de PettyCash/Refund conservan su contrato de flags booleanos pero el ViewModel los computa vía `policy->getVisibleSections()`.
```

- [ ] **Step 9.5: Commit final del PR2**

```powershell
git add docs/audits/permissions-audit-2026-05-11.md
git commit -m "docs(audit): cerrar PA-008 (PipelineFieldPolicy + 4 dominios)"
```

---

## PR 3 — PA-011: 5 ActionPolicies nuevas

### Task 10: Crear `InvoiceActionPolicy`

**Files:**
- Create: `src/Service/Pipeline/Invoice/Policy/InvoiceActionPolicy.php`
- Modify: `src/Controller/InvoicesController.php`

- [ ] **Step 10.1: Inventariar los `canOperate` inline en `InvoicesController`**

```powershell
grep -n "authFacade->canOperate" src/Controller/InvoicesController.php
```

Esperado (referencia, puede haber más):

```
361: canConfirmPayment = canOperate(..., PIPELINE_INVOICES, STATUS_VERIFICACION_PAGO)
366: canRegisterPayment = canOperate(..., PIPELINE_INVOICES, STATUS_TESORERIA)
371: canAuthorizePayment = canOperate(..., PIPELINE_INVOICES, STATUS_AUTORIZACION_PAGO)
```

Anotar las 3 (o más) acciones para reproducirlas en la policy.

- [ ] **Step 10.2: Crear `InvoiceActionPolicy`**

Crear `src/Service/Pipeline/Invoice/Policy/InvoiceActionPolicy.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\Invoice;
use App\ValueObject\UserContext;

/**
 * Audit PA-011 — espejo de `AdvanceLegalizationActionPolicy` para el pipeline
 * de facturas. Combina la dimensión rol×paso (vía `AuthorizationFacade`) con
 * el estado del agregado (`Invoice::isRejected()`, `isPaid()`).
 */
final class InvoiceActionPolicy
{
    public function __construct(
        private AuthorizationFacade $auth,
    ) {
    }

    public function canRegisterPayment(Invoice $invoice, int $roleId): bool
    {
        if ($invoice->isRejected() || $invoice->isPaid()) {
            return false;
        }

        return $this->_canOperate($roleId, InvoiceConstants::STATUS_TESORERIA);
    }

    public function canAuthorizePayment(Invoice $invoice, int $roleId): bool
    {
        if ($invoice->isRejected() || $invoice->isPaid()) {
            return false;
        }

        return $this->_canOperate($roleId, InvoiceConstants::STATUS_AUTORIZACION_PAGO);
    }

    public function canConfirmPayment(Invoice $invoice, int $roleId): bool
    {
        if ($invoice->isRejected() || $invoice->isPaid()) {
            return false;
        }

        return $this->_canOperate($roleId, InvoiceConstants::STATUS_VERIFICACION_PAGO);
    }

    private function _canOperate(int $roleId, string $step): bool
    {
        return $this->auth->canOperate(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_INVOICES,
            $step,
        );
    }
}
```

- [ ] **Step 10.3: Migrar callers en `InvoicesController`**

En `src/Controller/InvoicesController.php`:

1. Añadir `use`:

```php
use App\Service\Pipeline\Invoice\Policy\InvoiceActionPolicy;
```

2. Añadir propiedad y resolver en `initialize()`:

```php
private InvoiceActionPolicy $actionPolicy;
```

```php
$this->actionPolicy = $container->get(InvoiceActionPolicy::class);
```

3. Localizar el bloque (~líneas 360-375) donde se calculan los 3 flags inline y reemplazarlo por:

```php
$canConfirmPayment = $this->actionPolicy->canConfirmPayment($invoice, $roleId);
$canRegisterPayment = $this->actionPolicy->canRegisterPayment($invoice, $roleId);
$canAuthorizePayment = $this->actionPolicy->canAuthorizePayment($invoice, $roleId);
```

(El nombre exacto de la variable `$invoice` y `$roleId` puede variar — adaptar según el contexto del método. Si la variable es `$record` o el roleId se obtiene de otra forma, ajustar.)

- [ ] **Step 10.4: Verificar sintaxis**

```powershell
php -l src/Service/Pipeline/Invoice/Policy/InvoiceActionPolicy.php
php -l src/Controller/InvoicesController.php
```

- [ ] **Step 10.5: Commit (DI se hace en Task 15)**

```powershell
git add src/Service/Pipeline/Invoice/Policy/InvoiceActionPolicy.php src/Controller/InvoicesController.php
git commit -m "refactor(auth): InvoiceActionPolicy + migrar callers en InvoicesController (PA-011)"
```

---

### Task 11: Crear `RefundActionPolicy` y eliminar `_canOperateRefundStep`

**Files:**
- Create: `src/Service/Pipeline/Refund/Policy/RefundActionPolicy.php`
- Modify: `src/Controller/RefundsController.php`

- [ ] **Step 11.1: Crear `RefundActionPolicy`**

Crear `src/Service/Pipeline/Refund/Policy/RefundActionPolicy.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\PipelineStepConstants;
use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\ValueObject\UserContext;

/**
 * Audit PA-011 — espejo de `AdvanceLegalizationActionPolicy` para reintegros.
 * Reemplaza el wrapper privado `RefundsController::_canOperateRefundStep` y los
 * 3+ `authFacade->canOperate` inline del controller.
 */
final class RefundActionPolicy
{
    public function __construct(
        private AuthorizationFacade $auth,
    ) {
    }

    public function canOperateStep(int $roleId, string $step): bool
    {
        return $this->auth->canOperate(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_REFUNDS,
            $step,
        );
    }

    public function canOperateCurrentStep(Refund $refund, int $roleId): bool
    {
        if ($refund->isPagada()) {
            return false;
        }

        return $this->canOperateStep($roleId, $refund->status);
    }

    public function canRegisterPayment(Refund $refund, int $roleId): bool
    {
        if ($refund->isPagada()) {
            return false;
        }

        return $this->canOperateStep($roleId, RefundConstants::STATUS_TESORERIA);
    }

    public function canAuthorizePayment(Refund $refund, int $roleId): bool
    {
        if ($refund->isPagada()) {
            return false;
        }

        return $this->canOperateStep($roleId, RefundConstants::STATUS_AUTORIZACION_PAGO);
    }

    public function canConfirmPayment(Refund $refund, int $roleId): bool
    {
        if ($refund->isPagada()) {
            return false;
        }

        return $this->canOperateStep($roleId, RefundConstants::STATUS_VERIFICACION_PAGO);
    }
}
```

- [ ] **Step 11.2: Migrar `RefundsController`**

En `src/Controller/RefundsController.php`:

1. Añadir `use`:

```php
use App\Service\Pipeline\Refund\Policy\RefundActionPolicy;
```

2. Añadir propiedad y resolver en `initialize()`:

```php
private RefundActionPolicy $actionPolicy;
```

```php
$this->actionPolicy = $container->get(RefundActionPolicy::class);
```

3. **Eliminar el método `_canOperateRefundStep` completo** (líneas 50-61).

4. En `_documentGate` (línea 79), reemplazar:

```php
if (!$this->_canOperateRefundStep($record->status)) {
```

por:

```php
$roleId = (int)$this->_getCurrentUser()->role_id;
if (!$this->actionPolicy->canOperateStep($roleId, $record->status)) {
```

5. En los `authFacade->canOperate` inline del ViewModel (~líneas 457-470), reemplazar los 3 cálculos por:

```php
canRegisterPayment: $this->actionPolicy->canRegisterPayment($record, $roleId),
canAuthorizePayment: $this->actionPolicy->canAuthorizePayment($record, $roleId),
canConfirmPayment: $this->actionPolicy->canConfirmPayment($record, $roleId),
```

6. Para el `canOperate` en línea ~602 (verificar ubicación exacta con `grep -n`), reemplazar con el método apropiado de la policy según el step que esté chequeando.

- [ ] **Step 11.3: Verificar sintaxis**

```powershell
php -l src/Service/Pipeline/Refund/Policy/RefundActionPolicy.php
php -l src/Controller/RefundsController.php
```

- [ ] **Step 11.4: Verificar que no quedó referencia huérfana**

```powershell
grep -n "_canOperateRefundStep\|authFacade->canOperate" src/Controller/RefundsController.php
```

Esperado: 0 matches. Si quedó alguna, migrarla al método correspondiente de la policy.

- [ ] **Step 11.5: Commit**

```powershell
git add src/Service/Pipeline/Refund/Policy/RefundActionPolicy.php src/Controller/RefundsController.php
git commit -m "refactor(auth): RefundActionPolicy + eliminar _canOperateRefundStep (PA-011)"
```

---

### Task 12: Crear `PettyCashActionPolicy`

**Files:**
- Create: `src/Service/Pipeline/PettyCash/Policy/PettyCashActionPolicy.php`
- Modify: `src/Controller/PettyCashRecordsController.php`

- [ ] **Step 12.1: Crear la policy**

Crear `src/Service/Pipeline/PettyCash/Policy/PettyCashActionPolicy.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\PettyCashConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\PettyCashRecord;
use App\ValueObject\UserContext;

/**
 * Audit PA-011 — espejo de `AdvanceLegalizationActionPolicy` para caja menor.
 * Reemplaza los `authFacade->canOperate` inline del ViewModel del controller.
 */
final class PettyCashActionPolicy
{
    public function __construct(
        private AuthorizationFacade $auth,
    ) {
    }

    public function canOperateStep(int $roleId, string $step): bool
    {
        return $this->auth->canOperate(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_PETTY_CASH,
            $step,
        );
    }

    public function canRegisterPayment(PettyCashRecord $record, int $roleId): bool
    {
        if ($record->isPagada()) {
            return false;
        }

        return $this->canOperateStep($roleId, PettyCashConstants::STATUS_TESORERIA);
    }

    public function canAuthorizePayment(PettyCashRecord $record, int $roleId): bool
    {
        if ($record->isPagada()) {
            return false;
        }

        return $this->canOperateStep($roleId, PettyCashConstants::STATUS_AUTORIZACION_PAGO);
    }

    public function canConfirmPayment(PettyCashRecord $record, int $roleId): bool
    {
        if ($record->isPagada()) {
            return false;
        }

        return $this->canOperateStep($roleId, PettyCashConstants::STATUS_VERIFICACION_PAGO);
    }
}
```

- [ ] **Step 12.2: Migrar callers en `PettyCashRecordsController`**

En `src/Controller/PettyCashRecordsController.php`:

1. Añadir `use`:

```php
use App\Service\Pipeline\PettyCash\Policy\PettyCashActionPolicy;
```

2. Añadir propiedad y resolver en `initialize()`:

```php
private PettyCashActionPolicy $actionPolicy;
```

```php
$this->actionPolicy = $container->get(PettyCashActionPolicy::class);
```

3. En `_buildEditViewModel` (~línea 311), reemplazar el bloque de los 3 `authFacade->canOperate` por:

```php
$canRegisterPayment = $this->actionPolicy->canRegisterPayment($record, $roleId);
$canAuthorizePayment = $this->actionPolicy->canAuthorizePayment($record, $roleId);
$canConfirmPayment = $this->actionPolicy->canConfirmPayment($record, $roleId);
```

- [ ] **Step 12.3: Verificar sintaxis y huérfanos**

```powershell
php -l src/Service/Pipeline/PettyCash/Policy/PettyCashActionPolicy.php
php -l src/Controller/PettyCashRecordsController.php
grep -n "authFacade->canOperate" src/Controller/PettyCashRecordsController.php
```

Esperado: 0 matches del último grep.

- [ ] **Step 12.4: Commit**

```powershell
git add src/Service/Pipeline/PettyCash/Policy/PettyCashActionPolicy.php src/Controller/PettyCashRecordsController.php
git commit -m "refactor(auth): PettyCashActionPolicy + migrar callers (PA-011)"
```

---

### Task 13: Crear `NoveltyActionPolicy`

**Files:**
- Create: `src/Service/Pipeline/Novelty/Policy/NoveltyActionPolicy.php`
- Modify: `src/Controller/EmployeeNoveltiesController.php`

- [ ] **Step 13.1: Inventariar `canOperate` en `EmployeeNoveltiesController`**

```powershell
grep -n "authFacade->canOperate\|canOperate(" src/Controller/EmployeeNoveltiesController.php
```

Anotar la lista. Si no hay matches (porque la lógica vive en `NoveltyService` y se llama vía `canX()` métodos), la policy aún se justifica para uniformidad con los otros 4 dominios, pero sus métodos espejan los que NoveltyService expone.

- [ ] **Step 13.2: Crear `NoveltyActionPolicy`**

Crear `src/Service/Pipeline/Novelty/Policy/NoveltyActionPolicy.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\NoveltyConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\EmployeeNovelty;
use App\ValueObject\UserContext;

/**
 * Audit PA-011 — espejo de `AdvanceLegalizationActionPolicy` para novedades.
 */
final class NoveltyActionPolicy
{
    public function __construct(
        private AuthorizationFacade $auth,
    ) {
    }

    public function canOperateStep(int $roleId, string $step): bool
    {
        return $this->auth->canOperate(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_NOVELTIES,
            $step,
        );
    }

    public function canOperateCurrentStep(EmployeeNovelty $novelty, int $roleId): bool
    {
        if ($novelty->isRejected()) {
            return false;
        }

        return $this->canOperateStep($roleId, (string)$novelty->pipeline_status);
    }
}
```

- [ ] **Step 13.3: Migrar callers en `EmployeeNoveltiesController`**

Según el inventario del paso 13.1: para cada `canOperate` o wrapper privado en el controller, reemplazar por el método apropiado de `NoveltyActionPolicy`. Si no hay callers que migrar, la policy queda registrada pero no usada (es válido — el contrato existe para futuras acciones). Documentar en el commit message.

Añadir `use` y resolver en `initialize()` como en las tasks anteriores **solo si** se usa.

- [ ] **Step 13.4: Verificar sintaxis y huérfanos**

```powershell
php -l src/Service/Pipeline/Novelty/Policy/NoveltyActionPolicy.php
php -l src/Controller/EmployeeNoveltiesController.php
```

- [ ] **Step 13.5: Commit**

```powershell
git add src/Service/Pipeline/Novelty/Policy/NoveltyActionPolicy.php src/Controller/EmployeeNoveltiesController.php
git commit -m "refactor(auth): NoveltyActionPolicy (PA-011)"
```

---

### Task 14: Crear `PaymentSchedulingActionPolicy`

**Files:**
- Create: `src/Service/Pipeline/PaymentScheduling/Policy/PaymentSchedulingActionPolicy.php`
- Modify: `src/Controller/PaymentSchedulingsController.php`

- [ ] **Step 14.1: Inventariar `canOperate` en `PaymentSchedulingsController`**

```powershell
grep -n "authFacade->canOperate" src/Controller/PaymentSchedulingsController.php
```

Referencia (líneas 173, 179, 280, 339):
- línea 173: `canReject` (chequea `STATUS_AUTORIZACION_PAGO`)
- línea 179: `canConfirmPayment` (chequea `STATUS_VERIFICACION_PAGO`)
- línea 280: idem `canReject`
- línea 339: gate en alguna acción de mutación

- [ ] **Step 14.2: Crear `PaymentSchedulingActionPolicy`**

Crear `src/Service/Pipeline/PaymentScheduling/Policy/PaymentSchedulingActionPolicy.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\PaymentSchedulingConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\PaymentScheduling;
use App\ValueObject\UserContext;

/**
 * Audit PA-011 — espejo de `AdvanceLegalizationActionPolicy` para programación
 * de pagos.
 */
final class PaymentSchedulingActionPolicy
{
    public function __construct(
        private AuthorizationFacade $auth,
    ) {
    }

    public function canOperateStep(int $roleId, string $step): bool
    {
        return $this->auth->canOperate(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            $step,
        );
    }

    public function canReject(PaymentScheduling $scheduling, int $roleId): bool
    {
        if ($scheduling->isPagada()) {
            return false;
        }

        if ($scheduling->pipeline_status !== PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO) {
            return false;
        }

        return $this->canOperateStep($roleId, PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO);
    }

    public function canConfirmPayment(PaymentScheduling $scheduling, int $roleId): bool
    {
        if ($scheduling->isPagada()) {
            return false;
        }

        return $this->canOperateStep($roleId, PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO);
    }
}
```

- [ ] **Step 14.3: Migrar callers en `PaymentSchedulingsController`**

En `src/Controller/PaymentSchedulingsController.php`:

1. Añadir `use`:

```php
use App\Service\Pipeline\PaymentScheduling\Policy\PaymentSchedulingActionPolicy;
```

2. Añadir propiedad y resolver en `initialize()`:

```php
private PaymentSchedulingActionPolicy $actionPolicy;
```

```php
$this->actionPolicy = $container->get(PaymentSchedulingActionPolicy::class);
```

3. En `_buildEditViewModel` (~línea 164-205), reemplazar los `authFacade->canOperate` por las llamadas a `$this->actionPolicy->canReject/canConfirmPayment`. La condición compuesta de línea ~172 (`$canReject = $currentStatus === STATUS_AUTORIZACION_PAGO && canOperate(...)`) colapsa a `$canReject = $this->actionPolicy->canReject($record, $roleId);`.

4. Para los `canOperate` en líneas ~280 y ~339, identificar qué representa cada uno y mapear al método correspondiente. Si alguno no encaja en los métodos provistos, **añadir el método a la policy** antes de migrarlo (no dejar el `canOperate` inline).

- [ ] **Step 14.4: Verificar sintaxis y huérfanos**

```powershell
php -l src/Service/Pipeline/PaymentScheduling/Policy/PaymentSchedulingActionPolicy.php
php -l src/Controller/PaymentSchedulingsController.php
grep -n "authFacade->canOperate" src/Controller/PaymentSchedulingsController.php
```

Esperado: 0 matches del último grep.

- [ ] **Step 14.5: Commit**

```powershell
git add src/Service/Pipeline/PaymentScheduling/Policy/PaymentSchedulingActionPolicy.php src/Controller/PaymentSchedulingsController.php
git commit -m "refactor(auth): PaymentSchedulingActionPolicy + migrar callers (PA-011)"
```

---

### Task 15: Cablear DI de las 5 ActionPolicies en `Application.php`

**Files:**
- Modify: `src/Application.php`

- [ ] **Step 15.1: Añadir imports**

En `src/Application.php`, en la zona de imports de `use App\Service\Pipeline\...`, añadir en orden alfabético:

```php
use App\Service\Pipeline\Invoice\Policy\InvoiceActionPolicy;
use App\Service\Pipeline\Novelty\Policy\NoveltyActionPolicy;
use App\Service\Pipeline\PaymentScheduling\Policy\PaymentSchedulingActionPolicy;
use App\Service\Pipeline\PettyCash\Policy\PettyCashActionPolicy;
use App\Service\Pipeline\Refund\Policy\RefundActionPolicy;
```

- [ ] **Step 15.2: Registrar las 5 policies**

Buscar el bloque donde se registra `AdvanceLegalizationActionPolicy` (línea ~241) y añadir las otras 4 justo después:

```php
$container->addShared(AdvanceLegalizationActionPolicy::class)
    ->addArgument(AuthorizationFacade::class);
$container->addShared(InvoiceActionPolicy::class)
    ->addArgument(AuthorizationFacade::class);
$container->addShared(NoveltyActionPolicy::class)
    ->addArgument(AuthorizationFacade::class);
$container->addShared(PaymentSchedulingActionPolicy::class)
    ->addArgument(AuthorizationFacade::class);
$container->addShared(PettyCashActionPolicy::class)
    ->addArgument(AuthorizationFacade::class);
$container->addShared(RefundActionPolicy::class)
    ->addArgument(AuthorizationFacade::class);
```

- [ ] **Step 15.3: Verificar sintaxis y arranque**

```powershell
php -l src/Application.php
php bin/cake server
```

Esperado: server arranca sin errores. Detener.

- [ ] **Step 15.4: Commit**

```powershell
git add src/Application.php
git commit -m "chore(auth): cablear DI de las 5 *ActionPolicy (PA-011)"
```

---

### Task 16: Validación manual PR3 + cierre del audit doc

**Files:**
- Modify: `docs/audits/permissions-audit-2026-05-11.md`

- [ ] **Step 16.1: Lint global**

```powershell
composer cs-check
```

Si hay errores: `composer cs-fix` + commit `style: fix CS in PA-011 refactor`.

- [ ] **Step 16.2: Validación manual de gates**

Levantar `php bin/cake server`. Con 2 roles (uno con permisos completos, uno con permisos parciales — p. ej. Contabilidad), ejercitar las acciones de pipeline en cada controller:

| Controller | Acción | Rol con permiso → | Rol sin permiso → |
|---|---|---|---|
| Invoices | register-payment / authorize-payment / confirm-payment | botón visible y acción ejecuta | botón oculto/deshabilitado, ruta directa → flash error |
| Refunds | upload-document / delete-document (en `tesoreria`) | OK | flash "No tiene permisos para gestionar soportes..." |
| PettyCash | register-payment / authorize-payment / confirm-payment | idem Invoices | idem |
| Novelty | acciones del pipeline | idem | idem |
| PaymentScheduling | reject / confirm-payment | idem | idem |

Reintegro adicional: registro pagado intenta upload de documento → flash "No se puede ... un soporte de un reintegro pagado." (status 409).

- [ ] **Step 16.3: Verificar que no quedó ningún `_canOperate*` o `authFacade->canOperate` huérfano en controllers**

```powershell
grep -rn "_canOperate\|authFacade->canOperate" src/Controller/ --include="*.php"
```

Esperado: **0 matches**. Si algún controller todavía tiene un `canOperate` inline, decidir: (a) migrarlo a la policy correspondiente y rehacer commit, o (b) si es un gate de bajo nivel no cubierto por las policies, dejarlo y documentar.

- [ ] **Step 16.4: Marcar PA-011 como Resuelto en el audit doc**

En `docs/audits/permissions-audit-2026-05-11.md`, tabla "Estado de remediación", fila PA-011:

```markdown
| PA-011 | 🟡 Minor | `AdvanceLegalizationActionPolicy` modela bien el Policy pattern; Refund/PettyCash/Invoice/Novelty/PaymentScheduling siguen llamando `canOperate` inline → dos estilos coexistiendo | ✅ Resuelto | <commit-hash> (2026-05-12) |
```

Añadir bloque `> **Cierre:**` justo bajo `## PA-011 — Dos modelos de Policy coexisten 🟡`:

```markdown
> **Cierre:** se crearon `InvoiceActionPolicy`, `RefundActionPolicy`, `PettyCashActionPolicy`, `NoveltyActionPolicy`, `PaymentSchedulingActionPolicy` espejando el shape de `AdvanceLegalizationActionPolicy`. Controllers y ViewModels migrados a `$this->{module}Policy->canX(...)`. `RefundsController::_canOperateRefundStep` eliminado. `grep "authFacade->canOperate" src/Controller/` retorna 0 matches tras el refactor.
```

- [ ] **Step 16.5: Actualizar verdicto global y resumen ejecutivo**

En `docs/audits/permissions-audit-2026-05-11.md`, línea 8 (verdicto global), reemplazar:

```markdown
**Verdicto global:** ⚠️ **NEEDS REWORK** — ...
```

por:

```markdown
**Verdicto global:** ✅ **RESUELTO** (2026-05-12) — 13 hallazgos cerrados, 1 marcado como WONTFIX con criterio explícito de reapertura (PA-007). Refactor original de 14 hallazgos completado; sistema de permisos unificado bajo `AuthorizationFacade` (PA-004), atributos de método para gating (PA-002), `DenialReason` enum para mensajes (PA-005), `PipelineFieldPolicy` base para campos editables (PA-008) y `*ActionPolicy` por dominio (PA-011).
```

- [ ] **Step 16.6: Commit final**

```powershell
git add docs/audits/permissions-audit-2026-05-11.md
git commit -m "docs(audit): cerrar PA-011 + verdicto global ✅ RESUELTO"
```

---

## Cierre

Tras los 16 tasks:

- **PR1** mergea con un único commit.
- **PR2** mergea con 7 commits (FilterResult, base, Invoice migrate, Novelty, PettyCash, Refund, DI, validación).
- **PR3** mergea con 7 commits (5 policies + DI + validación).

El audit `docs/audits/permissions-audit-2026-05-11.md` queda con:

- **13** ✅ resueltos
- **1** 🟢 WONTFIX (PA-007) con criterio de reapertura
- **0** ⏳ pendientes

Verdicto global: ✅ **RESUELTO** (2026-05-12).

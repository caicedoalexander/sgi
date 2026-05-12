# PA-003 `$roleName` Cleanup — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminar el parámetro muerto `$roleName` de la API pública de `PipelineAuthorizationService` y de todos los wrappers que sólo lo reenviaban (services pipeline + policy + controllers + templates).

**Architecture:** Cleanup mecánico bottom-up sobre una sola rama, **un único commit atómico al final**. Sin worktree dedicado (proyecto no lo usa). Los commits intermedios se evitan porque entre el cambio del leaf y la actualización del último caller el código produce `ArgumentCountError` (PHP 8.4 strict): bisect-granularity se sacrifica deliberadamente a cambio de la consistencia semántica que el spec exige ("una sola PR atómica"). El plan se ejecuta linealmente; sólo el último task hace `git add` + `commit` + `push`.

**Tech Stack:** PHP 8.4, CakePHP 5.3, `declare(strict_types=1)`, `composer cs-check` (CakePHP coding standard). Verificación manual (proyecto sin tests automatizados — ver CLAUDE.md "Testing Policy").

**Spec de referencia:** `docs/superpowers/specs/2026-05-11-permissions-pa-003-roleName-cleanup-design.md`

---

## File Structure

| Archivo | Rol | Tipo de cambio |
|---|---|---|
| `src/Service/PipelineAuthorizationService.php` | Leaf (fuente de verdad) | Borrar 2º parámetro de `canOperate` y `getOperableSteps`; limpiar docblocks |
| `src/Service/InvoiceFieldAccessPolicy.php` | Wrapper | Borrar `$roleName` de 4 firmas + reenvíos internos |
| `src/Service/InvoiceTransitionValidator.php` | Wrapper | Borrar `$roleName` de `filterErrorsForRole` + llamada interna a `canOperate` |
| `src/Service/InvoicePipelineService.php` | Wrapper compuesto | Borrar `$roleName` de 9 firmas públicas + reenvíos |
| `src/Service/NoveltyService.php` | Wrapper | Borrar `$roleName` de 4 firmas + reenvíos |
| `src/Service/PettyCashService.php` | Wrapper | Borrar `$roleName` de 2 firmas (`canAdvance`, `canRegress`) + reenvíos a leaf en 6 sitios |
| `src/Service/RefundService.php` | Consumer del leaf | Sólo elimina argumento `''` posicional en 3 call-sites internos (firmas ya limpias) |
| `src/Service/RefundPaymentService.php` | Consumer del leaf | Sólo elimina argumento `''` o `$roleName` en 3 call-sites internos |
| `src/Service/PaymentSchedulingService.php` | Wrapper | Borrar `$roleName` de 3 firmas + reenvíos |
| `src/Service/LiquidationDocPaymentService.php` | Consumer del leaf | Eliminar argumento al llamar `canOperate` |
| `src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php` | Policy | Borrar `$roleName` de 13 métodos públicos + helper privado `_canOperate` |
| `src/Controller/AdvancesController.php` | Caller | Eliminar 14 asignaciones `$roleName = …` + argumentos al policy + a `pipelineService->advance/regress` |
| `src/Controller/InvoicesController.php` | Caller | Actualizar 3 call-sites a `pipelineAuth->canOperate` + cualquier call a `pipelineService->canAdvance/canRegress` |
| `src/Controller/InvoicePaymentsController.php` | Caller | Actualizar 6 call-sites |
| `src/Controller/PettyCashRecordsController.php` | Caller | Actualizar 4 call-sites + posibles llamadas a `pettyCash->canAdvance/canRegress` |
| `src/Controller/PaymentSchedulingsController.php` | Caller | Actualizar 2 call-sites + llamadas a `paymentScheduling->canAdvance/canReject/canRegress` |
| `src/Controller/NoveltyLiquidationDocsController.php` | Caller | Actualizar 3 call-sites |
| `src/Controller/LiquidationDocPaymentsController.php` | Caller | Actualizar 4 call-sites |
| `src/Controller/RefundsController.php` | Caller | Helper privado `_canOperateRefundStep` + 6 call-sites |
| `src/Controller/EmployeeNoveltiesController.php` | Caller potencial | Verificar y limpiar si llama a `NoveltyService::canAdvanceFromStatus`/`getEditableFields`/`getVisibleSections` |
| `templates/**` | Caller potencial | `grep` para detectar llamadas directas a services pipeline; modificar si aparece |

**Decisión:** los call-sites pueden propagar `$roleName` por dos vías: (a) variable local `$roleName = $this->_getUserRoleName($user)` que se pasa como argumento, o (b) literal `''` (string vacío) cuando el caller ya sabe que el parámetro es ignorado (patrón presente en `RefundService`). Ambos casos se eliminan por igual.

---

### Task 0: Setup branch + inventario verificable

**Files:**
- Run: `git checkout -b refactor/pa-003-rolename-cleanup`
- Create: `/tmp/pa003-inventory.txt` (artefacto temporal, no commiteado)

- [ ] **Step 0.1: Verificar que la rama main está limpia y sincronizada**

Run:
```bash
git status
git pull --ff-only origin main
```

Expected: `nothing to commit, working tree clean` y `Already up to date.`

- [ ] **Step 0.2: Crear rama de trabajo**

Run:
```bash
git checkout -b refactor/pa-003-rolename-cleanup
```

Expected: `Switched to a new branch 'refactor/pa-003-rolename-cleanup'`

- [ ] **Step 0.3: Generar inventario reproducible de call-sites con `$roleName` para verificación pre/post**

Run:
```bash
mkdir -p /tmp
{
  echo "=== Firmas que declaran \$roleName ==="
  grep -nE "function [a-zA-Z]+\([^)]*\\\$roleName" src/Service/PipelineAuthorizationService.php src/Service/InvoicePipelineService.php src/Service/InvoiceFieldAccessPolicy.php src/Service/InvoiceTransitionValidator.php src/Service/NoveltyService.php src/Service/PettyCashService.php src/Service/RefundService.php src/Service/RefundPaymentService.php src/Service/PaymentSchedulingService.php src/Service/LiquidationDocPaymentService.php src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php 2>/dev/null
  echo ""
  echo "=== Llamadas a canOperate/getOperableSteps ==="
  grep -rn "pipelineAuth->canOperate\|pipelineAuth->getOperableSteps" src/ templates/ 2>/dev/null
  echo ""
  echo "=== Llamadas a actionPolicy->canX ==="
  grep -rn "actionPolicy->can" src/Controller/ 2>/dev/null
  echo ""
  echo "=== Asignaciones \$roleName = ... ==="
  grep -rn '\$roleName = ' src/ templates/ 2>/dev/null
} > /tmp/pa003-inventory.txt

wc -l /tmp/pa003-inventory.txt
```

Expected: archivo `/tmp/pa003-inventory.txt` con ~120-140 líneas. Sirve como baseline para validar el final.

---

### Task 1: Leaf — `PipelineAuthorizationService`

**Files:**
- Modify: `src/Service/PipelineAuthorizationService.php:23-35` (docblock + firma `canOperate`)
- Modify: `src/Service/PipelineAuthorizationService.php:37-52` (docblock + firma `getOperableSteps`)

- [ ] **Step 1.1: Borrar `$roleName` de la firma de `canOperate` y limpiar docblock**

Edit `src/Service/PipelineAuthorizationService.php`:

```php
    /**
     * @param int $roleId
     * @param string $roleName Conservado para compat con callers; no se consulta tras cleanup 2026-05-02.
     * @param string $pipeline
     * @param string $step
     * @return bool true si el rol puede operar el paso del pipeline.
     */
    public function canOperate(int $roleId, string $roleName, string $pipeline, string $step): bool
    {
        $perms = $this->_loadForRole($roleId);

        return (bool)($perms[$pipeline][$step] ?? false);
    }
```

Replace with:

```php
    /**
     * @param int $roleId
     * @param string $pipeline
     * @param string $step
     * @return bool true si el rol puede operar el paso del pipeline.
     */
    public function canOperate(int $roleId, string $pipeline, string $step): bool
    {
        $perms = $this->_loadForRole($roleId);

        return (bool)($perms[$pipeline][$step] ?? false);
    }
```

- [ ] **Step 1.2: Borrar `$roleName` de la firma de `getOperableSteps` y limpiar docblock**

Edit `src/Service/PipelineAuthorizationService.php`:

```php
    /**
     * @param int $roleId
     * @param string $roleName Conservado para compat con callers; no se consulta tras cleanup 2026-05-02.
     * @param string $pipeline
     * @return array<string> Pasos del pipeline donde el rol puede operar.
     */
    public function getOperableSteps(int $roleId, string $roleName, string $pipeline): array
    {
        $perms = $this->_loadForRole($roleId);
        $stepsForPipeline = $perms[$pipeline] ?? [];

        return array_values(array_filter(
            PipelineStepConstants::STEPS_BY_PIPELINE[$pipeline] ?? [],
            static fn(string $step): bool => !empty($stepsForPipeline[$step]),
        ));
    }
```

Replace with:

```php
    /**
     * @param int $roleId
     * @param string $pipeline
     * @return array<string> Pasos del pipeline donde el rol puede operar.
     */
    public function getOperableSteps(int $roleId, string $pipeline): array
    {
        $perms = $this->_loadForRole($roleId);
        $stepsForPipeline = $perms[$pipeline] ?? [];

        return array_values(array_filter(
            PipelineStepConstants::STEPS_BY_PIPELINE[$pipeline] ?? [],
            static fn(string $step): bool => !empty($stepsForPipeline[$step]),
        ));
    }
```

- [ ] **Step 1.3: Verificar grep en el archivo leaf**

Run:
```bash
grep -n 'roleName' src/Service/PipelineAuthorizationService.php
```

Expected: 0 resultados.

---

### Task 2: Wrapper — `InvoiceFieldAccessPolicy`

**Files:**
- Modify: `src/Service/InvoiceFieldAccessPolicy.php:71-152` (4 métodos públicos + 2 llamadas internas al leaf)

- [ ] **Step 2.1: Eliminar `$roleName` de `getEditableFields`**

Edit `src/Service/InvoiceFieldAccessPolicy.php` (línea 71):

```php
    public function getEditableFields(int $roleId, string $roleName, string $status): array
```

Replace with:

```php
    public function getEditableFields(int $roleId, string $status): array
```

Después busca cualquier uso de `$roleName` dentro del cuerpo del método y elimínalo (o reemplaza la llamada `$this->pipelineAuth->getOperableSteps($roleId, $roleName, …)` por `$this->pipelineAuth->getOperableSteps($roleId, …)`).

- [ ] **Step 2.2: Eliminar `$roleName` de `getVisibleSections`**

Edit `src/Service/InvoiceFieldAccessPolicy.php` (línea 96):

```php
    public function getVisibleSections(int $roleId, string $roleName, string $status): array
```

Replace with:

```php
    public function getVisibleSections(int $roleId, string $status): array
```

Actualizar la llamada interna a `$this->pipelineAuth->getOperableSteps($roleId, $roleName, …)` → `$this->pipelineAuth->getOperableSteps($roleId, …)` (línea ~104).

- [ ] **Step 2.3: Eliminar `$roleName` de `getCollapsibleSections`**

Edit `src/Service/InvoiceFieldAccessPolicy.php` (línea 125):

```php
    public function getCollapsibleSections(int $roleId, string $roleName, string $status): array
```

Replace with:

```php
    public function getCollapsibleSections(int $roleId, string $status): array
```

Limpiar cualquier `$roleName` interno.

- [ ] **Step 2.4: Eliminar `$roleName` de `filterEntityData`**

Edit `src/Service/InvoiceFieldAccessPolicy.php` (línea 139):

```php
    public function filterEntityData(array $data, int $roleId, string $roleName, string $status): array
```

Replace with:

```php
    public function filterEntityData(array $data, int $roleId, string $status): array
```

Si el cuerpo invoca `$this->getEditableFields($roleId, $roleName, $status)`, ajustar a `$this->getEditableFields($roleId, $status)`.

- [ ] **Step 2.5: Verificación**

Run:
```bash
grep -n 'roleName' src/Service/InvoiceFieldAccessPolicy.php
```

Expected: 0 resultados.

---

### Task 3: Wrapper — `InvoiceTransitionValidator`

**Files:**
- Modify: `src/Service/InvoiceTransitionValidator.php:95-110` (firma `filterErrorsForRole` + llamada interna a `canOperate`)

- [ ] **Step 3.1: Eliminar `$roleName` de `filterErrorsForRole`**

Edit `src/Service/InvoiceTransitionValidator.php` (línea 95):

```php
    public function filterErrorsForRole(array $errors, array $rules, int $roleId, string $roleName, string $status): array
```

Replace with:

```php
    public function filterErrorsForRole(array $errors, array $rules, int $roleId, string $status): array
```

Actualizar la llamada interna en línea ~98 (`$this->pipelineAuth->canOperate($roleId, $roleName, …)` → `$this->pipelineAuth->canOperate($roleId, …)`).

- [ ] **Step 3.2: Verificación**

Run:
```bash
grep -n 'roleName' src/Service/InvoiceTransitionValidator.php
```

Expected: 0 resultados.

---

### Task 4: Wrapper compuesto — `InvoicePipelineService`

**Files:**
- Modify: `src/Service/InvoicePipelineService.php` (9 firmas + propagaciones internas)

Lista de métodos a modificar (líneas indicativas, verificar con `grep -n "function.*\$roleName" src/Service/InvoicePipelineService.php`):

- `getEditableFields` (línea 55)
- `getVisibleSections` (línea 60)
- `getCollapsibleSections` (línea 67)
- `filterAdvanceErrorsForRole` (línea 116)
- `canAdvance` (línea 121)
- `filterEntityData` (línea 155)
- `canRegress` (línea 177)
- `saveAndAdvance` (línea ~196)
- `advance` (línea 304)
- `regress` (línea 343)

- [ ] **Step 4.1: Modificar las 10 firmas públicas para eliminar `$roleName`**

Para cada método de la lista, eliminar el parámetro `string $roleName,` de la firma y todas las referencias internas al pasarlo a métodos delegados:

Ejemplo concreto (`canAdvance`, línea 121):

```php
    public function canAdvance(int $roleId, string $roleName, string $currentStatus, ?string $documentType = null): bool
    {
        if ($this->getNextStatus($currentStatus, $documentType) === null) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_INVOICES,
            $currentStatus,
        );
    }
```

Replace with:

```php
    public function canAdvance(int $roleId, string $currentStatus, ?string $documentType = null): bool
    {
        if ($this->getNextStatus($currentStatus, $documentType) === null) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            PipelineStepConstants::PIPELINE_INVOICES,
            $currentStatus,
        );
    }
```

Aplicar el mismo patrón a:

- `getEditableFields`: firma + llamada interna a `$this->fieldPolicy->getEditableFields($roleId, $roleName, $status)` → `$this->fieldPolicy->getEditableFields($roleId, $status)`.
- `getVisibleSections`: firma + llamada interna a `$this->fieldPolicy->getVisibleSections($roleId, $roleName, $status)` → `$this->fieldPolicy->getVisibleSections($roleId, $status)`.
- `getCollapsibleSections`: firma + llamada interna análoga.
- `filterAdvanceErrorsForRole`: firma + llamada a `$this->transitionValidator->filterErrorsForRole($errors, $rules, $roleId, $roleName, $status)` → `… $roleId, $status)`.
- `filterEntityData`: firma + llamada a `$this->fieldPolicy->filterEntityData($data, $roleId, $roleName, $status)` → `… $roleId, $status)`.
- `canRegress`: firma + llamada a `$this->pipelineAuth->canOperate($roleId, $roleName, …)` → `… $roleId, …)`.
- `saveAndAdvance`: firma `(Invoice $invoice, array $data, int $roleId, string $roleName, int $userId, ?string $baseUrl = null)` → `(Invoice $invoice, array $data, int $roleId, int $userId, ?string $baseUrl = null)`. En el cuerpo eliminar uso de `$roleName` en `filterEntityData` y `canAdvance`.
- `advance`: firma `(Invoice $invoice, int $roleId, string $roleName, int $userId)` → `(Invoice $invoice, int $roleId, int $userId)`. Limpiar llamada a `canAdvance($roleId, $roleName, …)`.
- `regress`: firma `(Invoice $invoice, int $roleId, string $roleName, int $userId, string $reason)` → `(Invoice $invoice, int $roleId, int $userId, string $reason)`. Limpiar llamada a `canRegress($roleId, $roleName, $currentStatus)`.

- [ ] **Step 4.2: Verificación**

Run:
```bash
grep -n 'roleName' src/Service/InvoicePipelineService.php
```

Expected: 0 resultados.

---

### Task 5: Wrapper — `NoveltyService`

**Files:**
- Modify: `src/Service/NoveltyService.php` (4 firmas + propagaciones)

Métodos a modificar:
- `getEditableFields` (línea 419)
- `getVisibleSections` (línea 438)
- `canAdvanceFromStatus` (línea 457)
- `filterEntityData` (línea 470)

- [ ] **Step 5.1: Modificar las 4 firmas y limpiar reenvíos al leaf**

Aplicar para cada método:

Ejemplo (`canAdvanceFromStatus`, línea 457):

```php
    public function canAdvanceFromStatus(int $roleId, string $roleName, string $status): bool
    {
        // body that calls $this->pipelineAuth->canOperate($roleId, $roleName, PipelineStepConstants::PIPELINE_NOVELTIES, $status)
    }
```

Replace with:

```php
    public function canAdvanceFromStatus(int $roleId, string $status): bool
    {
        // body that calls $this->pipelineAuth->canOperate($roleId, PipelineStepConstants::PIPELINE_NOVELTIES, $status)
    }
```

Aplicar el mismo patrón a `getEditableFields`, `getVisibleSections`, `filterEntityData`. En cada uno: eliminar `$roleName` de la firma + del docblock + de la llamada interna a `pipelineAuth->canOperate`/`getOperableSteps`.

Verificar también que llamadas internas a `getOperableSteps` (líneas 397, 409, 440) no propaguen `$roleName` que ya no existe.

- [ ] **Step 5.2: Verificación**

Run:
```bash
grep -n 'roleName' src/Service/NoveltyService.php
```

Expected: 0 resultados.

---

### Task 6: Wrapper — `PettyCashService`

**Files:**
- Modify: `src/Service/PettyCashService.php` (2 firmas públicas + 6 llamadas al leaf en líneas 64, 247, 479, 555, 670, 731)

Métodos con firma `$roleName`:
- `canAdvance` (línea 235)
- `canRegress` (línea 725)

- [ ] **Step 6.1: Eliminar `$roleName` de `canAdvance`**

Edit `src/Service/PettyCashService.php` (línea 235):

```php
    public function canAdvance(int $roleId, string $roleName, string $currentStatus): bool
```

Replace with:

```php
    public function canAdvance(int $roleId, string $currentStatus): bool
```

En el cuerpo (línea 247) actualizar `$this->pipelineAuth->canOperate($roleId, $roleName, …)` → `$this->pipelineAuth->canOperate($roleId, …)`.

- [ ] **Step 6.2: Eliminar `$roleName` de `canRegress`**

Edit `src/Service/PettyCashService.php` (línea 725):

```php
    public function canRegress(int $roleId, string $roleName, string $currentStatus): bool
```

Replace with:

```php
    public function canRegress(int $roleId, string $currentStatus): bool
```

En el cuerpo (línea 731) actualizar la llamada al leaf.

- [ ] **Step 6.3: Limpiar las 4 llamadas restantes al leaf**

Revisar líneas 64, 479, 555, 670: cada una invoca `$this->pipelineAuth->canOperate(…)` o `getOperableSteps(…)`. Eliminar el argumento `$roleName` (o `''`) que ocupa la segunda posición.

Ejemplo (línea 64):

```php
        return $this->pipelineAuth->getOperableSteps(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PETTY_CASH,
        );
```

Replace with:

```php
        return $this->pipelineAuth->getOperableSteps(
            $roleId,
            PipelineStepConstants::PIPELINE_PETTY_CASH,
        );
```

Si en ese contexto `$roleName` no se usa para otra cosa, eliminar también su asignación.

- [ ] **Step 6.4: Verificación**

Run:
```bash
grep -n 'roleName' src/Service/PettyCashService.php
```

Expected: 0 resultados.

---

### Task 7: Consumers del leaf — `RefundService` + `RefundPaymentService`

**Files:**
- Modify: `src/Service/RefundService.php` (3 call-sites: líneas 55, 133, 364 — usan literal `''` posicional)
- Modify: `src/Service/RefundPaymentService.php` (3 call-sites: líneas 59, 194, 370)

Las firmas públicas en estos services ya están limpias (no declaran `$roleName`). Sólo hay que eliminar el argumento posicional `''` (o `$roleName` si lo hubiera) en las llamadas al leaf.

- [ ] **Step 7.1: Limpiar las 3 llamadas en `RefundService`**

Edit `src/Service/RefundService.php`:

Línea ~55 (`getVisibleStatuses`):
```php
        return $this->pipelineAuth->getOperableSteps(
            $roleId,
            '',
            PipelineStepConstants::PIPELINE_REFUNDS,
        );
```

Replace with:
```php
        return $this->pipelineAuth->getOperableSteps(
            $roleId,
            PipelineStepConstants::PIPELINE_REFUNDS,
        );
```

Línea ~133 (`advanceStatus`):
```php
            !$this->pipelineAuth->canOperate(
                $roleId,
                '',
                PipelineStepConstants::PIPELINE_REFUNDS,
                $currentStatus,
            )
```

Replace with:
```php
            !$this->pipelineAuth->canOperate(
                $roleId,
                PipelineStepConstants::PIPELINE_REFUNDS,
                $currentStatus,
            )
```

Línea ~364 (`canRegress`): mismo patrón — eliminar el `''` posicional.

- [ ] **Step 7.2: Limpiar las 3 llamadas en `RefundPaymentService`**

Edit `src/Service/RefundPaymentService.php` en las líneas 59, 194, 370. Patrón idéntico:

```php
            !$this->pipelineAuth->canOperate(
                $roleId,
                $roleName,  // o '',
                PipelineStepConstants::PIPELINE_REFUNDS,
                $someStatus,
            )
```

Replace with:

```php
            !$this->pipelineAuth->canOperate(
                $roleId,
                PipelineStepConstants::PIPELINE_REFUNDS,
                $someStatus,
            )
```

Si la variable `$roleName` queda huérfana (asignada y no usada después de este cambio), eliminar también su asignación.

- [ ] **Step 7.3: Verificación**

Run:
```bash
grep -n 'roleName\|->canOperate\|->getOperableSteps' src/Service/RefundService.php src/Service/RefundPaymentService.php
```

Expected: las llamadas a `canOperate`/`getOperableSteps` ya no muestran un argumento `''` ni `$roleName` en segunda posición; ninguna mención huérfana a `roleName`.

---

### Task 8: Wrapper — `PaymentSchedulingService`

**Files:**
- Modify: `src/Service/PaymentSchedulingService.php` (3 firmas en líneas 56, 70, 84 + llamadas al leaf en 39, 62, 76, 90)

- [ ] **Step 8.1: Eliminar `$roleName` de `canAdvance`, `canReject`, `canRegress`**

Edit `src/Service/PaymentSchedulingService.php`:

```php
    public function canAdvance(int $roleId, string $roleName, string $currentStatus): bool
    public function canReject(int $roleId, string $roleName, string $currentStatus): bool
    public function canRegress(int $roleId, string $roleName, string $currentStatus): bool
```

Replace with:

```php
    public function canAdvance(int $roleId, string $currentStatus): bool
    public function canReject(int $roleId, string $currentStatus): bool
    public function canRegress(int $roleId, string $currentStatus): bool
```

- [ ] **Step 8.2: Limpiar las 4 llamadas al leaf**

Líneas 39, 62, 76, 90: en cada una eliminar el argumento posicional 2 (`$roleName` o `''`).

- [ ] **Step 8.3: Verificación**

Run:
```bash
grep -n 'roleName' src/Service/PaymentSchedulingService.php
```

Expected: 0 resultados.

---

### Task 9: Consumer del leaf — `LiquidationDocPaymentService`

**Files:**
- Modify: `src/Service/LiquidationDocPaymentService.php` (todas las llamadas a `pipelineAuth->canOperate`)

- [ ] **Step 9.1: Identificar y limpiar las llamadas al leaf**

Run:
```bash
grep -n "pipelineAuth->canOperate\|pipelineAuth->getOperableSteps\|roleName" src/Service/LiquidationDocPaymentService.php
```

Para cada llamada encontrada, eliminar el argumento posicional 2 (`$roleName` o `''`). Si `$roleName` queda huérfano en el método contenedor, eliminar también su asignación.

- [ ] **Step 9.2: Verificación**

Run:
```bash
grep -n 'roleName' src/Service/LiquidationDocPaymentService.php
```

Expected: 0 resultados (o solo en contextos legítimos no relacionados con pipeline).

---

### Task 10: Policy — `AdvanceLegalizationActionPolicy`

**Files:**
- Modify: `src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php` (13 firmas públicas + helper privado)

- [ ] **Step 10.1: Reemplazar el archivo completo con la versión limpia**

Edit `src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php`. El archivo actual tiene 100 líneas; reemplaza el cuerpo de la clase (desde el `__construct` hasta el cierre `}`) con:

```php
    public function __construct(
        private PipelineAuthorizationService $pipelineAuth,
    ) {
    }

    public function canLinkInvoices(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canLinkInvoices();
    }

    public function canUnlinkInvoice(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canUnlinkInvoice();
    }

    public function canUploadRelationDocument(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canUploadRelationDocument();
    }

    public function canMoveToRevision(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canMoveToRevision();
    }

    public function canMarkSigned(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canMarkSigned();
    }

    public function canReturnToValidacion(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canReturnToValidacion();
    }

    public function canMarkExact(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canMarkExact();
    }

    public function canRegisterShortage(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canRegisterShortage();
    }

    public function canRegisterSurplus(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canRegisterSurplus();
    }

    public function canConfirmShortage(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canConfirmShortage();
    }

    public function canRegisterRefund(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canRegisterRefund();
    }

    public function canAuthorizeRefundPayment(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canAuthorizeRefundPayment();
    }

    public function canConfirmRefundPayment(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canConfirmRefundPayment();
    }

    private function _canOperate(int $roleId, string $step): bool
    {
        return $this->pipelineAuth->canOperate(
            $roleId,
            PipelineStepConstants::PIPELINE_LEGALIZATIONS,
            $step,
        );
    }
```

(Mantener el docblock de clase, imports, namespace y la firma `final class AdvanceLegalizationActionPolicy` sin cambios.)

- [ ] **Step 10.2: Verificación**

Run:
```bash
grep -n 'roleName' src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php
```

Expected: 0 resultados.

---

### Task 11: Callers — `AdvancesController`

**Files:**
- Modify: `src/Controller/AdvancesController.php` (~14 call-sites al policy + invocaciones a `pipelineService->advance/regress` + asignaciones huérfanas de `$roleName`)

- [ ] **Step 11.1: Eliminar todas las llamadas que pasan `$roleName` al `actionPolicy`**

Por cada call-site (líneas 355, 425, 454, 478, 528, 552, 576, 601, 625, 653, 699, 727, 758), el patrón actual es:

```php
        $roleName = $this->_getUserRoleName($this->_getCurrentUser());
        if (!$this->actionPolicy->canLinkInvoices($leg, (int)$this->_getCurrentUser()->id, $roleName)) {
            // …
        }
```

Reemplazar por (eliminando la asignación de `$roleName` Y el argumento de la llamada):

```php
        if (!$this->actionPolicy->canLinkInvoices($leg, (int)$this->_getCurrentUser()->id)) {
            // …
        }
```

Aplicar el mismo patrón a las 13 acciones (`canLinkInvoices`, `canUnlinkInvoice`, `canUploadRelationDocument`, `canMoveToRevision`, `canMarkSigned`, `canReturnToValidacion`, `canMarkExact`, `canRegisterShortage`, `canRegisterSurplus`, `canConfirmShortage`, `canRegisterRefund`, `canAuthorizeRefundPayment`, `canConfirmRefundPayment`).

- [ ] **Step 11.2: Eliminar la propagación de `$roleName` a `pipelineService->advance/regress`**

Línea ~289-317 (búsqueda del bloque que llama a `pipelineService->advance(...)` o `regress(...)`):

```php
        $roleName = $user->role->name ?? '';
        // …
        $result = $this->invoicePipelineService->advance(
            $invoice,
            (int)$user->role_id,
            $roleName,
            (int)$user->id,
        );
```

Replace with:

```php
        $result = $this->invoicePipelineService->advance(
            $invoice,
            (int)$user->role_id,
            (int)$user->id,
        );
```

Igual para `regress`: borrar `$roleName` argumento.

Si tras estas eliminaciones `$user->role->name` o `_getUserRoleName(...)` no se usan para nada más en el método contenedor, eliminar también la asignación.

- [ ] **Step 11.3: Verificación**

Run:
```bash
grep -n 'roleName' src/Controller/AdvancesController.php
```

Expected: 0 resultados (o si quedan, validar que no son llamadas a métodos ya limpios; un caso legítimo aceptable sería un mensaje de error que muestre el nombre del rol — improbable en este controller, pero verificar caso por caso).

---

### Task 12: Callers — `InvoicesController`

**Files:**
- Modify: `src/Controller/InvoicesController.php` (3 call-sites + posibles llamadas a `pipelineService->canAdvance/canRegress/saveAndAdvance/advance/regress`)

- [ ] **Step 12.1: Identificar todas las llamadas afectadas**

Run:
```bash
grep -nE "pipelineAuth->canOperate|pipelineAuth->getOperableSteps|pipelineService->(canAdvance|canRegress|saveAndAdvance|advance|regress|filterAdvanceErrorsForRole|filterEntityData|getEditableFields|getVisibleSections|getCollapsibleSections)|fieldPolicy->" src/Controller/InvoicesController.php
```

Para cada resultado, eliminar el argumento `$roleName` (variable o literal `''`) en la posición correspondiente.

- [ ] **Step 12.2: Eliminar asignaciones huérfanas de `$roleName`**

Run:
```bash
grep -n 'roleName = ' src/Controller/InvoicesController.php
```

Para cada asignación, verificar que `$roleName` no se usa en el método después del cambio. Si no se usa, eliminar la asignación.

- [ ] **Step 12.3: Verificación**

Run:
```bash
grep -n 'roleName' src/Controller/InvoicesController.php
```

Expected: 0 resultados o solo casos legítimos (ej. mensaje flash que muestra el nombre del rol).

---

### Task 13: Callers — `InvoicePaymentsController`

**Files:**
- Modify: `src/Controller/InvoicePaymentsController.php` (6 call-sites en líneas 74, 121, 167, 208, 246, 291)

- [ ] **Step 13.1: Actualizar las 6 llamadas a `pipelineAuth->canOperate`**

Para cada línea, eliminar el argumento posicional 2 (`$roleName` o `''`).

Ejemplo (línea 74):

```php
                $this->pipelineAuth->canOperate(
                    $roleId,
                    $roleName,
                    PipelineStepConstants::PIPELINE_INVOICES,
                    $invoice->pipeline_status,
                )
```

Replace with:

```php
                $this->pipelineAuth->canOperate(
                    $roleId,
                    PipelineStepConstants::PIPELINE_INVOICES,
                    $invoice->pipeline_status,
                )
```

Aplicar al resto (121, 167, 208, 246, 291).

- [ ] **Step 13.2: Eliminar asignaciones huérfanas de `$roleName`**

Run:
```bash
grep -n 'roleName' src/Controller/InvoicePaymentsController.php
```

Para cada asignación local que ya no se usa, eliminar.

- [ ] **Step 13.3: Verificación**

Expected: `grep -n 'roleName' src/Controller/InvoicePaymentsController.php` → 0 resultados o solo usos legítimos.

---

### Task 14: Callers — `PettyCashRecordsController`

**Files:**
- Modify: `src/Controller/PettyCashRecordsController.php` (4 call-sites en líneas 325, 331, 337, 492 + posibles llamadas a `pettyCashService->canAdvance/canRegress`)

- [ ] **Step 14.1: Actualizar las 4 llamadas a `pipelineAuth->canOperate`**

Patrón:

```php
        $canRegisterPayment = $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PETTY_CASH,
            $someStatus,
        );
```

Replace with:

```php
        $canRegisterPayment = $this->pipelineAuth->canOperate(
            $roleId,
            PipelineStepConstants::PIPELINE_PETTY_CASH,
            $someStatus,
        );
```

- [ ] **Step 14.2: Actualizar llamadas a `pettyCashService->canAdvance/canRegress`**

Run:
```bash
grep -n "pettyCashService->canAdvance\|pettyCashService->canRegress\|pettyCash->canAdvance\|pettyCash->canRegress" src/Controller/PettyCashRecordsController.php
```

Para cada resultado, eliminar el argumento `$roleName`.

- [ ] **Step 14.3: Eliminar asignaciones huérfanas**

Run:
```bash
grep -n 'roleName' src/Controller/PettyCashRecordsController.php
```

Eliminar las asignaciones que ya no se usan.

- [ ] **Step 14.4: Verificación**

Expected: 0 resultados de `grep -n 'roleName' …` o sólo usos legítimos.

---

### Task 15: Callers — `PaymentSchedulingsController`

**Files:**
- Modify: `src/Controller/PaymentSchedulingsController.php` (2 call-sites en líneas 187, 342 + llamadas a `paymentScheduling->canAdvance/canReject/canRegress`)

- [ ] **Step 15.1: Actualizar llamadas al leaf y al service**

Run:
```bash
grep -n "pipelineAuth->\|paymentSchedulingService->canAdvance\|paymentSchedulingService->canReject\|paymentSchedulingService->canRegress" src/Controller/PaymentSchedulingsController.php
```

Para cada resultado, eliminar argumento `$roleName`.

- [ ] **Step 15.2: Eliminar asignaciones huérfanas y verificación**

Run:
```bash
grep -n 'roleName' src/Controller/PaymentSchedulingsController.php
```

Expected: 0 resultados o usos legítimos.

---

### Task 16: Callers — `NoveltyLiquidationDocsController`

**Files:**
- Modify: `src/Controller/NoveltyLiquidationDocsController.php` (3 call-sites en líneas 230, 236, 242)

- [ ] **Step 16.1: Actualizar las 3 llamadas a `pipelineAuth->canOperate`**

Patrón idéntico al de tasks anteriores: eliminar el argumento posicional 2.

- [ ] **Step 16.2: Eliminar asignaciones huérfanas y verificación**

Run:
```bash
grep -n 'roleName' src/Controller/NoveltyLiquidationDocsController.php
```

Expected: 0 resultados o sólo usos legítimos.

---

### Task 17: Callers — `LiquidationDocPaymentsController`

**Files:**
- Modify: `src/Controller/LiquidationDocPaymentsController.php` (4 call-sites en líneas 78, 118, 154, 190)

- [ ] **Step 17.1: Actualizar las 4 llamadas a `pipelineAuth->canOperate`**

Patrón uniforme: eliminar argumento posicional 2.

- [ ] **Step 17.2: Eliminar asignaciones huérfanas y verificación**

Run:
```bash
grep -n 'roleName' src/Controller/LiquidationDocPaymentsController.php
```

Expected: 0 resultados o sólo usos legítimos.

---

### Task 18: Callers — `RefundsController`

**Files:**
- Modify: `src/Controller/RefundsController.php` (helper privado `_canOperateRefundStep` línea 74-80 + 6 call-sites adicionales)

- [ ] **Step 18.1: Verificar firma de `_canOperateRefundStep`**

Run:
```bash
grep -n "_canOperateRefundStep\|pipelineAuth->" src/Controller/RefundsController.php
```

Examinar el helper privado: si su firma ya es `_canOperateRefundStep(string $step)` (sin `$roleName`), sólo hay que limpiar las llamadas internas al leaf. Si declara `$roleName`, eliminarlo.

- [ ] **Step 18.2: Actualizar las 6 llamadas adicionales a `pipelineAuth->canOperate`**

Líneas ~473, 479, 485, 617 y otras. Patrón: eliminar argumento posicional 2.

- [ ] **Step 18.3: Eliminar asignaciones huérfanas y verificación**

Run:
```bash
grep -n 'roleName' src/Controller/RefundsController.php
```

Expected: 0 resultados o sólo usos legítimos.

---

### Task 19: Callers — `EmployeeNoveltiesController` y otros consumidores de `NoveltyService`

**Files:**
- Modify: `src/Controller/EmployeeNoveltiesController.php` (si llama a `noveltyService->canAdvanceFromStatus/getEditableFields/getVisibleSections/filterEntityData`)

- [ ] **Step 19.1: Identificar callers**

Run:
```bash
grep -rn "noveltyService->canAdvanceFromStatus\|noveltyService->getEditableFields\|noveltyService->getVisibleSections\|noveltyService->filterEntityData" src/Controller/ src/Service/ templates/
```

- [ ] **Step 19.2: Para cada caller, eliminar el argumento `$roleName`**

Aplicar el patrón estándar: borrar argumento + asignaciones huérfanas.

- [ ] **Step 19.3: Verificación**

Run:
```bash
grep -n 'roleName' src/Controller/EmployeeNoveltiesController.php
```

Expected: 0 resultados o sólo usos legítimos.

---

### Task 20: Templates — verificación de llamadas directas a services pipeline

**Files:**
- Modify: `templates/**/*.php` (si aparecen llamadas)

- [ ] **Step 20.1: Buscar llamadas a services pipeline en templates**

Run:
```bash
grep -rn "pipelineAuth->\|pipelineService->canAdvance\|pipelineService->canRegress\|pipelineService->getEditableFields\|pipelineService->getVisibleSections\|fieldPolicy->\|actionPolicy->can\|noveltyService->\|refundService->\|pettyCashService->\|paymentSchedulingService->" templates/
```

- [ ] **Step 20.2: Si hay resultados, eliminar el argumento `$roleName` posicional**

Para cada línea encontrada, aplicar el mismo patrón. Si no hay resultados, anotar en una línea de log: "templates/: sin call-sites directos".

- [ ] **Step 20.3: Verificación**

Run:
```bash
grep -rn 'roleName' templates/
```

Expected: 0 resultados (si los hubiera, verificar que no son llamadas a las funciones limpiadas; un `<?= h($roleName) ?>` en una vista de admin que muestra el nombre del rol es legítimo).

---

### Task 21: Verificación estática global

**Files:** ninguno (sólo comandos de verificación).

- [ ] **Step 21.1: Re-generar inventario y comparar contra el baseline**

Run:
```bash
{
  echo "=== Firmas que declaran \$roleName ==="
  grep -nE "function [a-zA-Z]+\([^)]*\\\$roleName" src/Service/PipelineAuthorizationService.php src/Service/InvoicePipelineService.php src/Service/InvoiceFieldAccessPolicy.php src/Service/InvoiceTransitionValidator.php src/Service/NoveltyService.php src/Service/PettyCashService.php src/Service/RefundService.php src/Service/RefundPaymentService.php src/Service/PaymentSchedulingService.php src/Service/LiquidationDocPaymentService.php src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php 2>/dev/null
  echo ""
  echo "=== Llamadas a canOperate/getOperableSteps con \$roleName ==="
  grep -rnE "(canOperate|getOperableSteps)\(.*\\\$roleName" src/ templates/ 2>/dev/null
} > /tmp/pa003-inventory-after.txt

cat /tmp/pa003-inventory-after.txt
```

Expected (criterios de validación pre-merge del spec):
1. La sección "Firmas que declaran `$roleName`" debe estar **vacía** (excepto, si aplica, métodos en `AuthorizationService` que NO se tocaron — verificar que el file path sea `AuthorizationService.php` y no algún wrapper pipeline).
2. La sección "Llamadas a canOperate/getOperableSteps con `$roleName`" debe estar **vacía**.

- [ ] **Step 21.2: Verificar que `AuthorizationService::isAllowed` sigue intacto**

Run:
```bash
grep -n "function isAllowed" src/Service/AuthorizationService.php
```

Expected: muestra `public function isAllowed(int $roleId, string $roleName, string $module, string $action): bool` — sin cambios.

- [ ] **Step 21.3: Code Style check**

Run:
```bash
composer cs-check
```

Expected: PASS, sin nuevos warnings ni errores. Si CS-fixer reporta espacios/comas residuales por argumentos eliminados, ejecutar `composer cs-fix` y revisar el diff.

- [ ] **Step 21.4: Boot del server**

Run:
```bash
php bin/cake server &
SERVER_PID=$!
sleep 3
curl -sI http://localhost:8765/ | head -1
kill $SERVER_PID 2>/dev/null
```

Expected: el `curl` devuelve un código HTTP (200, 302 redirect al login, etc.). Si hay `ArgumentCountError` o `TypeError`, aparecerán en `logs/error.log` — revisar y corregir el call-site faltante.

Run:
```bash
tail -50 logs/error.log
```

Expected: sin nuevas entradas de `ArgumentCountError` ni `TypeError` posteriores al inicio de este task.

---

### Task 22: Smoke tests manuales

**Files:** ninguno (validación manual en navegador).

Plan de smoke (definido en el spec, sección "Smoke por módulo"). Ejecutar como Alexander tras `php bin/cake server`. Marcar cada flujo:

- [ ] **Step 22.1: Facturas** — login como Tesorería, abrir factura en `tesoreria`, registrar pago, confirmar avance a `autorizacion_pago`. Login como Contador, autorizar el pago, confirmar avance a `verificacion_pago`.

- [ ] **Step 22.2: Anticipos** — login como Contabilidad o Tesorería, abrir una legalización de anticipo, ejecutar `linkInvoices`, `markSigned`, `markExact` según el estado actual. Confirmar que las acciones se ejecutan sin error y que los botones siguen visibles/ocultos según el rol.

- [ ] **Step 22.3: Reintegros** — login como Tesorería, abrir un reintegro en `agrupacion`, avanzar a `contabilidad`, registrar un pago.

- [ ] **Step 22.4: Caja Menor** — login como Tesorería, avanzar un registro de `tesoreria` a `autorizacion_pago`.

- [ ] **Step 22.5: Novedades** — login como Auxiliar de Personal, abrir una novedad, avanzar, abrir el `edit` con otro rol y verificar que las secciones visibles y campos editables son idénticos al pre-merge.

- [ ] **Step 22.6: Payment Schedulings** — login como Coordinador Administrativo y Financiero, avanzar un scheduling de `borrador` a `tesoreria`, y rechazar otro.

- [ ] **Step 22.7: Sidebar** — login como cada rol probado arriba; verificar que los badge counters siguen apareciendo y son consistentes con el pre-merge.

- [ ] **Step 22.8: Roles** — navegar a `/roles/edit/{id}` con un rol existente y a `/roles/add`; ambos deben cargar la matriz de pipeline sin error.

- [ ] **Step 22.9: Logs limpios**

Run:
```bash
tail -100 logs/error.log
```

Expected: sin nuevos errores tras los smoke tests.

---

### Task 23: Commit atómico + push + PR

**Files:** todos los archivos modificados.

- [ ] **Step 23.1: Revisar el diff completo antes de commit**

Run:
```bash
git status
git diff --stat
```

Expected: ~20 archivos modificados, balance LoC negativo (~-70 líneas netas según el spec).

- [ ] **Step 23.2: Stage y commit atómico**

Run:
```bash
git add src/Service/PipelineAuthorizationService.php \
        src/Service/InvoicePipelineService.php \
        src/Service/InvoiceFieldAccessPolicy.php \
        src/Service/InvoiceTransitionValidator.php \
        src/Service/NoveltyService.php \
        src/Service/PettyCashService.php \
        src/Service/RefundService.php \
        src/Service/RefundPaymentService.php \
        src/Service/PaymentSchedulingService.php \
        src/Service/LiquidationDocPaymentService.php \
        src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php \
        src/Controller/AdvancesController.php \
        src/Controller/InvoicesController.php \
        src/Controller/InvoicePaymentsController.php \
        src/Controller/PettyCashRecordsController.php \
        src/Controller/PaymentSchedulingsController.php \
        src/Controller/NoveltyLiquidationDocsController.php \
        src/Controller/LiquidationDocPaymentsController.php \
        src/Controller/RefundsController.php

# Templates y EmployeeNoveltiesController solo si fueron modificados
git add -u src/Controller/EmployeeNoveltiesController.php templates/ 2>/dev/null || true

git status
```

Expected: todos los cambios staged; nada en `Untracked files`.

- [ ] **Step 23.3: Crear el commit**

Run:
```bash
git commit -m "$(cat <<'EOF'
refactor(permissions): eliminar parametro muerto $roleName de API pipeline (PA-003)

Cleanup mecanico bottom-up. Elimina `string $roleName` de:
- `PipelineAuthorizationService::canOperate` y `::getOperableSteps`
- Wrappers en services pipeline (Invoice, Novelty, PettyCash, PaymentScheduling)
- 13 metodos publicos de `AdvanceLegalizationActionPolicy` + helper privado
- Call-sites en controllers y consumers (`RefundService`, `RefundPaymentService`,
  `LiquidationDocPaymentService`) que pasaban `$roleName` o `''` posicional

No toca `AuthorizationService::isAllowed` (ese parametro si se consulta para
admin bypass) ni `_getUserRoleName` (sigue alimentando `isAllowed` y el sidebar).
Sin cambio funcional: misma autorizacion runtime contra la tabla
`pipeline_permissions`.

Spec: docs/superpowers/specs/2026-05-11-permissions-pa-003-roleName-cleanup-design.md
Hallazgo: PA-003 de docs/audits/permissions-audit-2026-05-11.md
EOF
)"
```

Expected: commit creado, hash impreso. Sin errores de pre-commit hooks.

- [ ] **Step 23.4: Push y apertura de PR (opcional, decisión del usuario)**

Si Alexander decide pushear y crear PR:

Run:
```bash
git push -u origin refactor/pa-003-rolename-cleanup
```

Luego (si gh está configurado):

```bash
gh pr create --title "refactor(permissions): PA-003 cleanup de \$roleName en API pipeline" --body "$(cat <<'EOF'
## Summary
- Elimina el parametro muerto `string \$roleName` de `PipelineAuthorizationService::canOperate` y `::getOperableSteps`.
- Cascada bottom-up por todos los wrappers (services pipeline + `AdvanceLegalizationActionPolicy` + controllers).
- Sin cambio funcional: misma autorizacion runtime, mismas filas de `pipeline_permissions`.

## Hallazgo
PA-003 de [`docs/audits/permissions-audit-2026-05-11.md`](docs/audits/permissions-audit-2026-05-11.md).

## Spec
[`docs/superpowers/specs/2026-05-11-permissions-pa-003-roleName-cleanup-design.md`](docs/superpowers/specs/2026-05-11-permissions-pa-003-roleName-cleanup-design.md).

## Test plan (manual)
- [x] Facturas: avance `tesoreria` -> `autorizacion_pago` -> `verificacion_pago`
- [x] Anticipos: `linkInvoices`, `markSigned`, `markExact`
- [x] Reintegros: avance `agrupacion` -> `contabilidad` + registro de pago
- [x] Caja Menor: avance `tesoreria` -> `autorizacion_pago`
- [x] Novedades: avance + revision de secciones visibles
- [x] PaymentSchedulings: avance `borrador` -> `tesoreria` + rechazo
- [x] Sidebar counters y `/roles/edit/{id}` intactos
- [x] `composer cs-check` PASS, `logs/error.log` sin nuevas entradas
EOF
)"
```

Expected: PR creada, URL impresa. No requerido — Alexander decide.

---

## Self-Review

**Spec coverage:**

| Spec requirement | Task |
|---|---|
| Leaf service signatures | Task 1 |
| `InvoiceFieldAccessPolicy` wrappers | Task 2 |
| `InvoiceTransitionValidator` wrapper | Task 3 |
| `InvoicePipelineService` wrappers | Task 4 |
| `NoveltyService` wrappers | Task 5 |
| `PettyCashService` wrappers | Task 6 |
| `RefundService` + `RefundPaymentService` call-sites | Task 7 |
| `PaymentSchedulingService` wrappers | Task 8 |
| `LiquidationDocPaymentService` call-sites | Task 9 |
| `AdvanceLegalizationActionPolicy` (13 + helper) | Task 10 |
| Controllers (`AdvancesController` + 7 más + `EmployeeNoveltiesController`) | Tasks 11-19 |
| Templates verification | Task 20 |
| Pre-merge static checks (greps + cs-check + boot) | Task 21 |
| Manual smoke (6 dominios + sidebar + roles) | Task 22 |
| Atomic commit + PR | Task 23 |
| NO tocar `AuthorizationService::isAllowed` | Task 21.2 verifica |
| NO introducir `UserContext` | (sin task asociado — exclusión por diseño, documentada en spec) |

Cobertura completa. Sin gaps.

**Placeholders:** ninguno. Cada step contiene comandos exactos o snippets de código.

**Type consistency:**

- `canOperate(int $roleId, string $pipeline, string $step): bool` — usado consistentemente desde Task 1 en adelante.
- `getOperableSteps(int $roleId, string $pipeline): array` — usado consistentemente.
- `AdvanceLegalizationActionPolicy::canX(AdvanceLegalization $leg, int $roleId): bool` — patrón unificado en Task 10 y referido en Task 11.
- `_canOperate(int $roleId, string $step): bool` — privado, consistente.

Sin desviaciones.

---

## Execution Handoff

Plan completo y guardado en `docs/superpowers/plans/2026-05-11-permissions-pa-003-roleName-cleanup.md`. Dos opciones de ejecución:

1. **Subagent-Driven (recomendada)** — Despacho un subagente fresco por task, revisión entre tasks, iteración rápida.

2. **Inline Execution** — Ejecutar los tasks en esta sesión con `executing-plans`, ejecución batch con checkpoints para revisión.

¿Cuál prefieres?

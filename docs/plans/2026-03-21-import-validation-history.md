# Mejora de Validaciones e Historial en Importación de Empleados

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Corregir la importación Excel de empleados para que solo cuente como "actualizados" los registros con cambios reales, registre historial de cambios, y muestre un nuevo contador "sin cambios".

**Architecture:** Se modifica `ExcelImportService::processImport()` para comparar valores normalizados antes de guardar. Se agrega `unchanged` a `ImportResult`. Se inyecta `EmployeeHistoryService` + `userId` para registrar auditoría en importaciones.

**Tech Stack:** CakePHP 5.3, PHP 8.2, PhpSpreadsheet, JavaScript vanilla.

---

### Task 1: Agregar propiedad `unchanged` a `ImportResult`

**Files:**
- Modify: `src/Service/ImportResult.php`

**Step 1: Agregar propiedad y actualizar `getSummary()`**

En `src/Service/ImportResult.php`, agregar la propiedad `$unchanged` y su línea en `getSummary()`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

class ImportResult
{
    public int $created = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $skipped = 0;
    public array $errors = [];

    public function getSummary(): string
    {
        $parts = [];
        if ($this->created > 0) {
            $parts[] = "{$this->created} creados";
        }
        if ($this->updated > 0) {
            $parts[] = "{$this->updated} actualizados";
        }
        if ($this->unchanged > 0) {
            $parts[] = "{$this->unchanged} sin cambios";
        }
        if ($this->skipped > 0) {
            $parts[] = "{$this->skipped} omitidos";
        }
        if (!empty($this->errors)) {
            $parts[] = count($this->errors) . ' errores';
        }

        return implode(', ', $parts) ?: 'Sin cambios';
    }
}
```

**Step 2: Commit**

```bash
git add src/Service/ImportResult.php
git commit -m "feat: add unchanged counter to ImportResult"
```

---

### Task 2: Agregar método de normalización y comparación en `ExcelImportService`

**Files:**
- Modify: `src/Service/ExcelImportService.php`

**Step 1: Agregar método privado `normalizeForComparison`**

Agregar este método al final de la clase `ExcelImportService` (antes del `}` de cierre):

```php
/**
 * Normalize a value for comparison between existing DB value and imported value.
 *
 * @param mixed $value The value to normalize
 * @param string $type The field type from definitions
 * @return string|null Normalized string representation for comparison
 */
private function normalizeForComparison(mixed $value, string $type): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if ($value instanceof \DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    return match ($type) {
        'date' => trim((string)$value),
        'decimal' => rtrim(rtrim(number_format((float)$value, 10, '.', ''), '0'), '.'),
        'integer' => (string)(int)$value,
        'boolean' => (string)(int)(bool)$value,
        default => trim((string)$value),
    };
}
```

**Step 2: Agregar método privado `filterChangedFields`**

Agregar este método después de `normalizeForComparison`:

```php
/**
 * Filter rowData to only fields that actually differ from existing entity.
 *
 * @param object $existing The existing entity from DB
 * @param array<string, mixed> $rowData Imported row data
 * @param array<string, array> $definitions Field definitions with types
 * @return array<string, mixed> Only the fields that have real changes
 */
private function filterChangedFields(object $existing, array $rowData, array $definitions): array
{
    $changed = [];
    foreach ($rowData as $field => $newValue) {
        $type = $definitions[$field]['type'] ?? 'string';
        // FK fields store integer IDs regardless of their declared type
        if (!empty($definitions[$field]['fk'])) {
            $type = 'integer';
        }

        $oldNormalized = $this->normalizeForComparison($existing->get($field), $type);
        $newNormalized = $this->normalizeForComparison($newValue, $type);

        if ($oldNormalized !== $newNormalized) {
            $changed[$field] = $newValue;
        }
    }

    return $changed;
}
```

**Step 3: Commit**

```bash
git add src/Service/ExcelImportService.php
git commit -m "feat: add normalization and comparison methods to ExcelImportService"
```

---

### Task 3: Modificar lógica de upsert en `processImport`

**Files:**
- Modify: `src/Service/ExcelImportService.php`

**Step 1: Extender firma de `processImport` con parámetros de historial**

Cambiar la firma del método `processImport` (línea 56-63) a:

```php
public function processImport(
    string $tempFilePath,
    string $module,
    string $tableName,
    array $mapping,
    array $enabledHeaders,
    ?callable $onCreated = null,
    ?EmployeeHistoryService $historyService = null,
    ?int $userId = null,
): ImportResult {
```

Agregar el import al inicio del archivo:
```php
use App\Model\Entity\Employee;
```

**Step 2: Modificar bloque de upsert para comparar antes de guardar**

Reemplazar el bloque de upsert existente (líneas 187-227, desde `// Upsert` hasta el final del `else` de save) con:

```php
// Upsert
$existing = $table->find()
    ->where([$keyField => $keyValue])
    ->first();

if ($existing) {
    // Filter to only fields with actual changes
    $changedData = $this->filterChangedFields($existing, $rowData, $definitions);

    if (empty($changedData)) {
        $result->unchanged++;
        continue;
    }

    // Clone original state before patching (for history)
    $originalClone = clone $existing;

    $entity = $table->patchEntity($existing, $changedData);

    if ($table->save($entity)) {
        $result->updated++;
        // Record history if service provided
        if ($historyService && $userId && $entity instanceof Employee) {
            $historyService->recordChanges($originalClone, $entity, $userId);
        }
    } else {
        $errors = $entity->getErrors();
        $errorMsg = "Fila {$rowNum}: ";
        foreach ($errors as $field => $fieldErrors) {
            $label = $definitions[$field]['label'] ?? $field;
            $errorMsg .= "{$label}: " . implode(', ', $fieldErrors) . '. ';
        }
        $result->errors[] = trim($errorMsg);
    }
} else {
    // Validate required_new fields
    $missingNew = [];
    foreach ($definitions as $field => $def) {
        if (!empty($def['required_new']) && empty($rowData[$field])) {
            $missingNew[] = $def['label'];
        }
    }
    if (!empty($missingNew)) {
        $result->errors[] = "Fila {$rowNum}: Campos obligatorios para nuevo registro: "
            . implode(', ', $missingNew);
        continue;
    }
    $entity = $table->newEntity($rowData);

    if ($table->save($entity)) {
        $result->created++;
        if ($onCreated) {
            $onCreated($entity);
        }
    } else {
        $errors = $entity->getErrors();
        $errorMsg = "Fila {$rowNum}: ";
        foreach ($errors as $field => $fieldErrors) {
            $label = $definitions[$field]['label'] ?? $field;
            $errorMsg .= "{$label}: " . implode(', ', $fieldErrors) . '. ';
        }
        $result->errors[] = trim($errorMsg);
    }
}
```

**Step 3: Commit**

```bash
git add src/Service/ExcelImportService.php
git commit -m "feat: compare fields before saving and record history on import"
```

---

### Task 4: Actualizar `EmployeesController::importProcess` para inyectar historial

**Files:**
- Modify: `src/Controller/EmployeesController.php`

**Step 1: Pasar EmployeeHistoryService y userId al processImport**

En el método `importProcess()` (línea ~470-479), cambiar la llamada a `processImport` para incluir el history service y userId:

Reemplazar:
```php
$importService = new ExcelImportService();
$result = $importService->processImport(
    $tempPath,
    'Employees',
    'Employees',
    $mapping,
    $enabledHeaders,
    fn($entity) => $this->documentService->createDefaultFolders((int)$entity->id),
);
```

Con:
```php
$importService = new ExcelImportService();
$historyService = new EmployeeHistoryService();
$userId = (int)$this->request->getAttribute('identity')->getIdentifier();
$result = $importService->processImport(
    $tempPath,
    'Employees',
    'Employees',
    $mapping,
    $enabledHeaders,
    fn($entity) => $this->documentService->createDefaultFolders((int)$entity->id),
    $historyService,
    $userId,
);
```

Verificar que el import de `EmployeeHistoryService` existe al inicio del controller. Si no existe, agregar:
```php
use App\Service\EmployeeHistoryService;
```

**Step 2: Agregar `unchanged` a la respuesta JSON**

Reemplazar el bloque de `$this->set()` y `setOption('serialize')` (líneas ~481-491):

```php
$this->set([
    'success' => empty($result->errors) || $result->created > 0 || $result->updated > 0,
    'created' => $result->created,
    'updated' => $result->updated,
    'unchanged' => $result->unchanged,
    'skipped' => $result->skipped,
    'errors' => $result->errors,
    'summary' => $result->getSummary(),
]);
$this->viewBuilder()->setOption('serialize', [
    'success', 'created', 'updated', 'unchanged', 'skipped', 'errors', 'summary',
]);
```

**Step 3: Commit**

```bash
git add src/Controller/EmployeesController.php
git commit -m "feat: inject EmployeeHistoryService into import and expose unchanged count"
```

---

### Task 5: Actualizar UI para mostrar contador "Sin cambios"

**Files:**
- Modify: `webroot/js/excel-mapper.js`

**Step 1: Agregar tarjeta "Sin cambios" en `renderImportResults`**

En la función `renderImportResults` (línea ~378), reemplazar el bloque `<div class="row g-3 mb-3">` que tiene 4 columnas (`col-3`) con 5 columnas usando un layout flexible:

Reemplazar desde `<div class="row g-3 mb-3">` hasta su cierre `</div>` (líneas 387-404) con:

```javascript
<div class="d-flex justify-content-center gap-3 mb-3 flex-wrap">
    <div class="text-center" style="min-width:80px">
        <div style="font-size:1.5rem;font-weight:600;color:var(--primary-color)">${data.created}</div>
        <div style="font-size:.75rem;color:#666">Creados</div>
    </div>
    <div class="text-center" style="min-width:80px">
        <div style="font-size:1.5rem;font-weight:600;color:#0d6efd">${data.updated}</div>
        <div style="font-size:.75rem;color:#666">Actualizados</div>
    </div>
    <div class="text-center" style="min-width:80px">
        <div style="font-size:1.5rem;font-weight:600;color:#6c757d">${data.unchanged || 0}</div>
        <div style="font-size:.75rem;color:#666">Sin cambios</div>
    </div>
    <div class="text-center" style="min-width:80px">
        <div style="font-size:1.5rem;font-weight:600;color:#6c757d">${data.skipped}</div>
        <div style="font-size:.75rem;color:#666">Omitidos</div>
    </div>
    <div class="text-center" style="min-width:80px">
        <div style="font-size:1.5rem;font-weight:600;color:#dc3545">${data.errors ? data.errors.length : 0}</div>
        <div style="font-size:.75rem;color:#666">Errores</div>
    </div>
</div>
```

**Step 2: Commit**

```bash
git add webroot/js/excel-mapper.js
git commit -m "feat: show unchanged counter in import results UI"
```

---

### Task 6: Verificación manual end-to-end

**Step 1: Verificar code style**

```bash
composer cs-check
```

Esperado: Sin errores en los archivos modificados. Si hay errores, corregir con `composer cs-fix`.

**Step 2: Verificar tests existentes**

```bash
composer test
```

Esperado: Todos los tests pasan.

**Step 3: Verificación funcional**

1. Iniciar servidor: `php bin/cake server`
2. Ir a Empleados → Importar Excel
3. Exportar el listado actual de empleados
4. Re-importar el mismo archivo Excel sin cambios
5. Verificar que el resultado muestra "X sin cambios" y 0 actualizados
6. Modificar un campo en el Excel (ej: teléfono de un empleado)
7. Re-importar
8. Verificar que muestra "1 actualizado, X-1 sin cambios"
9. Ir al historial del empleado modificado y confirmar que el cambio quedó registrado

**Step 4: Commit final si hubo ajustes de cs-fix**

```bash
git add -A
git commit -m "fix: code style adjustments"
```

# Excel Import/Export Employees — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a flexible, user-friendly Excel import/export system for Employees with Spanish headers, column selection/reordering in export, and interactive column mapping in import — all via AJAX modals.

**Architecture:** New `ExcelMappingService` defines field↔label mappings per module. New `ExcelImportService` handles mapped imports with upsert logic. `ExcelService` is extended to accept custom fields+labels. Frontend uses a single `excel-mapper.js` with SortableJS for drag-and-drop reordering in export and interactive column mapping in import. All communication via AJAX with JSON responses.

**Tech Stack:** CakePHP 5.3, PhpSpreadsheet, Bootstrap 5 modals, SortableJS (CDN), vanilla JS + fetch API

---

## Task 1: Create ExcelMappingService

**Files:**
- Create: `src/Service/ExcelMappingService.php`

**Step 1: Create the service with Employees field definitions**

```php
<?php
declare(strict_types=1);

namespace App\Service;

final class ExcelMappingService
{
    private const FIELD_DEFINITIONS = [
        'Employees' => [
            'document_type' => ['label' => 'Tipo de documento', 'type' => 'string'],
            'document_number' => ['label' => 'Cédula', 'type' => 'string', 'required' => true, 'is_key' => true],
            'first_name' => ['label' => 'Nombres', 'type' => 'string', 'required_new' => true],
            'last_name' => ['label' => 'Apellidos', 'type' => 'string', 'required_new' => true],
            'birth_date' => ['label' => 'Fecha de nacimiento', 'type' => 'date'],
            'gender' => ['label' => 'Género', 'type' => 'string'],
            'email' => ['label' => 'Correo electrónico', 'type' => 'string'],
            'phone' => ['label' => 'Teléfono', 'type' => 'string'],
            'address' => ['label' => 'Dirección', 'type' => 'string'],
            'city' => ['label' => 'Ciudad', 'type' => 'string'],
            'hire_date' => ['label' => 'Fecha de ingreso', 'type' => 'date'],
            'termination_date' => ['label' => 'Fecha de retiro', 'type' => 'date'],
            'salary' => ['label' => 'Salario', 'type' => 'decimal'],
            'contract_type' => ['label' => 'Tipo de contrato', 'type' => 'string'],
            'vest_number' => ['label' => 'Número de chaleco', 'type' => 'string'],
            'eps' => ['label' => 'EPS', 'type' => 'string'],
            'pension_fund' => ['label' => 'Fondo de pensión', 'type' => 'string'],
            'arl' => ['label' => 'ARL', 'type' => 'string'],
            'severance_fund' => ['label' => 'Fondo de cesantías', 'type' => 'string'],
            'notes' => ['label' => 'Observaciones', 'type' => 'string'],
            'active' => ['label' => 'Activo', 'type' => 'boolean'],
            'position_id' => ['label' => 'ID Cargo', 'type' => 'integer', 'fk' => true],
            'position' => ['label' => 'Cargo', 'type' => 'string', 'display_only' => true],
            'supervisor_position_id' => ['label' => 'ID Cargo supervisor', 'type' => 'integer', 'fk' => true],
            'supervisor_position' => ['label' => 'Cargo supervisor', 'type' => 'string', 'display_only' => true],
            'operation_center_id' => ['label' => 'ID Centro de operación', 'type' => 'integer', 'fk' => true],
            'operation_center' => ['label' => 'Centro de operación', 'type' => 'string', 'display_only' => true],
            'cost_center_id' => ['label' => 'ID Centro de costos', 'type' => 'integer', 'fk' => true],
            'cost_center' => ['label' => 'Centro de costos', 'type' => 'string', 'display_only' => true],
            'employee_status_id' => ['label' => 'ID Estado', 'type' => 'integer', 'fk' => true],
            'employee_status' => ['label' => 'Estado', 'type' => 'string', 'display_only' => true],
            'marital_status_id' => ['label' => 'ID Estado civil', 'type' => 'integer', 'fk' => true],
            'marital_status' => ['label' => 'Estado civil', 'type' => 'string', 'display_only' => true],
            'education_level_id' => ['label' => 'ID Nivel educativo', 'type' => 'integer', 'fk' => true],
            'education_level' => ['label' => 'Nivel educativo', 'type' => 'string', 'display_only' => true],
            'temporary_organization_id' => ['label' => 'ID Temporal', 'type' => 'integer', 'fk' => true],
            'temporary_organization' => ['label' => 'Temporal', 'type' => 'string', 'display_only' => true],
        ],
    ];

    /**
     * Get field definitions for a module.
     *
     * @return array<string, array{label: string, type: string, required?: bool, required_new?: bool, is_key?: bool, fk?: bool, display_only?: bool}>
     */
    public function getFieldDefinitions(string $module): array
    {
        return self::FIELD_DEFINITIONS[$module] ?? [];
    }

    /**
     * Get exportable fields as ordered list for JSON response.
     *
     * @return array<int, array{field: string, label: string}>
     */
    public function getExportableFields(string $module): array
    {
        $fields = [];
        foreach ($this->getFieldDefinitions($module) as $field => $def) {
            $fields[] = [
                'field' => $field,
                'label' => $def['label'],
                'checked' => true,
            ];
        }

        return $fields;
    }

    /**
     * Get system fields for import mapping UI (excludes display_only).
     *
     * @return array<int, array{field: string, label: string, required: bool}>
     */
    public function getImportableFields(string $module): array
    {
        $fields = [];
        foreach ($this->getFieldDefinitions($module) as $field => $def) {
            if (!empty($def['display_only'])) {
                continue;
            }
            $fields[] = [
                'field' => $field,
                'label' => $def['label'],
                'required' => !empty($def['required']),
            ];
        }

        return $fields;
    }

    /**
     * Build label→field and field→field lookup maps for auto-mapping.
     * Returns ['spanish_label' => 'field_name', 'english_field' => 'field_name'].
     *
     * @return array<string, string>
     */
    public function buildAutoMapLookup(string $module): array
    {
        $lookup = [];
        foreach ($this->getFieldDefinitions($module) as $field => $def) {
            if (!empty($def['display_only'])) {
                continue;
            }
            // Match by Spanish label (case-insensitive)
            $lookup[mb_strtolower(trim($def['label']))] = $field;
            // Match by English field name (case-insensitive)
            $lookup[mb_strtolower($field)] = $field;
        }

        return $lookup;
    }

    /**
     * Auto-map file headers to system fields.
     *
     * @param array<string> $fileHeaders Headers from the uploaded Excel file
     * @return array<string, string|null> Map of file_header => system_field (null if no match)
     */
    public function autoMapColumns(array $fileHeaders, string $module): array
    {
        $lookup = $this->buildAutoMapLookup($module);
        $mapping = [];

        foreach ($fileHeaders as $header) {
            $normalized = mb_strtolower(trim($header));
            $mapping[$header] = $lookup[$normalized] ?? null;
        }

        return $mapping;
    }

    /**
     * Validate that required fields are mapped.
     *
     * @param array<string, string|null> $mapping file_header => system_field
     * @return array<string> List of error messages (empty = valid)
     */
    public function validateMapping(array $mapping, string $module): array
    {
        $definitions = $this->getFieldDefinitions($module);
        $mappedFields = array_filter(array_values($mapping));
        $errors = [];

        foreach ($definitions as $field => $def) {
            if (!empty($def['required']) && !in_array($field, $mappedFields)) {
                $errors[] = "El campo obligatorio \"{$def['label']}\" no está mapeado.";
            }
        }

        return $errors;
    }

    /**
     * Get the label map (field => label) for export headers.
     *
     * @return array<string, string>
     */
    public function getLabelMap(string $module): array
    {
        $map = [];
        foreach ($this->getFieldDefinitions($module) as $field => $def) {
            $map[$field] = $def['label'];
        }

        return $map;
    }
}
```

**Step 2: Commit**

```bash
git add src/Service/ExcelMappingService.php
git commit -m "feat(excel): add ExcelMappingService with field definitions and auto-mapping"
```

---

## Task 2: Create ExcelImportService

**Files:**
- Create: `src/Service/ExcelImportService.php`

**Step 1: Create the import service with mapped upsert logic**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;
use Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ExcelImportService
{
    private ExcelMappingService $mappingService;

    public function __construct(?ExcelMappingService $mappingService = null)
    {
        $this->mappingService = $mappingService ?? new ExcelMappingService();
    }

    /**
     * Read headers from an uploaded Excel file saved as temp.
     *
     * @return array{headers: array<string>, temp_file: string}
     * @throws \Exception if the file cannot be read
     */
    public function readHeaders(string $tempFilePath): array
    {
        $spreadsheet = IOFactory::load($tempFilePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        if (empty($rows)) {
            throw new Exception('El archivo está vacío.');
        }

        $headers = array_map(fn($h) => trim((string)$h), $rows[0]);
        // Remove empty trailing headers
        $headers = array_values(array_filter($headers, fn($h) => $h !== ''));

        return $headers;
    }

    /**
     * Process import with custom column mapping.
     *
     * @param string $tempFilePath Path to the temporary Excel file
     * @param string $module Module name (e.g., 'Employees')
     * @param string $tableName ORM table alias
     * @param array<string, string> $mapping file_header => system_field
     * @param array<string> $enabledHeaders Only these file headers will be processed
     */
    public function processImport(
        string $tempFilePath,
        string $module,
        string $tableName,
        array $mapping,
        array $enabledHeaders,
    ): ImportResult {
        $result = new ImportResult();
        $definitions = $this->mappingService->getFieldDefinitions($module);

        // Find key field
        $keyField = null;
        foreach ($definitions as $field => $def) {
            if (!empty($def['is_key'])) {
                $keyField = $field;
                break;
            }
        }
        if (!$keyField) {
            $result->errors[] = 'No se encontró campo clave para el módulo.';
            return $result;
        }

        // Validate required fields are mapped
        $validationErrors = $this->mappingService->validateMapping($mapping, $module);
        if (!empty($validationErrors)) {
            $result->errors = $validationErrors;
            return $result;
        }

        try {
            $spreadsheet = IOFactory::load($tempFilePath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, false, false);
        } catch (Exception $e) {
            $result->errors[] = 'No se pudo leer el archivo: ' . $e->getMessage();
            return $result;
        }

        if (count($rows) < 2) {
            $result->errors[] = 'El archivo está vacío o solo tiene encabezados.';
            return $result;
        }

        $headers = array_map(fn($h) => trim((string)$h), $rows[0]);
        $table = TableRegistry::getTableLocator()->get($tableName);
        $skipSystemFields = ['id', 'created', 'modified', 'profile_image'];

        for ($i = 1; $i < count($rows); $i++) {
            $rowData = [];
            $rowNum = $i + 1; // Human-readable row number

            foreach ($headers as $col => $header) {
                // Skip if header is not in enabled list
                if (!in_array($header, $enabledHeaders)) {
                    continue;
                }
                // Skip if not mapped
                $systemField = $mapping[$header] ?? null;
                if (!$systemField) {
                    continue;
                }
                // Skip system and display_only fields
                if (in_array($systemField, $skipSystemFields)) {
                    continue;
                }
                $fieldDef = $definitions[$systemField] ?? null;
                if ($fieldDef && !empty($fieldDef['display_only'])) {
                    continue;
                }

                $rawValue = $rows[$i][$col] ?? null;
                $rowData[$systemField] = $this->castValue($rawValue, $fieldDef['type'] ?? 'string');
            }

            // Check key field
            $keyValue = trim((string)($rowData[$keyField] ?? ''));
            if ($keyValue === '') {
                $result->skipped++;
                continue;
            }

            // Upsert
            $existing = $table->find()
                ->where([$keyField => $keyValue])
                ->first();

            if ($existing) {
                $entity = $table->patchEntity($existing, $rowData);
            } else {
                // Validate required_new fields
                $missingNew = [];
                foreach ($definitions as $field => $def) {
                    if (!empty($def['required_new']) && empty($rowData[$field])) {
                        $missingNew[] = $def['label'];
                    }
                }
                if (!empty($missingNew)) {
                    $result->errors[] = "Fila {$rowNum}: Campos obligatorios para nuevo registro: " . implode(', ', $missingNew);
                    continue;
                }
                $entity = $table->newEntity($rowData);
            }

            if ($table->save($entity)) {
                if ($existing) {
                    $result->updated++;
                } else {
                    $result->created++;
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

        return $result;
    }

    /**
     * Cast a raw Excel value to the expected PHP type.
     */
    private function castValue(mixed $rawValue, string $type): mixed
    {
        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        return match ($type) {
            'date' => $this->parseDate($rawValue),
            'decimal' => $this->parseDecimal($rawValue),
            'integer' => (int)$rawValue,
            'boolean' => $this->parseBoolean($rawValue),
            default => trim((string)$rawValue),
        };
    }

    private function parseDate(mixed $value): ?string
    {
        // Excel serial number
        if (is_numeric($value) && (float)$value > 1000) {
            try {
                $dateObj = Date::excelToDateTimeObject((float)$value);
                return $dateObj->format('Y-m-d');
            } catch (Exception) {
                return null;
            }
        }

        $strValue = trim((string)$value);

        // Try common formats
        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'm/d/Y'];
        foreach ($formats as $format) {
            $parsed = \DateTime::createFromFormat($format, $strValue);
            if ($parsed && $parsed->format($format) === $strValue) {
                return $parsed->format('Y-m-d');
            }
        }

        return $strValue;
    }

    private function parseDecimal(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }
        // Remove currency formatting (dots as thousands, comma as decimal)
        $cleaned = str_replace(['.', '$', ' '], '', (string)$value);
        $cleaned = str_replace(',', '.', $cleaned);

        return is_numeric($cleaned) ? (float)$cleaned : null;
    }

    private function parseBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $strValue = mb_strtolower(trim((string)$value));

        return match ($strValue) {
            '1', 'true', 'sí', 'si', 'yes', 'activo' => true,
            '0', 'false', 'no', 'inactivo' => false,
            default => null,
        };
    }
}
```

**Step 2: Commit**

```bash
git add src/Service/ExcelImportService.php
git commit -m "feat(excel): add ExcelImportService with mapped upsert and type casting"
```

---

## Task 3: Extend ExcelService for custom export

**Files:**
- Modify: `src/Service/ExcelService.php`

**Step 1: Add new method `exportWithLabels()` to ExcelService**

Add this method after the existing `exportCatalog()` method (after line 68):

```php
/**
 * Export data with custom field selection, ordering, and Spanish labels.
 *
 * @param string $sheetTitle Title for the Excel sheet
 * @param \Cake\ORM\Query\SelectQuery $query The query to export
 * @param array<string> $fields Ordered list of field names to export
 * @param array<string, string> $labelMap Map of field_name => Spanish label for headers
 */
public function exportWithLabels(
    string $sheetTitle,
    SelectQuery $query,
    array $fields,
    array $labelMap,
): string {
    $results = $query->all()->toArray();

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($sheetTitle);

    if (empty($results)) {
        $sheet->setCellValue('A1', 'Sin datos');
    } else {
        // Headers with Spanish labels
        foreach ($fields as $col => $field) {
            $cell = Coordinate::stringFromColumnIndex($col + 1) . '1';
            $sheet->setCellValue($cell, $labelMap[$field] ?? $field);
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }

        // Data rows
        foreach ($results as $rowNum => $entity) {
            $row = method_exists($entity, 'toArray') ? $entity->toArray() : (array)$entity;
            foreach ($fields as $col => $field) {
                $value = $row[$field] ?? '';
                if ($value instanceof DateTimeInterface) {
                    $value = $value->format('Y-m-d');
                }
                $cell = Coordinate::stringFromColumnIndex($col + 1) . ($rowNum + 2);
                $sheet->setCellValue($cell, $value);
            }
        }

        // Auto-size
        foreach ($fields as $col => $field) {
            $colLetter = Coordinate::stringFromColumnIndex($col + 1);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }
    }

    $tempFile = tempnam(sys_get_temp_dir(), 'sgi_export_') . '.xlsx';
    $writer = new Xlsx($spreadsheet);
    $writer->save($tempFile);

    return $tempFile;
}
```

**Step 2: Commit**

```bash
git add src/Service/ExcelService.php
git commit -m "feat(excel): add exportWithLabels() for custom field selection and Spanish headers"
```

---

## Task 4: Add AJAX endpoints to EmployeesController

**Files:**
- Modify: `src/Controller/EmployeesController.php`

**Step 1: Add `exportConfig()` action**

Add after the `initialize()` method. This returns JSON with available fields for the export modal.

```php
public function exportConfig()
{
    $this->request->allowMethod(['get']);
    $this->viewBuilder()->setClassName('Json');

    $mappingService = new \App\Service\ExcelMappingService();
    $fields = $mappingService->getExportableFields('Employees');

    $this->set('fields', $fields);
    $this->viewBuilder()->setOption('serialize', ['fields']);
}
```

**Step 2: Replace existing `export()` method (lines 207-281)**

Replace the entire `export()` method with this version that accepts POST with selected fields:

```php
public function export()
{
    $this->request->allowMethod(['post']);

    $requestFields = $this->request->getData('fields');
    if (empty($requestFields) || !is_array($requestFields)) {
        $this->response = $this->response->withStatus(400);
        $this->viewBuilder()->setClassName('Json');
        $this->set('error', 'No se seleccionaron campos para exportar.');
        $this->viewBuilder()->setOption('serialize', ['error']);

        return;
    }

    $mappingService = new \App\Service\ExcelMappingService();
    $labelMap = $mappingService->getLabelMap('Employees');

    // Filter to only valid fields
    $allDefinitions = $mappingService->getFieldDefinitions('Employees');
    $validFields = array_filter($requestFields, fn($f) => isset($allDefinitions[$f]));
    if (empty($validFields)) {
        $this->response = $this->response->withStatus(400);
        $this->viewBuilder()->setClassName('Json');
        $this->set('error', 'Ningún campo válido seleccionado.');
        $this->viewBuilder()->setOption('serialize', ['error']);

        return;
    }

    // Build query with contains for display_only fields
    $query = $this->Employees->find()
        ->contain([
            'EmployeeStatuses',
            'Positions',
            'SupervisorPositions',
            'OperationCenters',
            'CostCenters',
            'MaritalStatuses',
            'EducationLevels',
            'TemporaryOrganizations',
        ])
        ->order(['Employees.last_name' => 'ASC'])
        ->formatResults(function ($results) use ($validFields) {
            return $results->map(function ($employee) use ($validFields) {
                $data = [];
                $relationMap = [
                    'position' => 'position',
                    'supervisor_position' => 'supervisor_position',
                    'operation_center' => 'operation_center',
                    'cost_center' => 'cost_center',
                    'employee_status' => 'employee_status',
                    'marital_status' => 'marital_status',
                    'education_level' => 'education_level',
                    'temporary_organization' => 'temporary_organization',
                ];

                foreach ($validFields as $field) {
                    if (isset($relationMap[$field])) {
                        $rel = $relationMap[$field];
                        $data[$field] = $employee->{$rel}->name ?? '';
                    } else {
                        $data[$field] = $employee->{$field} ?? '';
                    }
                }

                return new \ArrayObject($data);
            });
        });

    $excelService = new \App\Service\ExcelService();
    $filePath = $excelService->exportWithLabels('Empleados', $query, $validFields, $labelMap);

    $response = $this->response->withFile($filePath, [
        'download' => true,
        'name' => 'empleados_' . date('Y-m-d') . '.xlsx',
    ]);

    register_shutdown_function(function () use ($filePath) {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    });

    return $response;
}
```

**Step 3: Add `importUpload()` action**

Add after `export()`. Receives file upload, saves temp, reads headers, auto-maps.

```php
public function importUpload()
{
    $this->request->allowMethod(['post']);
    $this->viewBuilder()->setClassName('Json');

    $file = $this->request->getUploadedFile('excel_file');
    if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
        $this->response = $this->response->withStatus(400);
        $this->set('error', 'No se recibió un archivo válido.');
        $this->viewBuilder()->setOption('serialize', ['error']);

        return;
    }

    // Save temp file
    $tempName = 'sgi_import_' . bin2hex(random_bytes(8));
    $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tempName . '.xlsx';
    $file->moveTo($tempPath);

    try {
        $importService = new \App\Service\ExcelImportService();
        $headers = $importService->readHeaders($tempPath);

        $mappingService = new \App\Service\ExcelMappingService();
        $autoMapping = $mappingService->autoMapColumns($headers, 'Employees');
        $systemFields = $mappingService->getImportableFields('Employees');

        $this->set(compact('tempName', 'headers', 'autoMapping', 'systemFields'));
        $this->viewBuilder()->setOption('serialize', ['tempName', 'headers', 'autoMapping', 'systemFields']);
    } catch (\Exception $e) {
        if (file_exists($tempPath)) {
            unlink($tempPath);
        }
        $this->response = $this->response->withStatus(400);
        $this->set('error', $e->getMessage());
        $this->viewBuilder()->setOption('serialize', ['error']);
    }
}
```

**Step 4: Replace existing `import()` method with `importProcess()`**

Remove the old `import()` method (lines 283-319) and add:

```php
public function importProcess()
{
    $this->request->allowMethod(['post']);
    $this->viewBuilder()->setClassName('Json');

    $tempName = $this->request->getData('temp_file');
    $mapping = $this->request->getData('mapping');
    $enabledHeaders = $this->request->getData('enabled');

    if (!$tempName || !$mapping || !$enabledHeaders) {
        $this->response = $this->response->withStatus(400);
        $this->set('error', 'Datos de importación incompletos.');
        $this->viewBuilder()->setOption('serialize', ['error']);

        return;
    }

    // Validate temp file name (prevent path traversal)
    if (!preg_match('/^sgi_import_[a-f0-9]{16}$/', $tempName)) {
        $this->response = $this->response->withStatus(400);
        $this->set('error', 'Referencia de archivo inválida.');
        $this->viewBuilder()->setOption('serialize', ['error']);

        return;
    }

    $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tempName . '.xlsx';
    if (!file_exists($tempPath)) {
        $this->response = $this->response->withStatus(400);
        $this->set('error', 'El archivo temporal ha expirado. Por favor, suba el archivo nuevamente.');
        $this->viewBuilder()->setOption('serialize', ['error']);

        return;
    }

    try {
        $importService = new \App\Service\ExcelImportService();
        $result = $importService->processImport(
            $tempPath,
            'Employees',
            'Employees',
            $mapping,
            $enabledHeaders,
        );

        $this->set([
            'success' => empty($result->errors) || $result->created > 0 || $result->updated > 0,
            'created' => $result->created,
            'updated' => $result->updated,
            'skipped' => $result->skipped,
            'errors' => $result->errors,
            'summary' => $result->getSummary(),
        ]);
        $this->viewBuilder()->setOption('serialize', ['success', 'created', 'updated', 'skipped', 'errors', 'summary']);
    } finally {
        if (file_exists($tempPath)) {
            unlink($tempPath);
        }
    }
}
```

**Step 5: Commit**

```bash
git add src/Controller/EmployeesController.php
git commit -m "feat(excel): add AJAX endpoints for export config, upload, and mapped import"
```

---

## Task 5: Add routes for new AJAX endpoints

**Files:**
- Modify: `config/routes.php`

**Step 1: Add routes before `$builder->fallbacks()`**

Add these routes after the existing employee document management routes (after line 214), before `$builder->fallbacks()`:

```php
// Employee Excel import/export AJAX
$builder->connect(
    '/employees/export-config',
    ['controller' => 'Employees', 'action' => 'exportConfig']
);
$builder->connect(
    '/employees/import-upload',
    ['controller' => 'Employees', 'action' => 'importUpload']
);
$builder->connect(
    '/employees/import-process',
    ['controller' => 'Employees', 'action' => 'importProcess']
);
```

Note: The `export` action already works with the fallback routes (POST `/employees/export`), but we add explicit routes for the new actions since they use camelCase action names that need dashed URL conversion.

**Step 2: Commit**

```bash
git add config/routes.php
git commit -m "feat(excel): add routes for employee Excel AJAX endpoints"
```

---

## Task 6: Create excel-mapper.js

**Files:**
- Create: `webroot/js/excel-mapper.js`

**Step 1: Create the JavaScript module**

This file handles both export and import modals with AJAX communication. It is initialized from the template via `data-module` attribute.

```javascript
/**
 * Excel Mapper — Export & Import modal logic with SortableJS.
 * Initialize by including this script and adding data-module="Employees" to #exportExcelModal.
 */
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrfToken"]')?.content || '';

    // ─── EXPORT MODAL ───
    const exportModal = document.getElementById('exportExcelModal');
    if (exportModal) {
        const module = exportModal.dataset.module || 'Employees';
        const exportFieldList = document.getElementById('exportFieldList');
        const exportBtn = document.getElementById('exportBtn');
        const exportSelectAll = document.getElementById('exportSelectAll');
        const exportLoading = document.getElementById('exportLoading');

        // Load fields when modal opens
        exportModal.addEventListener('show.bs.modal', function () {
            if (exportFieldList.children.length > 0) return; // Already loaded
            exportLoading.style.display = 'block';

            fetch(`/${module.toLowerCase()}/export-config`, {
                headers: { 'Accept': 'application/json' }
            })
            .then(r => r.json())
            .then(data => {
                exportLoading.style.display = 'none';
                renderExportFields(data.fields);
                initSortable();
            })
            .catch(() => {
                exportLoading.style.display = 'none';
                exportFieldList.innerHTML = '<div class="text-danger">Error al cargar campos.</div>';
            });
        });

        function renderExportFields(fields) {
            exportFieldList.innerHTML = '';
            fields.forEach(f => {
                const item = document.createElement('div');
                item.className = 'export-field-item d-flex align-items-center gap-2 px-3 py-2';
                item.dataset.field = f.field;
                item.innerHTML = `
                    <i class="bi bi-grip-vertical text-muted export-drag-handle" style="cursor:grab"></i>
                    <input type="checkbox" class="form-check-input export-field-check" value="${f.field}" ${f.checked ? 'checked' : ''} id="exp_${f.field}">
                    <label class="form-check-label flex-grow-1" for="exp_${f.field}" style="font-size:.875rem">${f.label}</label>
                `;
                exportFieldList.appendChild(item);
            });
        }

        function initSortable() {
            if (typeof Sortable !== 'undefined') {
                Sortable.create(exportFieldList, {
                    handle: '.export-drag-handle',
                    animation: 150,
                    ghostClass: 'sortable-ghost',
                });
            }
        }

        // Select all
        if (exportSelectAll) {
            exportSelectAll.addEventListener('change', function () {
                exportFieldList.querySelectorAll('.export-field-check').forEach(cb => {
                    cb.checked = this.checked;
                });
            });
        }

        // Export button
        if (exportBtn) {
            exportBtn.addEventListener('click', function () {
                const fields = [];
                exportFieldList.querySelectorAll('.export-field-item').forEach(item => {
                    const cb = item.querySelector('.export-field-check');
                    if (cb && cb.checked) {
                        fields.push(cb.value);
                    }
                });

                if (fields.length === 0) {
                    alert('Seleccione al menos un campo para exportar.');
                    return;
                }

                exportBtn.disabled = true;
                exportBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Exportando...';

                fetch(`/${module.toLowerCase()}/export`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                    },
                    body: JSON.stringify({ fields }),
                })
                .then(response => {
                    if (!response.ok) throw new Error('Error en la exportación');
                    return response.blob();
                })
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `empleados_${new Date().toISOString().slice(0,10)}.xlsx`;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    window.URL.revokeObjectURL(url);
                    bootstrap.Modal.getInstance(exportModal).hide();
                })
                .catch(() => {
                    const errorDiv = document.getElementById('exportError');
                    if (errorDiv) {
                        errorDiv.textContent = 'Error al exportar. Intente de nuevo.';
                        errorDiv.style.display = 'block';
                    }
                })
                .finally(() => {
                    exportBtn.disabled = false;
                    exportBtn.innerHTML = '<i class="bi bi-download me-1"></i>Exportar';
                });
            });
        }
    }

    // ─── IMPORT MODAL ───
    const importModal = document.getElementById('importExcelModal');
    if (importModal) {
        const module = importModal.dataset.module || 'Employees';
        const importStep1 = document.getElementById('importStep1');
        const importStep2 = document.getElementById('importStep2');
        const importStep3 = document.getElementById('importStep3');
        const importUploadBtn = document.getElementById('importUploadBtn');
        const importProcessBtn = document.getElementById('importProcessBtn');
        const importBackBtn = document.getElementById('importBackBtn');
        const importCloseBtn = document.getElementById('importCloseBtn');
        const importFileInput = document.getElementById('importFileInput');
        const importMappingBody = document.getElementById('importMappingBody');
        const importMappingError = document.getElementById('importMappingError');

        let currentTempName = null;
        let currentHeaders = [];
        let systemFields = [];

        // Reset on modal close
        importModal.addEventListener('hidden.bs.modal', function () {
            showStep(1);
            if (importFileInput) importFileInput.value = '';
            currentTempName = null;
            currentHeaders = [];
            importMappingBody.innerHTML = '';
        });

        function showStep(step) {
            importStep1.style.display = step === 1 ? 'block' : 'none';
            importStep2.style.display = step === 2 ? 'block' : 'none';
            importStep3.style.display = step === 3 ? 'block' : 'none';

            if (importUploadBtn) importUploadBtn.style.display = step === 1 ? 'inline-block' : 'none';
            if (importProcessBtn) importProcessBtn.style.display = step === 2 ? 'inline-block' : 'none';
            if (importBackBtn) importBackBtn.style.display = step === 2 ? 'inline-block' : 'none';
            if (importCloseBtn) importCloseBtn.style.display = step === 3 ? 'inline-block' : 'none';
        }

        // Step 1 → Upload file
        if (importUploadBtn) {
            importUploadBtn.addEventListener('click', function () {
                const file = importFileInput?.files[0];
                if (!file) {
                    return;
                }

                const formData = new FormData();
                formData.append('excel_file', file);

                importUploadBtn.disabled = true;
                importUploadBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Analizando...';

                fetch(`/${module.toLowerCase()}/import-upload`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': csrfToken,
                    },
                    body: formData,
                })
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    currentTempName = data.tempName;
                    currentHeaders = data.headers;
                    systemFields = data.systemFields;
                    renderMappingTable(data.headers, data.autoMapping, data.systemFields);
                    showStep(2);
                })
                .catch(err => {
                    if (importMappingError) {
                        importMappingError.textContent = err.message || 'Error al procesar el archivo.';
                        importMappingError.style.display = 'block';
                    }
                })
                .finally(() => {
                    importUploadBtn.disabled = false;
                    importUploadBtn.innerHTML = '<i class="bi bi-upload me-1"></i>Subir';
                });
            });
        }

        // Back button
        if (importBackBtn) {
            importBackBtn.addEventListener('click', function () {
                showStep(1);
                importMappingError.style.display = 'none';
            });
        }

        function renderMappingTable(headers, autoMapping, fields) {
            importMappingBody.innerHTML = '';
            const usedFields = new Set();

            headers.forEach(header => {
                const mappedField = autoMapping[header] || '';
                if (mappedField) usedFields.add(mappedField);

                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="align-middle">
                        <input type="checkbox" class="form-check-input import-col-check" data-header="${escapeHtml(header)}" ${mappedField ? 'checked' : ''}>
                    </td>
                    <td class="align-middle" style="font-size:.875rem">
                        <code>${escapeHtml(header)}</code>
                    </td>
                    <td>
                        <select class="form-select form-select-sm import-field-select" data-header="${escapeHtml(header)}">
                            <option value="">— Sin asignar —</option>
                            ${fields.map(f => `<option value="${f.field}" ${mappedField === f.field ? 'selected' : ''}>${f.label}${f.required ? ' *' : ''}</option>`).join('')}
                        </select>
                    </td>
                    <td class="align-middle text-center import-status-cell">
                        ${mappedField ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash-circle text-muted"></i>'}
                    </td>
                `;
                importMappingBody.appendChild(tr);

                // Update status on select change
                const select = tr.querySelector('.import-field-select');
                const checkbox = tr.querySelector('.import-col-check');
                const statusCell = tr.querySelector('.import-status-cell');

                select.addEventListener('change', function () {
                    if (this.value) {
                        checkbox.checked = true;
                        statusCell.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i>';
                    } else {
                        statusCell.innerHTML = '<i class="bi bi-dash-circle text-muted"></i>';
                    }
                    validateRequiredMapped();
                });

                checkbox.addEventListener('change', function () {
                    validateRequiredMapped();
                });
            });

            validateRequiredMapped();
        }

        function validateRequiredMapped() {
            const requiredFields = systemFields.filter(f => f.required).map(f => f.field);
            const mappedFields = [];

            importMappingBody.querySelectorAll('tr').forEach(tr => {
                const cb = tr.querySelector('.import-col-check');
                const sel = tr.querySelector('.import-field-select');
                if (cb && cb.checked && sel && sel.value) {
                    mappedFields.push(sel.value);
                }
            });

            const missing = requiredFields.filter(f => !mappedFields.includes(f));
            const indicator = document.getElementById('importRequiredIndicator');

            if (missing.length > 0) {
                const missingLabels = missing.map(f => {
                    const sf = systemFields.find(s => s.field === f);
                    return sf ? sf.label : f;
                });
                if (indicator) {
                    indicator.innerHTML = `<i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>Faltan campos obligatorios: ${missingLabels.join(', ')}`;
                    indicator.className = 'alert alert-warning py-2 px-3 mb-0 mt-2';
                    indicator.style.display = 'block';
                    indicator.style.fontSize = '.825rem';
                }
                if (importProcessBtn) importProcessBtn.disabled = true;
            } else {
                if (indicator) {
                    indicator.innerHTML = '<i class="bi bi-check-circle-fill text-success me-1"></i>Todos los campos obligatorios mapeados';
                    indicator.className = 'alert alert-success py-2 px-3 mb-0 mt-2';
                    indicator.style.display = 'block';
                    indicator.style.fontSize = '.825rem';
                }
                if (importProcessBtn) importProcessBtn.disabled = false;
            }
        }

        // Step 2 → Process import
        if (importProcessBtn) {
            importProcessBtn.addEventListener('click', function () {
                const mapping = {};
                const enabled = [];

                importMappingBody.querySelectorAll('tr').forEach(tr => {
                    const cb = tr.querySelector('.import-col-check');
                    const sel = tr.querySelector('.import-field-select');
                    const header = cb?.dataset.header;

                    if (cb && cb.checked && header) {
                        enabled.push(header);
                        if (sel && sel.value) {
                            mapping[header] = sel.value;
                        }
                    }
                });

                importProcessBtn.disabled = true;
                importProcessBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importando...';
                importMappingError.style.display = 'none';

                fetch(`/${module.toLowerCase()}/import-process`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                    },
                    body: JSON.stringify({
                        temp_file: currentTempName,
                        mapping: mapping,
                        enabled: enabled,
                    }),
                })
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    renderImportResults(data);
                    showStep(3);
                })
                .catch(err => {
                    importMappingError.textContent = err.message || 'Error al importar.';
                    importMappingError.style.display = 'block';
                })
                .finally(() => {
                    importProcessBtn.disabled = false;
                    importProcessBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Importar';
                });
            });
        }

        // Close button (step 3) → reload page
        if (importCloseBtn) {
            importCloseBtn.addEventListener('click', function () {
                window.location.reload();
            });
        }

        function renderImportResults(data) {
            const container = document.getElementById('importResults');
            if (!container) return;

            let html = `
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-check-circle-fill text-success" style="font-size:1.5rem"></i>
                    <strong>Importación completada</strong>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-3 text-center">
                        <div style="font-size:1.5rem;font-weight:600;color:var(--primary-color)">${data.created}</div>
                        <div style="font-size:.75rem;color:#666">Creados</div>
                    </div>
                    <div class="col-3 text-center">
                        <div style="font-size:1.5rem;font-weight:600;color:#0d6efd">${data.updated}</div>
                        <div style="font-size:.75rem;color:#666">Actualizados</div>
                    </div>
                    <div class="col-3 text-center">
                        <div style="font-size:1.5rem;font-weight:600;color:#6c757d">${data.skipped}</div>
                        <div style="font-size:.75rem;color:#666">Omitidos</div>
                    </div>
                    <div class="col-3 text-center">
                        <div style="font-size:1.5rem;font-weight:600;color:#dc3545">${data.errors ? data.errors.length : 0}</div>
                        <div style="font-size:.75rem;color:#666">Errores</div>
                    </div>
                </div>
            `;

            if (data.errors && data.errors.length > 0) {
                html += `
                    <div style="font-size:.825rem;font-weight:600;margin-bottom:.5rem">Detalle de errores:</div>
                    <div style="max-height:200px;overflow-y:auto;font-size:.8rem;border:1px solid var(--border-color);border-radius:4px;padding:.5rem">
                        ${data.errors.map(e => `<div class="mb-1"><i class="bi bi-exclamation-circle text-danger me-1"></i>${escapeHtml(e)}</div>`).join('')}
                    </div>
                `;
            }

            container.innerHTML = html;
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    }
});
```

**Step 2: Commit**

```bash
git add webroot/js/excel-mapper.js
git commit -m "feat(excel): add excel-mapper.js with export/import modal logic and SortableJS"
```

---

## Task 7: Update Employees index template

**Files:**
- Modify: `templates/Employees/index.php`

**Step 1: Replace export button and import modal**

Replace lines 14-64 (the header buttons + old import modal) with:

```php
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Empleados</span>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#exportExcelModal">
            <i class="bi bi-upload me-1"></i>Exportar
        </button>
        <?php if (!empty($userPermissions['employees']['can_create'])): ?>
        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importExcelModal">
            <i class="bi bi-download me-1"></i>Importar
        </button>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1"></i>Nuevo Empleado',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- Export Modal -->
<div class="modal fade" id="exportExcelModal" tabindex="-1" data-module="Employees">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Exportar Empleados</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center mb-2">
                    <input type="checkbox" class="form-check-input me-2" id="exportSelectAll" checked>
                    <label class="form-check-label" for="exportSelectAll" style="font-size:.875rem;font-weight:600">Seleccionar todos</label>
                </div>
                <div id="exportLoading" style="display:none" class="text-center py-3">
                    <span class="spinner-border spinner-border-sm me-1"></span>Cargando campos...
                </div>
                <div id="exportFieldList" style="max-height:400px;overflow-y:auto;border:1px solid var(--border-color);border-radius:4px"></div>
                <div id="exportError" class="text-danger mt-2" style="display:none;font-size:.825rem"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="exportBtn">
                    <i class="bi bi-download me-1"></i>Exportar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importExcelModal" tabindex="-1" data-module="Employees">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Importar Empleados desde Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Step 1: Upload -->
                <div id="importStep1">
                    <p style="font-size:.85rem;color:#666;">
                        Seleccione un archivo <code>.xlsx</code> para importar. El sistema detectará las columnas automáticamente y le permitirá configurar el mapeo.
                    </p>
                    <p style="font-size:.8rem;color:#999;">
                        <i class="bi bi-info-circle me-1"></i>Tip: Exporte primero para obtener la plantilla con las columnas correctas.
                    </p>
                    <input type="file" id="importFileInput" class="form-control" accept=".xlsx">
                </div>

                <!-- Step 2: Mapping -->
                <div id="importStep2" style="display:none">
                    <p style="font-size:.85rem;color:#666;margin-bottom:.75rem">
                        Configure el mapeo de columnas. Las columnas reconocidas se asignan automáticamente.
                    </p>
                    <div style="max-height:400px;overflow-y:auto">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th style="width:40px"></th>
                                    <th style="font-size:.8rem">Columna del archivo</th>
                                    <th style="font-size:.8rem">Campo del sistema</th>
                                    <th style="width:40px"></th>
                                </tr>
                            </thead>
                            <tbody id="importMappingBody"></tbody>
                        </table>
                    </div>
                    <div id="importRequiredIndicator" style="display:none"></div>
                    <div id="importMappingError" class="alert alert-danger py-2 px-3 mt-2" style="display:none;font-size:.825rem"></div>
                </div>

                <!-- Step 3: Results -->
                <div id="importStep3" style="display:none">
                    <div id="importResults"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-outline-secondary" id="importBackBtn" style="display:none">
                    <i class="bi bi-arrow-left me-1"></i>Volver
                </button>
                <button type="button" class="btn btn-primary" id="importUploadBtn">
                    <i class="bi bi-upload me-1"></i>Subir
                </button>
                <button type="button" class="btn btn-primary" id="importProcessBtn" style="display:none">
                    <i class="bi bi-check-lg me-1"></i>Importar
                </button>
                <button type="button" class="btn btn-primary" id="importCloseBtn" style="display:none">
                    Cerrar
                </button>
            </div>
        </div>
    </div>
</div>
```

**Step 2: Add script block at the end of the template (after line 232)**

Append at the bottom of the file:

```php
<?php $this->Html->script('https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js', ['block' => true]) ?>
<?php $this->Html->script('excel-mapper', ['block' => true]) ?>
```

**Step 3: Commit**

```bash
git add templates/Employees/index.php
git commit -m "feat(excel): update Employees index with export/import AJAX modals"
```

---

## Task 8: Add CSS for export field items

**Files:**
- Modify: `webroot/css/styles.css`

**Step 1: Add styles for the export field list and sortable ghost**

Append to end of `styles.css`:

```css
/* ─── Excel Mapper ─── */
.export-field-item {
    border-bottom: 1px solid var(--border-color);
}
.export-field-item:last-child {
    border-bottom: none;
}
.export-field-item:hover {
    background: #f8f9fa;
}
.sortable-ghost {
    opacity: 0.4;
    background: #e9ecef;
}
```

**Step 2: Commit**

```bash
git add webroot/css/styles.css
git commit -m "feat(excel): add CSS for export field list and sortable ghost"
```

---

## Task 9: Manual testing

**No files changed — testing only.**

**Step 1: Start dev server**

```bash
bin/cake server
```

**Step 2: Test export flow**

1. Navigate to `http://localhost:8765/employees`
2. Click "Exportar" → modal should open
3. Verify fields load with checkboxes and drag handles
4. Uncheck some fields, reorder others via drag
5. Click "Exportar" → XLSX should download
6. Open XLSX → verify Spanish headers, correct field order, only selected fields

**Step 3: Test import flow**

1. Click "Importar" → modal should open (Step 1)
2. Select the exported XLSX file
3. Click "Subir" → modal should show mapping table (Step 2)
4. Verify auto-mapping detected columns by Spanish labels
5. Adjust mapping if needed, uncheck some columns
6. Verify required field indicator shows correctly
7. Click "Importar" → should process and show results (Step 3)
8. Verify created/updated counts are correct

**Step 4: Test edge cases**

1. Import a file with English column headers → verify auto-mapping by field name
2. Import with missing required field (Cédula) unmapped → verify button disabled
3. Import file with extra unknown columns → verify they can be mapped or ignored
4. Import with new employees (not in DB) missing first_name → verify error message
5. Import with existing employees → verify update only mapped fields

**Step 5: Final commit (if any fixes needed)**

```bash
git add -A
git commit -m "fix(excel): address issues found during manual testing"
```

---

## Summary

| Task | Component | Description |
|------|-----------|-------------|
| 1 | ExcelMappingService | Field definitions, auto-mapping, validation |
| 2 | ExcelImportService | Mapped import with type casting and upsert |
| 3 | ExcelService | New `exportWithLabels()` method |
| 4 | EmployeesController | 4 AJAX endpoints (exportConfig, export, importUpload, importProcess) |
| 5 | routes.php | Routes for new AJAX endpoints |
| 6 | excel-mapper.js | Frontend modal logic with SortableJS |
| 7 | index.php | Updated modals for export and import |
| 8 | styles.css | CSS for field list and sortable |
| 9 | Testing | Manual verification of all flows |

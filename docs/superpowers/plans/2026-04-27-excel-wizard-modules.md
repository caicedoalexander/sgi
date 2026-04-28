# Excel Wizard Reusable Across SGI Modules — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract the Employees-style Excel import/export wizard into a reusable interface + trait so any module can opt in with a 3-step drop-in. Migrate Employees, Providers, Invoices (export-only), and 8 catalog modules to use it. Delete the legacy `ExcelCatalogTrait` path.

**Architecture:** A Table that implements `ExcelExportableInterface` declares its field map. A controller that `use`s `ExcelWizardTrait` exposes the four wizard actions (`exportConfig`, `export`, `importUpload`, `importProcess`). Two reusable view elements (`excel_wizard/buttons`, `excel_wizard/modals`) render the UI. Existing services (`ExcelMappingService`, `ExcelImportService`, `ExcelService`) are decoupled from the `Employees` module.

**Tech Stack:** CakePHP 5.3 / PHP 8.2+, PhpSpreadsheet, Bootstrap 5 modals, vanilla JS (`webroot/js/excel-mapper.js`), SortableJS, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-04-27-excel-wizard-modules-design.md`.

---

## File Map

### New files
| Path | Responsibility |
|---|---|
| `src/Model/Excel/ExcelExportableInterface.php` | Contract Tables must implement to participate. |
| `src/Model/Excel/ExcelExportableTrait.php` | Default implementations for hooks and flags. |
| `src/Controller/Trait/ExcelWizardTrait.php` | The four wizard HTTP actions. |
| `templates/element/excel_wizard/buttons.php` | Export/Import buttons partial. |
| `templates/element/excel_wizard/modals.php` | Export modal + Import wizard modal partial; loads `excel-mapper.js`. |
| `tests/TestCase/Service/ExcelMappingServiceTest.php` | Unit tests for the mapping service. |
| `tests/TestCase/Service/ExcelImportServiceTest.php` | Unit tests for the import service. |
| `tests/TestCase/Controller/Trait/ExcelWizardTraitTest.php` | Integration test for wizard actions. |

### Modified files
| Path | Change |
|---|---|
| `src/Service/ExcelMappingService.php` | Drop `FIELD_DEFINITIONS` constant; methods accept Table or array. |
| `src/Service/ExcelImportService.php` | Use Table hooks; drop `Employee` type; drop debug log. |
| `src/Service/ExcelService.php` | Remove `exportCatalog` and `importCatalog`. |
| `src/Controller/AppController.php` | Add `exportConfig`/`importUpload`/`importProcess` to `_actionToPermission`. |
| `webroot/js/excel-mapper.js` | Read `data-download-slug` from export modal. |
| `src/Model/Table/EmployeesTable.php` | Implement interface; declare fields; on-import hooks. |
| `src/Model/Table/ProvidersTable.php` | Implement interface; declare fields. |
| `src/Model/Table/InvoicesTable.php` | Implement interface; `isExcelImportable() = false`. |
| `src/Model/Table/{CostCenters,OperationCenters,Positions,TemporaryOrganizations,EducationLevels,MaritalStatuses,EmployeeStatuses,DefaultFolders}Table.php` | Implement interface. |
| `src/Controller/EmployeesController.php` | Switch to trait; remove inline actions. |
| `src/Controller/ProvidersController.php` | Switch to trait; remove ad-hoc export/import. |
| `src/Controller/InvoicesController.php` | Switch to trait; remove inline export. |
| `src/Controller/{CostCenters,OperationCenters,Positions,TemporaryOrganizations,EducationLevels,MaritalStatuses,EmployeeStatuses,DefaultFolders}Controller.php` | Replace `ExcelCatalogTrait` with `ExcelWizardTrait`. |
| `templates/Employees/index.php` | Replace inline modals with element. |
| `templates/Providers/index.php` | Add wizard buttons + modals element. |
| `templates/Invoices/index.php` | Add wizard buttons + modals element (export only). |
| `templates/{CostCenters,OperationCenters,Positions,TemporaryOrganizations,EducationLevels,MaritalStatuses,EmployeeStatuses,DefaultFolders}/index.php` | Swap `catalog_excel_buttons` element for new elements. |

### Deleted files / code
- `src/Controller/Trait/ExcelCatalogTrait.php`
- `templates/element/catalog_excel_buttons.php`
- `ExcelService::exportCatalog()` and `ExcelService::importCatalog()` methods
- `ExcelMappingService::FIELD_DEFINITIONS` constant
- Inline `export` action in `InvoicesController`, inline `export`/`import` in `ProvidersController`, inline `exportConfig`/`export`/`importUpload`/`importProcess` in `EmployeesController`
- `file_put_contents(TMP . 'import_debug.log', …)` line in `ExcelImportService`

---

## Conventions for every task

- Use `composer test` to run PHPUnit and `composer cs-fix` after editing PHP. Run `composer check` at end of each task block.
- Commits use conventional commit prefixes (`feat:`, `refactor:`, `test:`, `chore:`).
- Co-author trailer: `Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>`.
- After each task that includes a Smoke Test step, do not proceed if smoke fails.

---

## Phase 1 — Core infrastructure (no module migrations yet)

### Task 1: Create `ExcelExportableInterface`

**Files:**
- Create: `src/Model/Excel/ExcelExportableInterface.php`

- [ ] **Step 1: Create the interface file**

```php
<?php
declare(strict_types=1);

namespace App\Model\Excel;

use Cake\Datasource\EntityInterface;

/**
 * Tables that opt into the Excel wizard implement this interface.
 *
 * Field definition shape (returned by getExcelFields()):
 *   'field_name' => [
 *     'label'        => string,                    // Spanish header in UI/export
 *     'type'         => 'string'|'date'|'decimal'|'integer'|'boolean',
 *     'required'?    => bool,                      // mapping must include this on import
 *     'required_new'?=> bool,                      // mandatory only when creating a new row
 *     'is_key'?      => bool,                      // upsert key (exactly one per Table)
 *     'aliases'?     => array<string>,             // alternative file headers for auto-mapping
 *     'fk'?          => bool,                      // foreign key resolved from a code/name
 *     'fk_table'?    => string,                    // ORM table alias of the related entity
 *     'fk_code'?     => string,                    // column in fk_table holding the code
 *     'display_only'?=> bool,                      // exported only; on import resolves via fk_resolve
 *     'fk_resolve'?  => string,                    // column in fk_table to look up by name
 *     'fk_target'?   => string,                    // sibling field receiving the resolved id
 *   ]
 */
interface ExcelExportableInterface
{
    /** @return array<string, array<string, mixed>> */
    public function getExcelFields(): array;

    public function getExcelSheetTitle(): string;

    public function getExcelDownloadSlug(): string;

    public function isExcelImportable(): bool;

    /** @return array<int|string, mixed> */
    public function getExcelExportContains(): array;

    public function onExcelImportCreated(EntityInterface $entity, int $userId): void;

    public function onExcelImportUpdated(EntityInterface $original, EntityInterface $entity, int $userId): void;
}
```

- [ ] **Step 2: Run static checks**

Run: `composer cs-check`
Expected: PASS (or only warnings unrelated to the new file).

- [ ] **Step 3: Commit**

```bash
git add src/Model/Excel/ExcelExportableInterface.php
git commit -m "$(cat <<'EOF'
feat(excel): add ExcelExportableInterface for Table-driven wizard config

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Create `ExcelExportableTrait` with default hook implementations

**Files:**
- Create: `src/Model/Excel/ExcelExportableTrait.php`

- [ ] **Step 1: Create the trait**

```php
<?php
declare(strict_types=1);

namespace App\Model\Excel;

use Cake\Datasource\EntityInterface;

/**
 * Default no-op implementations for the optional hooks of ExcelExportableInterface.
 * Tables still must implement getExcelFields(), getExcelSheetTitle(), getExcelDownloadSlug().
 */
trait ExcelExportableTrait
{
    public function isExcelImportable(): bool
    {
        return true;
    }

    public function getExcelExportContains(): array
    {
        return [];
    }

    public function onExcelImportCreated(EntityInterface $entity, int $userId): void
    {
    }

    public function onExcelImportUpdated(EntityInterface $original, EntityInterface $entity, int $userId): void
    {
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Model/Excel/ExcelExportableTrait.php
git commit -m "$(cat <<'EOF'
feat(excel): add ExcelExportableTrait with default hook implementations

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Characterization tests for `ExcelMappingService` (capture current behavior)

**Files:**
- Create: `tests/TestCase/Service/ExcelMappingServiceTest.php`

- [ ] **Step 1: Create the test directory if missing**

Run: `mkdir -p tests/TestCase/Service`

- [ ] **Step 2: Write tests against the **current** service (with hardcoded constant)**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ExcelMappingService;
use Cake\TestSuite\TestCase;

class ExcelMappingServiceTest extends TestCase
{
    private ExcelMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExcelMappingService();
    }

    public function testGetExportableFieldsReturnsAllEmployeeFieldsCheckedTrue(): void
    {
        $fields = $this->service->getExportableFields('Employees');
        $this->assertNotEmpty($fields);
        foreach ($fields as $f) {
            $this->assertArrayHasKey('field', $f);
            $this->assertArrayHasKey('label', $f);
            $this->assertTrue($f['checked']);
        }
        $names = array_column($fields, 'field');
        $this->assertContains('document_number', $names);
        $this->assertContains('first_name', $names);
    }

    public function testGetImportableFieldsExcludesPureDisplayOnly(): void
    {
        $fields = $this->service->getImportableFields('Employees');
        $names = array_column($fields, 'field');
        // 'position' is display_only WITH fk_resolve → included
        $this->assertContains('position', $names);
        // No pure display_only-without-fk_resolve fields exist in the current map,
        // but the contract is preserved:
        foreach ($fields as $f) {
            $this->assertArrayHasKey('required', $f);
        }
    }

    public function testAutoMapColumnsRecognizesLabelAliasAndFieldName(): void
    {
        $headers = ['Cédula', 'apellidos', 'first_name', 'no_existe'];
        $map = $this->service->autoMapColumns($headers, 'Employees');
        $this->assertSame('document_number', $map['Cédula']);
        $this->assertSame('last_name1', $map['apellidos']);
        $this->assertSame('first_name', $map['first_name']);
        $this->assertNull($map['no_existe']);
    }

    public function testValidateMappingReturnsErrorWhenRequiredMissing(): void
    {
        $mapping = ['Nombres' => 'first_name']; // document_number is required, missing
        $errors = $this->service->validateMapping($mapping, 'Employees');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Cédula', $errors[0]);
    }

    public function testValidateMappingPassesWhenRequiredMapped(): void
    {
        $mapping = [
            'Cédula' => 'document_number',
            'Nombres' => 'first_name',
        ];
        $errors = $this->service->validateMapping($mapping, 'Employees');
        $this->assertSame([], $errors);
    }

    public function testGetLabelMapHasSpanishLabels(): void
    {
        $labels = $this->service->getLabelMap('Employees');
        $this->assertSame('Cédula', $labels['document_number']);
        $this->assertSame('Nombres', $labels['first_name']);
    }
}
```

- [ ] **Step 3: Run the tests against the current code**

Run: `composer test -- --filter ExcelMappingServiceTest`
Expected: PASS (we're capturing current behavior).

- [ ] **Step 4: Commit**

```bash
git add tests/TestCase/Service/ExcelMappingServiceTest.php
git commit -m "$(cat <<'EOF'
test(excel): characterization tests for ExcelMappingService

Captures current behavior before refactoring the service to drop the
hardcoded FIELD_DEFINITIONS constant.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Refactor `ExcelMappingService` to accept a Table or fields array

**Files:**
- Modify: `src/Service/ExcelMappingService.php`
- Modify: `tests/TestCase/Service/ExcelMappingServiceTest.php`

- [ ] **Step 1: Replace the service file**

Overwrite `src/Service/ExcelMappingService.php` with:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Excel\ExcelExportableInterface;

final class ExcelMappingService
{
    /**
     * Resolve definitions from either a Table implementing the interface
     * or an already-built field array.
     *
     * @param ExcelExportableInterface|array<string, array<string, mixed>> $source
     * @return array<string, array<string, mixed>>
     */
    public function getFieldDefinitions(ExcelExportableInterface|array $source): array
    {
        return $source instanceof ExcelExportableInterface ? $source->getExcelFields() : $source;
    }

    /**
     * @param ExcelExportableInterface|array<string, array<string, mixed>> $source
     * @return array<int, array{field: string, label: string, checked: bool}>
     */
    public function getExportableFields(ExcelExportableInterface|array $source): array
    {
        $fields = [];
        foreach ($this->getFieldDefinitions($source) as $field => $def) {
            $fields[] = [
                'field' => $field,
                'label' => $def['label'],
                'checked' => true,
            ];
        }

        return $fields;
    }

    /**
     * @param ExcelExportableInterface|array<string, array<string, mixed>> $source
     * @return array<int, array{field: string, label: string, required: bool}>
     */
    public function getImportableFields(ExcelExportableInterface|array $source): array
    {
        $fields = [];
        foreach ($this->getFieldDefinitions($source) as $field => $def) {
            if (!empty($def['display_only']) && empty($def['fk_resolve'])) {
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
     * @param ExcelExportableInterface|array<string, array<string, mixed>> $source
     * @return array<string, string>
     */
    public function buildAutoMapLookup(ExcelExportableInterface|array $source): array
    {
        $lookup = [];
        foreach ($this->getFieldDefinitions($source) as $field => $def) {
            $lookup[mb_strtolower(trim($def['label']))] = $field;
            $lookup[mb_strtolower($field)] = $field;
            if (!empty($def['aliases'])) {
                foreach ($def['aliases'] as $alias) {
                    $lookup[mb_strtolower(trim($alias))] = $field;
                }
            }
        }

        return $lookup;
    }

    /**
     * @param array<string> $fileHeaders
     * @param ExcelExportableInterface|array<string, array<string, mixed>> $source
     * @return array<string, string|null>
     */
    public function autoMapColumns(array $fileHeaders, ExcelExportableInterface|array $source): array
    {
        $lookup = $this->buildAutoMapLookup($source);
        $mapping = [];
        foreach ($fileHeaders as $header) {
            $normalized = mb_strtolower(trim($header));
            $mapping[$header] = $lookup[$normalized] ?? null;
        }

        return $mapping;
    }

    /**
     * @param array<string, string|null> $mapping
     * @param ExcelExportableInterface|array<string, array<string, mixed>> $source
     * @return array<int, string>
     */
    public function validateMapping(array $mapping, ExcelExportableInterface|array $source): array
    {
        $definitions = $this->getFieldDefinitions($source);
        $mappedFields = array_filter(array_values($mapping));
        $errors = [];
        foreach ($definitions as $field => $def) {
            if (!empty($def['required']) && !in_array($field, $mappedFields, true)) {
                $errors[] = "El campo obligatorio \"{$def['label']}\" no está mapeado.";
            }
        }

        return $errors;
    }

    /**
     * @param ExcelExportableInterface|array<string, array<string, mixed>> $source
     * @return array<string, string>
     */
    public function getLabelMap(ExcelExportableInterface|array $source): array
    {
        $map = [];
        foreach ($this->getFieldDefinitions($source) as $field => $def) {
            $map[$field] = $def['label'];
        }

        return $map;
    }
}
```

- [ ] **Step 2: Update the characterization tests to use a fixed array (not the deleted constant)**

The tests previously called `$service->getExportableFields('Employees')` — that string-based API no longer exists. Replace the test file with the array-driven version:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ExcelMappingService;
use Cake\TestSuite\TestCase;

class ExcelMappingServiceTest extends TestCase
{
    private ExcelMappingService $service;
    /** @var array<string, array<string, mixed>> */
    private array $fields;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExcelMappingService();
        $this->fields = [
            'document_number' => [
                'label' => 'Cédula', 'type' => 'string',
                'required' => true, 'is_key' => true,
                'aliases' => ['empleado'],
            ],
            'first_name' => ['label' => 'Nombres', 'type' => 'string', 'required_new' => true],
            'last_name1' => [
                'label' => 'Primer Apellido', 'type' => 'string', 'required_new' => true,
                'aliases' => ['apellidos'],
            ],
            'position' => [
                'label' => 'Cargo', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'Positions', 'fk_target' => 'position_id',
            ],
            'profile_image' => [
                'label' => 'Imagen', 'type' => 'string', 'display_only' => true,
            ],
        ];
    }

    public function testGetExportableFieldsAllCheckedTrue(): void
    {
        $fields = $this->service->getExportableFields($this->fields);
        $this->assertCount(5, $fields);
        foreach ($fields as $f) {
            $this->assertTrue($f['checked']);
        }
    }

    public function testGetImportableFieldsExcludesPureDisplayOnly(): void
    {
        $fields = $this->service->getImportableFields($this->fields);
        $names = array_column($fields, 'field');
        $this->assertContains('position', $names);          // display_only + fk_resolve → included
        $this->assertNotContains('profile_image', $names);  // pure display_only → excluded
    }

    public function testAutoMapColumnsRecognizesLabelAliasFieldName(): void
    {
        $headers = ['Cédula', 'apellidos', 'first_name', 'no_existe'];
        $map = $this->service->autoMapColumns($headers, $this->fields);
        $this->assertSame('document_number', $map['Cédula']);
        $this->assertSame('last_name1', $map['apellidos']);
        $this->assertSame('first_name', $map['first_name']);
        $this->assertNull($map['no_existe']);
    }

    public function testValidateMappingDetectsMissingRequired(): void
    {
        $mapping = ['Nombres' => 'first_name'];
        $errors = $this->service->validateMapping($mapping, $this->fields);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Cédula', $errors[0]);
    }

    public function testValidateMappingPassesWhenRequiredMapped(): void
    {
        $mapping = ['Cédula' => 'document_number', 'Nombres' => 'first_name'];
        $errors = $this->service->validateMapping($mapping, $this->fields);
        $this->assertSame([], $errors);
    }

    public function testGetLabelMapReturnsFieldToLabelDict(): void
    {
        $labels = $this->service->getLabelMap($this->fields);
        $this->assertSame('Cédula', $labels['document_number']);
        $this->assertSame('Nombres', $labels['first_name']);
    }
}
```

- [ ] **Step 3: Run tests**

Run: `composer test -- --filter ExcelMappingServiceTest`
Expected: All 6 tests pass.

- [ ] **Step 4: Run cs-fix and commit**

```bash
composer cs-fix
git add src/Service/ExcelMappingService.php tests/TestCase/Service/ExcelMappingServiceTest.php
git commit -m "$(cat <<'EOF'
refactor(excel): make ExcelMappingService Table/array driven

Drops the hardcoded FIELD_DEFINITIONS constant. Methods now accept either
an ExcelExportableInterface Table or a definitions array directly.
Behavior unchanged.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Refactor `ExcelImportService` to use Table hooks

**Files:**
- Modify: `src/Service/ExcelImportService.php`

- [ ] **Step 1: Rewrite the service**

Overwrite `src/Service/ExcelImportService.php` with:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Excel\ExcelExportableInterface;
use Cake\ORM\TableRegistry;
use DateTime;
use DateTimeInterface;
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
     * @return array<string>
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

        return array_values(array_filter($headers, fn($h) => $h !== ''));
    }

    /**
     * Process a previously-uploaded Excel file using a user-provided column mapping.
     *
     * @param object&ExcelExportableInterface $table A Table implementing the interface.
     *        Both an ORM table type and the interface are required, but PHP cannot express
     *        the intersection in a portable signature; runtime check enforces it.
     * @param array<string, string> $mapping file_header => system_field
     * @param array<string> $enabledHeaders headers the user kept enabled in the wizard
     */
    public function processImport(
        string $tempFilePath,
        ExcelExportableInterface $table,
        array $mapping,
        array $enabledHeaders,
        int $userId,
    ): ImportResult {
        $result = new ImportResult();
        $definitions = $table->getExcelFields();

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

        $validationErrors = $this->mappingService->validateMapping($mapping, $definitions);
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
        $skipSystemFields = ['id', 'created', 'modified', 'profile_image'];

        $fkCodeLookups = $this->buildFkLookups($definitions);
        $fkNameLookups = $this->buildFkNameLookups($definitions);

        $rowCount = count($rows);
        for ($i = 1; $i < $rowCount; $i++) {
            $rowData = [];
            $rowNum = $i + 1;

            foreach ($headers as $col => $header) {
                if (!in_array($header, $enabledHeaders, true)) {
                    continue;
                }
                $systemField = $mapping[$header] ?? null;
                if (!$systemField) {
                    continue;
                }
                if (in_array($systemField, $skipSystemFields, true)) {
                    continue;
                }
                $fieldDef = $definitions[$systemField] ?? null;
                $rawValue = $rows[$i][$col] ?? null;
                $castValue = $this->castValue($rawValue, $fieldDef['type'] ?? 'string');

                if (!empty($fieldDef['display_only']) && !empty($fieldDef['fk_resolve'])) {
                    $nameStr = trim((string)$castValue);
                    if ($nameStr !== '' && isset($fkNameLookups[$systemField])) {
                        $resolvedId = $fkNameLookups[$systemField][$nameStr]
                            ?? $fkNameLookups[$systemField][mb_strtolower($nameStr)]
                            ?? null;
                        if ($resolvedId === null) {
                            $result->errors[] = "Fila {$rowNum}: {$fieldDef['label']} \"{$nameStr}\" no encontrado.";
                            continue;
                        }
                        $targetField = $fieldDef['fk_target'];
                        if (!isset($rowData[$targetField])) {
                            $rowData[$targetField] = $resolvedId;
                        }
                    }
                    continue;
                }

                if (!empty($fieldDef['display_only'])) {
                    continue;
                }

                if (!empty($fieldDef['fk']) && !empty($fieldDef['fk_code']) && $castValue !== null) {
                    $codeStr = trim((string)$castValue);
                    if ($codeStr !== '' && isset($fkCodeLookups[$systemField])) {
                        $resolvedId = $fkCodeLookups[$systemField][$codeStr] ?? null;
                        if ($resolvedId === null) {
                            $result->errors[] = "Fila {$rowNum}: {$fieldDef['label']} \"{$codeStr}\" no encontrado.";
                            continue;
                        }
                        $castValue = $resolvedId;
                    } else {
                        $castValue = null;
                    }
                }

                $rowData[$systemField] = $castValue;
            }

            $keyValue = trim((string)($rowData[$keyField] ?? ''));
            if ($keyValue === '') {
                $result->skipped++;
                continue;
            }

            $existing = $table->find()
                ->where([$keyField => $keyValue])
                ->first();

            if ($existing) {
                $changedData = $this->filterChangedFields($existing, $rowData, $definitions);
                if (empty($changedData)) {
                    $result->unchanged++;
                    continue;
                }
                $originalClone = clone $existing;
                $entity = $table->patchEntity($existing, $changedData);
                if ($table->save($entity)) {
                    $result->updated++;
                    $table->onExcelImportUpdated($originalClone, $entity, $userId);
                } else {
                    $result->errors[] = $this->formatEntityErrors($entity, $rowNum, $definitions);
                }
            } else {
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
                    $table->onExcelImportCreated($entity, $userId);
                } else {
                    $result->errors[] = $this->formatEntityErrors($entity, $rowNum, $definitions);
                }
            }
        }

        return $result;
    }

    private function formatEntityErrors(object $entity, int $rowNum, array $definitions): string
    {
        $errors = method_exists($entity, 'getErrors') ? $entity->getErrors() : [];
        $msg = "Fila {$rowNum}: ";
        foreach ($errors as $field => $fieldErrors) {
            $label = $definitions[$field]['label'] ?? $field;
            $msg .= "{$label}: " . implode(', ', $fieldErrors) . '. ';
        }

        return trim($msg);
    }

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
        if (is_numeric($value) && (float)$value > 1000) {
            try {
                $dateObj = Date::excelToDateTimeObject((float)$value);

                return $dateObj->format('Y-m-d');
            } catch (Exception) {
                return null;
            }
        }

        $strValue = trim((string)$value);
        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'm/d/Y'];
        foreach ($formats as $format) {
            $parsed = DateTime::createFromFormat($format, $strValue);
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

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @return array<string, array<string, int>>
     */
    private function buildFkLookups(array $definitions): array
    {
        $lookups = [];
        foreach ($definitions as $field => $def) {
            if (empty($def['fk']) || empty($def['fk_table']) || empty($def['fk_code'])) {
                continue;
            }
            $fkTable = TableRegistry::getTableLocator()->get($def['fk_table']);
            $codeField = $def['fk_code'];
            $rows = $fkTable->find()->select(['id', $codeField])
                ->where(["{$codeField} IS NOT" => null])->all();
            $map = [];
            foreach ($rows as $row) {
                $code = trim((string)$row->{$codeField});
                if ($code !== '') {
                    $map[$code] = $row->id;
                }
            }
            $lookups[$field] = $map;
        }

        return $lookups;
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @return array<string, array<string, int>>
     */
    private function buildFkNameLookups(array $definitions): array
    {
        $lookups = [];
        foreach ($definitions as $field => $def) {
            if (empty($def['display_only']) || empty($def['fk_resolve']) || empty($def['fk_table'])) {
                continue;
            }
            $fkTable = TableRegistry::getTableLocator()->get($def['fk_table']);
            $nameField = $def['fk_resolve'];
            $rows = $fkTable->find()->select(['id', $nameField])
                ->where(["{$nameField} IS NOT" => null])->all();
            $map = [];
            foreach ($rows as $row) {
                $name = trim((string)$row->{$nameField});
                if ($name !== '') {
                    $map[$name] = $row->id;
                    $map[mb_strtolower($name)] = $row->id;
                }
            }
            $lookups[$field] = $map;
        }

        return $lookups;
    }

    private function normalizeForComparison(mixed $value, string $type): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_object($value) && method_exists($value, 'toNative')) {
            return $value->toNative()->format('Y-m-d');
        }
        if ($value instanceof DateTimeInterface) {
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

    /**
     * @param array<string, mixed> $rowData
     * @param array<string, array<string, mixed>> $definitions
     * @return array<string, mixed>
     */
    private function filterChangedFields(object $existing, array $rowData, array $definitions): array
    {
        $changed = [];
        foreach ($rowData as $field => $newValue) {
            $type = $definitions[$field]['type'] ?? 'string';
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
}
```

Notable changes versus the previous version:
- `processImport()` signature changed: takes the Table (which **is** a CakePHP Table that also implements `ExcelExportableInterface`) and `$userId`. No more `$module`/`$tableName` strings, no more `$onCreated` callback, no more `$historyService`/`?int` history parameters.
- Hooks invoked on the Table: `onExcelImportCreated`, `onExcelImportUpdated`.
- Removed `EmployeeHistoryService` and `Employee` imports.
- Removed `file_put_contents(TMP . 'import_debug.log', …)` debug write.

- [ ] **Step 2: Run cs-fix**

Run: `composer cs-fix`
Expected: PASS.

- [ ] **Step 3: Run static checks** (the existing controller and tests still reference the old signature — they'll fail. Note this in commit log; we fix in Tasks 6 and 11.)

Run: `composer test -- --filter ExcelMappingServiceTest`
Expected: PASS (mapping tests are unaffected).

Run: `composer test`
Expected: FAIL — `EmployeesController` still calls the old `processImport` signature. This is acceptable here; Task 11 fixes it.

- [ ] **Step 4: Commit**

```bash
git add src/Service/ExcelImportService.php
git commit -m "$(cat <<'EOF'
refactor(excel): wire ExcelImportService through Table-level hooks

processImport() no longer receives the EmployeeHistoryService or an
onCreated callback; it calls $table->onExcelImportCreated() and
onExcelImportUpdated() so any module can plug in its own side effects.
Drops the import_debug.log writeline.

Note: EmployeesController still calls the old signature; fixed in a
follow-up commit.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Add the wizard actions to `_actionToPermission`

**Files:**
- Modify: `src/Controller/AppController.php:60-69`

- [ ] **Step 1: Edit the method**

Open `src/Controller/AppController.php` and change `_actionToPermission`:

```php
    protected function _actionToPermission(string $action): string
    {
        return match ($action) {
            'index', 'view', 'export', 'exportConfig', 'all', 'rejected', 'exportPdf', 'preview', 'active', 'activeEvents', 'allEvents' => 'view',
            'add', 'addFolder', 'uploadDocument', 'import', 'importExcel', 'importUpload', 'importProcess', 'previewImport', 'confirmImport', 'addItem', 'uploadAttachment', 'addPayment' => 'add',
            'edit', 'advanceStatus', 'addObservation', 'testSmtp', 'approve', 'reject', 'deactivate', 'saveFields', 'removeInvoice', 'advance', 'advanceGroup', 'addSignature', 'assignLiquidation', 'getFlags', 'authorizePayment', 'rejectPayment', 'editPayment', 'sendApprovalLinks', 'modifyApprovers', 'resetFlow', 'upload' => 'edit',
            'delete', 'deleteDocument', 'removeItem', 'deleteAttachment' => 'delete',
            default => 'view',
        };
    }
```

Two strings added: `'exportConfig'` to the view branch and `'importUpload', 'importProcess'` to the add branch.

- [ ] **Step 2: Commit**

```bash
git add src/Controller/AppController.php
git commit -m "$(cat <<'EOF'
feat(rbac): map excel wizard actions to view/add permissions

exportConfig → view, importUpload/importProcess → add. Mirrors the
existing export/import mapping so role-based access works for the
new wizard endpoints.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Create `ExcelWizardTrait` for controllers

**Files:**
- Create: `src/Controller/Trait/ExcelWizardTrait.php`

- [ ] **Step 1: Create the trait**

```php
<?php
declare(strict_types=1);

namespace App\Controller\Trait;

use App\Model\Excel\ExcelExportableInterface;
use App\Service\ExcelImportService;
use App\Service\ExcelMappingService;
use App\Service\ExcelService;
use ArrayObject;
use Cake\Http\Response;
use Exception;
use LogicException;

/**
 * HTTP wizard for Excel export/import. The controller's primary Table
 * (returned by fetchTable()) MUST implement ExcelExportableInterface.
 *
 * Endpoints:
 *   GET  /<controller>/export-config   → JSON exportable fields
 *   POST /<controller>/export          → XLSX download (selected fields)
 *   POST /<controller>/import-upload   → JSON tempName + auto-mapping
 *   POST /<controller>/import-process  → JSON ImportResult summary
 */
trait ExcelWizardTrait
{
    private function _excelTable(): ExcelExportableInterface
    {
        $table = $this->fetchTable();
        if (!$table instanceof ExcelExportableInterface) {
            throw new LogicException(sprintf(
                '%s must implement %s to use ExcelWizardTrait.',
                $table::class,
                ExcelExportableInterface::class,
            ));
        }

        return $table;
    }

    public function exportConfig(): void
    {
        $this->request->allowMethod(['get']);
        $this->viewBuilder()->setClassName('Json');

        $fields = (new ExcelMappingService())->getExportableFields($this->_excelTable());

        $this->set('fields', $fields);
        $this->viewBuilder()->setOption('serialize', ['fields']);
    }

    public function export(): ?Response
    {
        $this->request->allowMethod(['post']);

        $table = $this->_excelTable();
        $mapping = new ExcelMappingService();
        $allDefinitions = $table->getExcelFields();

        $requestFields = $this->request->getData('fields');
        if (empty($requestFields) || !is_array($requestFields)) {
            return $this->_excelJsonError(400, 'No se seleccionaron campos para exportar.');
        }

        $validFields = array_values(array_filter($requestFields, fn($f) => isset($allDefinitions[$f])));
        if (empty($validFields)) {
            return $this->_excelJsonError(400, 'Ningún campo válido seleccionado.');
        }

        $query = $table->find();
        $contains = $table->getExcelExportContains();
        if (!empty($contains)) {
            $query->contain($contains);
        }

        $query->formatResults(function ($results) use ($validFields, $allDefinitions) {
            return $results->map(function ($entity) use ($validFields, $allDefinitions) {
                $data = [];
                foreach ($validFields as $field) {
                    $def = $allDefinitions[$field];
                    if (!empty($def['display_only'])) {
                        $rel = $def['fk_target'] ?? null;
                        if ($rel && isset($def['fk_resolve'])) {
                            // The display_only field's name (e.g. 'position') is also
                            // the association alias (lowerCamel). Resolve via that path.
                            $assoc = $field; // matches the property name on the entity
                            $related = $entity->{$assoc} ?? null;
                            $data[$field] = $related ? ($related->{$def['fk_resolve']} ?? '') : '';
                        } else {
                            $data[$field] = '';
                        }
                    } elseif (!empty($def['fk']) && !empty($def['fk_code'])) {
                        // Export FK as the related entity's code rather than numeric id
                        $assoc = preg_replace('/_id$/', '', $field);
                        $related = $entity->{$assoc} ?? null;
                        $data[$field] = $related ? ($related->{$def['fk_code']} ?? '') : '';
                    } else {
                        $data[$field] = $entity->{$field} ?? '';
                    }
                }

                return new ArrayObject($data);
            });
        });

        $excelService = new ExcelService();
        $filePath = $excelService->exportWithLabels(
            $table->getExcelSheetTitle(),
            $query,
            $validFields,
            $mapping->getLabelMap($table),
        );

        $response = $this->response->withFile($filePath, [
            'download' => true,
            'name' => $table->getExcelDownloadSlug() . '_' . date('Y-m-d') . '.xlsx',
        ]);

        register_shutdown_function(function () use ($filePath): void {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        });

        return $response;
    }

    public function importUpload(): void
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setClassName('Json');

        $table = $this->_excelTable();
        if (!$table->isExcelImportable()) {
            $this->response = $this->response->withStatus(405);
            $this->set('error', 'Importación no permitida en este módulo.');
            $this->viewBuilder()->setOption('serialize', ['error']);

            return;
        }

        $file = $this->request->getUploadedFile('excel_file');
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $this->response = $this->response->withStatus(400);
            $this->set('error', 'No se recibió un archivo válido.');
            $this->viewBuilder()->setOption('serialize', ['error']);

            return;
        }

        $tempName = 'sgi_import_' . bin2hex(random_bytes(8));
        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tempName . '.xlsx';
        $file->moveTo($tempPath);

        $importService = new ExcelImportService();
        $mapping = new ExcelMappingService();

        try {
            $headers = $importService->readHeaders($tempPath);
            $autoMapping = $mapping->autoMapColumns($headers, $table);
            $systemFields = $mapping->getImportableFields($table);

            $this->set(compact('tempName', 'headers', 'autoMapping', 'systemFields'));
            $this->viewBuilder()->setOption('serialize', ['tempName', 'headers', 'autoMapping', 'systemFields']);
        } catch (Exception $e) {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            $this->response = $this->response->withStatus(400);
            $this->set('error', $e->getMessage());
            $this->viewBuilder()->setOption('serialize', ['error']);
        }
    }

    public function importProcess(): void
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setClassName('Json');

        $table = $this->_excelTable();
        if (!$table->isExcelImportable()) {
            $this->response = $this->response->withStatus(405);
            $this->set('error', 'Importación no permitida en este módulo.');
            $this->viewBuilder()->setOption('serialize', ['error']);

            return;
        }

        $tempName = $this->request->getData('temp_file');
        $mappingData = $this->request->getData('mapping');
        $enabledHeaders = $this->request->getData('enabled');

        if (!$tempName || !$mappingData || !$enabledHeaders) {
            $this->response = $this->response->withStatus(400);
            $this->set('error', 'Datos de importación incompletos.');
            $this->viewBuilder()->setOption('serialize', ['error']);

            return;
        }

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
            $userId = (int)$this->request->getAttribute('identity')->getIdentifier();
            $importService = new ExcelImportService();
            $result = $importService->processImport($tempPath, $table, $mappingData, $enabledHeaders, $userId);

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
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    private function _excelJsonError(int $status, string $message): ?Response
    {
        $this->response = $this->response->withStatus($status);
        $this->viewBuilder()->setClassName('Json');
        $this->set('error', $message);
        $this->viewBuilder()->setOption('serialize', ['error']);

        return null;
    }
}
```

- [ ] **Step 2: Run cs-fix**

Run: `composer cs-fix`

- [ ] **Step 3: Commit**

```bash
git add src/Controller/Trait/ExcelWizardTrait.php
git commit -m "$(cat <<'EOF'
feat(excel): add ExcelWizardTrait with the four wizard HTTP actions

The trait reads the controller's primary Table (which must implement
ExcelExportableInterface) and exposes exportConfig/export/importUpload/
importProcess. Modules opt into import/export with a single 'use' line
plus the Table-level interface.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Create the two view elements

**Files:**
- Create: `templates/element/excel_wizard/buttons.php`
- Create: `templates/element/excel_wizard/modals.php`

- [ ] **Step 1: Create the buttons element**

```bash
mkdir -p templates/element/excel_wizard
```

Create `templates/element/excel_wizard/buttons.php`:

```php
<?php
/**
 * Excel wizard buttons (Exportar / Importar).
 *
 * @var \App\View\AppView $this
 * @var string $module      Camel-cased module name, e.g. 'Employees'
 * @var bool   $importable  Show Import button when true
 * @var bool   $canCreate   User has can_create on the module (for Import visibility)
 */
$importable = $importable ?? true;
$canCreate = $canCreate ?? false;
?>
<button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#exportExcelModal">
    <i class="bi bi-upload me-1"></i>Exportar
</button>
<?php if ($importable && $canCreate): ?>
<button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importExcelModal">
    <i class="bi bi-download me-1"></i>Importar
</button>
<?php endif; ?>
```

- [ ] **Step 2: Create the modals element**

Create `templates/element/excel_wizard/modals.php`:

```php
<?php
/**
 * Excel wizard modals — export field selector + import 3-step wizard.
 *
 * @var \App\View\AppView $this
 * @var string $module       Camel-cased, e.g. 'Employees'
 * @var string $entityName   Plural Spanish label, e.g. 'Empleados'
 * @var string $downloadSlug Lower-snake slug for filename, e.g. 'empleados'
 * @var bool   $importable
 */
$importable = $importable ?? true;
?>
<!-- Export Modal -->
<div class="modal fade" id="exportExcelModal" tabindex="-1"
     data-module="<?= h($module) ?>" data-download-slug="<?= h($downloadSlug) ?>">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Exportar <?= h($entityName) ?></h5>
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

<?php if ($importable): ?>
<!-- Import Modal -->
<div class="modal fade" id="importExcelModal" tabindex="-1" data-module="<?= h($module) ?>">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Importar <?= h($entityName) ?> desde Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="importStep1">
                    <p style="font-size:.85rem;color:#666;">
                        Seleccione un archivo <code>.xlsx</code> para importar. El sistema detectará las columnas automáticamente y le permitirá configurar el mapeo.
                    </p>
                    <p style="font-size:.8rem;color:#999;">
                        <i class="bi bi-info-circle me-1"></i>Tip: Exporte primero para obtener la plantilla con las columnas correctas.
                    </p>
                    <input type="file" id="importFileInput" class="form-control" accept=".xlsx">
                </div>

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
<?php endif; ?>

<?= $this->Html->script('excel-mapper', ['block' => true]) ?>
```

- [ ] **Step 3: Commit**

```bash
git add templates/element/excel_wizard/buttons.php templates/element/excel_wizard/modals.php
git commit -m "$(cat <<'EOF'
feat(excel): add reusable excel_wizard view elements

buttons.php renders Exportar/Importar; modals.php renders the export
field selector and the 3-step import wizard. Both are parametrized
via $module/$entityName/$downloadSlug/$importable.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Update `excel-mapper.js` to read `data-download-slug`

**Files:**
- Modify: `webroot/js/excel-mapper.js:113-117`

- [ ] **Step 1: Edit the download filename fallback**

Locate the block:

```javascript
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `empleados_${new Date().toISOString().slice(0,10)}.xlsx`;
```

Replace with:

```javascript
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    const slug = exportModal.dataset.downloadSlug || module.toLowerCase();
                    a.download = `${slug}_${new Date().toISOString().slice(0,10)}.xlsx`;
```

- [ ] **Step 2: Commit**

```bash
git add webroot/js/excel-mapper.js
git commit -m "$(cat <<'EOF'
feat(excel): read data-download-slug for filename fallback

Replaces the hardcoded 'empleados_…' filename with the slug declared
on the export modal so non-Employees modules get the right filename.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Phase 2 — Migrate Employees

### Task 10: Move Employees field map and hooks into `EmployeesTable`

**Files:**
- Modify: `src/Model/Table/EmployeesTable.php`

- [ ] **Step 1: Add interface and trait imports**

In `EmployeesTable.php`, after the existing `use` lines, add:

```php
use App\Model\Excel\ExcelExportableInterface;
use App\Model\Excel\ExcelExportableTrait;
use App\Service\EmployeeDocumentService;
use App\Service\EmployeeHistoryService;
use Cake\Datasource\EntityInterface;
```

Change the class declaration:

```php
class EmployeesTable extends Table implements ExcelExportableInterface
{
    use ExcelExportableTrait;
```

- [ ] **Step 2: Add the methods at the end of the class** (before the final `}`)

```php
    public function getExcelFields(): array
    {
        return [
            'document_type' => ['label' => 'Tipo de documento', 'type' => 'string'],
            'document_number' => [
                'label' => 'Cédula', 'type' => 'string', 'required' => true, 'is_key' => true,
                'aliases' => ['empleado'],
            ],
            'first_name' => ['label' => 'Nombres', 'type' => 'string', 'required_new' => true],
            'last_name1' => [
                'label' => 'Primer Apellido', 'type' => 'string', 'required_new' => true,
                'aliases' => ['apellido1', 'apellido 1', 'primer apellido', 'apellidos'],
            ],
            'last_name2' => [
                'label' => 'Segundo Apellido', 'type' => 'string',
                'aliases' => ['apellido2', 'apellido 2', 'segundo apellido'],
            ],
            'birth_date' => [
                'label' => 'Fecha de nacimiento', 'type' => 'date',
                'aliases' => ['fecha nacimiento del empleado'],
            ],
            'gender' => ['label' => 'Género', 'type' => 'string', 'aliases' => ['genero del empleado']],
            'email' => ['label' => 'Correo electrónico', 'type' => 'string', 'aliases' => ['email del contacto']],
            'phone' => ['label' => 'Teléfono', 'type' => 'string', 'aliases' => ['celular del contacto']],
            'address' => [
                'label' => 'Dirección', 'type' => 'string',
                'aliases' => ['dirección del contacto', 'direccion del contacto'],
            ],
            'city' => ['label' => 'Ciudad', 'type' => 'string'],
            'hire_date' => ['label' => 'Fecha de ingreso', 'type' => 'date', 'aliases' => ['fecha ingreso']],
            'termination_date' => ['label' => 'Fecha de retiro', 'type' => 'date'],
            'salary' => ['label' => 'Salario', 'type' => 'decimal'],
            'contract_type' => ['label' => 'Tipo de contrato', 'type' => 'string'],
            'vest_number' => ['label' => 'Número de chaleco', 'type' => 'string'],
            'eps' => ['label' => 'EPS', 'type' => 'string'],
            'pension_fund' => ['label' => 'Fondo de pensión', 'type' => 'string'],
            'arl' => ['label' => 'ARL', 'type' => 'string'],
            'severance_fund' => ['label' => 'Fondo de cesantías', 'type' => 'string'],
            'notes' => ['label' => 'Observaciones', 'type' => 'string'],

            'position_id' => [
                'label' => 'Código Cargo', 'type' => 'string',
                'fk' => true, 'fk_table' => 'Positions', 'fk_code' => 'code',
                'aliases' => ['id cargo'],
            ],
            'supervisor_position_id' => [
                'label' => 'Código Cargo supervisor', 'type' => 'string',
                'fk' => true, 'fk_table' => 'Positions', 'fk_code' => 'code',
                'aliases' => ['cargo jefe inmediato'],
            ],
            'operation_center_id' => [
                'label' => 'Código Centro de operación', 'type' => 'string',
                'fk' => true, 'fk_table' => 'OperationCenters', 'fk_code' => 'code',
                'aliases' => ['id c.o.'],
            ],
            'cost_center_id' => [
                'label' => 'Código Centro de costos', 'type' => 'string',
                'fk' => true, 'fk_table' => 'CostCenters', 'fk_code' => 'code',
                'aliases' => ['id ccosto'],
            ],
            'employee_status_id' => [
                'label' => 'Estado empleado', 'type' => 'string',
                'fk' => true, 'fk_table' => 'EmployeeStatuses', 'fk_code' => 'name',
            ],
            'marital_status_id' => [
                'label' => 'Estado civil', 'type' => 'string',
                'fk' => true, 'fk_table' => 'MaritalStatuses', 'fk_code' => 'name',
            ],
            'education_level_id' => [
                'label' => 'Nivel educativo', 'type' => 'string',
                'fk' => true, 'fk_table' => 'EducationLevels', 'fk_code' => 'name',
            ],
            'temporary_organization_id' => [
                'label' => 'NIT Temporal', 'type' => 'string',
                'fk' => true, 'fk_table' => 'TemporaryOrganizations', 'fk_code' => 'nit',
            ],

            'position' => [
                'label' => 'Cargo', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'Positions', 'fk_target' => 'position_id',
                'aliases' => ['descripcion del cargo'],
            ],
            'supervisor_position' => [
                'label' => 'Cargo supervisor', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'Positions', 'fk_target' => 'supervisor_position_id',
                'aliases' => ['descripción cargo jefe inmediato', 'descripcion cargo jefe inmediato'],
            ],
            'operation_center' => [
                'label' => 'Centro de operación', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'OperationCenters', 'fk_target' => 'operation_center_id',
                'aliases' => ['descripcion c.o.'],
            ],
            'cost_center' => [
                'label' => 'Centro de costos', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'CostCenters', 'fk_target' => 'cost_center_id',
                'aliases' => ['descripcion ccosto'],
            ],
            'employee_status' => [
                'label' => 'Estado', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'EmployeeStatuses', 'fk_target' => 'employee_status_id',
                'aliases' => ['descripcion estado'],
            ],
            'marital_status' => [
                'label' => 'Estado civil', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'MaritalStatuses', 'fk_target' => 'marital_status_id',
                'aliases' => ['estado civil del empleado'],
            ],
            'education_level' => [
                'label' => 'Nivel educativo', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'EducationLevels', 'fk_target' => 'education_level_id',
                'aliases' => ['nivel educativo del empleado'],
            ],
            'temporary_organization' => [
                'label' => 'Temporal', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'TemporaryOrganizations',
                'fk_target' => 'temporary_organization_id',
            ],
        ];
    }

    public function getExcelSheetTitle(): string
    {
        return 'Empleados';
    }

    public function getExcelDownloadSlug(): string
    {
        return 'empleados';
    }

    public function getExcelExportContains(): array
    {
        return [
            'EmployeeStatuses', 'Positions', 'SupervisorPositions',
            'OperationCenters', 'CostCenters', 'MaritalStatuses',
            'EducationLevels', 'TemporaryOrganizations',
        ];
    }

    public function onExcelImportCreated(EntityInterface $entity, int $userId): void
    {
        (new EmployeeDocumentService())->createDefaultFolders((int)$entity->id);
    }

    public function onExcelImportUpdated(EntityInterface $original, EntityInterface $entity, int $userId): void
    {
        (new EmployeeHistoryService())->recordChanges($original, $entity, $userId);
    }
```

- [ ] **Step 3: Run cs-fix**

Run: `composer cs-fix`

- [ ] **Step 4: Commit**

```bash
git add src/Model/Table/EmployeesTable.php
git commit -m "$(cat <<'EOF'
feat(employees): make EmployeesTable ExcelExportableInterface

Moves the FIELD_DEFINITIONS['Employees'] block from ExcelMappingService
into getExcelFields(). Wires onExcelImport(Created|Updated) hooks to
EmployeeDocumentService and EmployeeHistoryService respectively.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 11: Switch `EmployeesController` to use `ExcelWizardTrait`

**Files:**
- Modify: `src/Controller/EmployeesController.php`

- [ ] **Step 1: Replace imports and properties**

Remove these imports (lines ~7-12 of the file):

```php
use App\Service\EmployeeDocumentService;
use App\Service\ExcelImportService;
use App\Service\ExcelMappingService;
use App\Service\ExcelService;
use ArrayObject;
```

Add:

```php
use App\Controller\Trait\ExcelWizardTrait;
use App\Service\EmployeeDocumentService;
use App\Service\EmployeeHistoryService;
```

(Keep `EmployeeFilterService`, `EmployeeHistoryService` if still used elsewhere.)

In the class body, add at the top:

```php
class EmployeesController extends AppController
{
    use ExcelWizardTrait;
```

Remove the `$mappingService`, `$excelService`, `$importService` private properties and their initialization in `initialize()`. Keep `$filterService`, `$documentService`, `$historyService` if still used elsewhere; if not, remove those too (verify by grepping).

- [ ] **Step 2: Delete the four inline actions**

Remove the entire bodies of `exportConfig()`, `export()`, `importUpload()`, and `importProcess()` from `EmployeesController.php` (currently lines ~43-501). They are now provided by the trait.

- [ ] **Step 3: Verify no references remain**

Run: `grep -n 'mappingService\|excelService\|importService' src/Controller/EmployeesController.php`
Expected: empty (no references). If any remain, remove them.

- [ ] **Step 4: Run cs-fix and tests**

```bash
composer cs-fix
composer test -- --filter ExcelMappingServiceTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/EmployeesController.php
git commit -m "$(cat <<'EOF'
refactor(employees): switch controller to ExcelWizardTrait

Removes the inline exportConfig/export/importUpload/importProcess
actions; the trait now provides them, reading the field definitions
from EmployeesTable::getExcelFields().

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 12: Refactor `templates/Employees/index.php` to use elements

**Files:**
- Modify: `templates/Employees/index.php:14-126,294`

- [ ] **Step 1: Replace the header buttons block**

Find the header (around lines 14-31) and replace its right-hand side:

Old (lines 16-30):
```php
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
```

New:
```php
    <div class="d-flex gap-2">
        <?= $this->element('excel_wizard/buttons', [
            'module' => 'Employees',
            'importable' => true,
            'canCreate' => !empty($userPermissions['employees']['can_create']),
        ]) ?>
        <?php if (!empty($userPermissions['employees']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1"></i>Nuevo Empleado',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
```

- [ ] **Step 2: Delete the inline modal blocks (lines 33-126 of the original)**

Remove both `<!-- Export Modal -->` and `<!-- Import Modal -->` blocks entirely.

- [ ] **Step 3: Insert the modals element near the bottom of the template**

Find `<?php $this->Html->script('excel-mapper', ['block' => true]) ?>` (around line 294) and replace it with:

```php
<?= $this->element('excel_wizard/modals', [
    'module' => 'Employees',
    'entityName' => 'Empleados',
    'downloadSlug' => 'empleados',
    'importable' => true,
]) ?>
```

(The element loads `excel-mapper.js` itself.)

- [ ] **Step 4: Smoke test in browser**

Run: `php bin/cake server` (in another shell).

Manually verify in `http://localhost:8765/empleados`:
- Page renders without errors.
- Click **Exportar** → modal opens with field list, drag handles visible. Export with default selection downloads `empleados_<date>.xlsx`.
- Click **Importar** → upload step appears. Upload a known Excel → mapping step shows auto-mapping.
- Process the import → results step shows counters.

- [ ] **Step 5: Regression check (manual)**

Before continuing, run an idempotent re-import (same file twice):
- After first run: counters show created/updated counts.
- After second run: `unchanged` count == total rows; `employee_histories` row count must NOT have grown (verify with `SELECT COUNT(*) FROM employee_histories`).
- Verify default folders were created for new employees: `SELECT COUNT(*) FROM employee_folders WHERE employee_id = <new_id>` ≥ 1.

- [ ] **Step 6: Commit**

```bash
git add templates/Employees/index.php
git commit -m "$(cat <<'EOF'
refactor(employees): render Excel wizard via shared elements

Removes inline modal HTML (~95 lines) from templates/Employees/index.php
and delegates to the new excel_wizard/buttons and excel_wizard/modals
elements. Behavior unchanged.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Phase 3 — Migrate Providers

### Task 13: Make `ProvidersTable` excel-exportable

**Files:**
- Modify: `src/Model/Table/ProvidersTable.php`

- [ ] **Step 1: Add interface, trait, and methods**

At the top of `ProvidersTable.php`, add imports:

```php
use App\Model\Excel\ExcelExportableInterface;
use App\Model\Excel\ExcelExportableTrait;
```

Change class declaration:

```php
class ProvidersTable extends Table implements ExcelExportableInterface
{
    use ExcelExportableTrait;
```

Add at end of class:

```php
    public function getExcelFields(): array
    {
        return [
            'document_type' => [
                'label' => 'Tipo de documento', 'type' => 'string',
                'aliases' => ['tipo doc', 'tipo'],
            ],
            'document_number' => [
                'label' => 'NIT/Documento', 'type' => 'string',
                'required' => true, 'is_key' => true,
                'aliases' => ['nit', 'documento', 'numero documento'],
            ],
            'name' => ['label' => 'Nombre', 'type' => 'string', 'required_new' => true],
            'active' => ['label' => 'Activo', 'type' => 'boolean'],
        ];
    }

    public function getExcelSheetTitle(): string
    {
        return 'Proveedores';
    }

    public function getExcelDownloadSlug(): string
    {
        return 'proveedores';
    }
```

- [ ] **Step 2: Commit**

```bash
composer cs-fix
git add src/Model/Table/ProvidersTable.php
git commit -m "$(cat <<'EOF'
feat(providers): implement ExcelExportableInterface

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 14: Switch `ProvidersController` to use `ExcelWizardTrait`

**Files:**
- Modify: `src/Controller/ProvidersController.php`

- [ ] **Step 1: Replace imports**

Remove `use App\Service\ExcelService;`. Add `use App\Controller\Trait\ExcelWizardTrait;`.

- [ ] **Step 2: Use the trait**

Add right after `class ProvidersController extends AppController {`:
```php
    use ExcelWizardTrait;
```

Remove the `private ExcelService $excelService;` property and its initialization in `initialize()`.

- [ ] **Step 3: Delete the inline `export()` and `import()` actions**

Remove the entire bodies of those two methods (currently around lines 79-117).

- [ ] **Step 4: Smoke test**

In browser, visit `/providers`:
- Verify Exportar/Importar buttons render.
- Export a few providers; check XLSX has Spanish headers.
- Import the same XLSX; verify `unchanged: N`.

- [ ] **Step 5: Commit**

```bash
composer cs-fix
git add src/Controller/ProvidersController.php
git commit -m "$(cat <<'EOF'
refactor(providers): use ExcelWizardTrait instead of ad-hoc export/import

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 15: Update `templates/Providers/index.php` to use the elements

**Files:**
- Modify: `templates/Providers/index.php`

- [ ] **Step 1: Replace any existing export/import buttons (or `catalog_excel_buttons` element) with the new buttons element**

Find the page header block. Replace whatever export/import controls exist with:

```php
<?= $this->element('excel_wizard/buttons', [
    'module' => 'Providers',
    'importable' => true,
    'canCreate' => !empty($userPermissions['providers']['can_create']),
]) ?>
```

- [ ] **Step 2: Insert the modals element near the bottom of the template** (above `<?= $this->fetch('script') ?>` or before `</body>` content):

```php
<?= $this->element('excel_wizard/modals', [
    'module' => 'Providers',
    'entityName' => 'Proveedores',
    'downloadSlug' => 'proveedores',
    'importable' => true,
]) ?>
```

- [ ] **Step 3: Smoke test the page** (open `/providers`).

- [ ] **Step 4: Commit**

```bash
git add templates/Providers/index.php
git commit -m "$(cat <<'EOF'
refactor(providers): render Excel wizard via shared elements

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Phase 4 — Migrate Invoices (export-only)

### Task 16: Make `InvoicesTable` exportable but not importable

**Files:**
- Modify: `src/Model/Table/InvoicesTable.php`

- [ ] **Step 1: Add imports and traits**

```php
use App\Model\Excel\ExcelExportableInterface;
use App\Model\Excel\ExcelExportableTrait;
```

```php
class InvoicesTable extends Table implements ExcelExportableInterface
{
    use ExcelExportableTrait;
```

- [ ] **Step 2: Add methods at the end of the class**

```php
    public function getExcelFields(): array
    {
        return [
            'invoice_number' => ['label' => 'Número Factura', 'type' => 'string'],
            'document_type' => ['label' => 'Tipo Documento', 'type' => 'string'],
            'registration_date' => ['label' => 'Fecha Registro', 'type' => 'date'],
            'issue_date' => ['label' => 'Fecha Emisión', 'type' => 'date'],
            'due_date' => ['label' => 'Fecha Vencimiento', 'type' => 'date'],
            // display_only field keys MUST match the association property name on the
            // entity (lowerCamel of the alias). The trait uses $entity->{$field} to read
            // the related entity. For belongsTo('Providers') Cake exposes $entity->provider.
            'provider' => [
                'label' => 'Proveedor', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'Providers', 'fk_target' => 'provider_id',
            ],
            'operation_center' => [
                'label' => 'Centro Operación', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'OperationCenters', 'fk_target' => 'operation_center_id',
            ],
            'expense_type' => [
                'label' => 'Tipo Gasto', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'ExpenseTypes', 'fk_target' => 'expense_type_id',
            ],
            'cost_center' => [
                'label' => 'Centro Costos', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'CostCenters', 'fk_target' => 'cost_center_id',
            ],
            'detail' => ['label' => 'Detalle', 'type' => 'string'],
            'amount' => ['label' => 'Valor', 'type' => 'decimal'],
            'dian_validation' => ['label' => 'Validación DIAN', 'type' => 'string'],
            'accrued' => ['label' => 'Causada', 'type' => 'boolean'],
            'accrual_date' => ['label' => 'Fecha Causación', 'type' => 'date'],
            'ready_for_payment' => ['label' => 'Lista para Pago', 'type' => 'string'],
            'payment_status' => ['label' => 'Estado Pago', 'type' => 'string'],
            'full_payment_date' => ['label' => 'Fecha Pago Total', 'type' => 'date'],
            'pipeline_status' => ['label' => 'Estado Pipeline', 'type' => 'string'],
        ];
    }

    public function getExcelSheetTitle(): string
    {
        return 'Facturas';
    }

    public function getExcelDownloadSlug(): string
    {
        return 'facturas';
    }

    public function isExcelImportable(): bool
    {
        return false;
    }

    public function getExcelExportContains(): array
    {
        return ['Providers', 'OperationCenters', 'ExpenseTypes', 'CostCenters'];
    }
```

> Note: `pipeline_status` is exported as the raw status code. The previous controller mapped it via `InvoicePipelineService::STATUS_LABELS`. If you want the localized label in the export, post-process inside `getExcelExportContains()` is not enough — instead, after this task you can override `export()` in the controller, but the simpler approach is to live with the raw code in the XLSX. Confirm with the user during smoke testing if the raw code is acceptable; if not, see follow-up below.

> **Optional follow-up (not required for this plan):** if status labels are needed, add a virtual property `pipeline_status_label` to the `Invoice` entity and reference that field in `getExcelFields()` instead.

- [ ] **Step 3: Commit**

```bash
composer cs-fix
git add src/Model/Table/InvoicesTable.php
git commit -m "$(cat <<'EOF'
feat(invoices): export-only ExcelExportableInterface

isExcelImportable() returns false; the wizard buttons hide the Import
button and the trait returns 405 from import endpoints.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 17: Switch `InvoicesController` to `ExcelWizardTrait`

**Files:**
- Modify: `src/Controller/InvoicesController.php`

- [ ] **Step 1: Add the trait**

Add `use App\Controller\Trait\ExcelWizardTrait;` to the imports.

In the class body:
```php
    use ExcelWizardTrait;
```

- [ ] **Step 2: Delete the inline `export()` action** (currently lines ~417-471).

- [ ] **Step 3: Smoke test**

In browser, visit `/invoices`:
- Verify Exportar button is present, **Importar is NOT present**.
- Export a small subset of invoices.
- Try `curl -X POST http://localhost:8765/invoices/import-upload -H 'Cookie: <session>'` → expect HTTP 405.

- [ ] **Step 4: Commit**

```bash
composer cs-fix
git add src/Controller/InvoicesController.php
git commit -m "$(cat <<'EOF'
refactor(invoices): use ExcelWizardTrait (export-only)

Removes the inline export() action. isExcelImportable=false on the
Table makes import endpoints return 405.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 18: Insert wizard elements into `templates/Invoices/index.php`

**Files:**
- Modify: `templates/Invoices/index.php`

- [ ] **Step 1: Replace whatever Export button currently exists in the page header with**:

```php
<?= $this->element('excel_wizard/buttons', [
    'module' => 'Invoices',
    'importable' => false,
    'canCreate' => !empty($userPermissions['invoices']['can_create']),
]) ?>
```

- [ ] **Step 2: Add the modals element near the bottom**:

```php
<?= $this->element('excel_wizard/modals', [
    'module' => 'Invoices',
    'entityName' => 'Facturas',
    'downloadSlug' => 'facturas',
    'importable' => false,
]) ?>
```

- [ ] **Step 3: Smoke test** — verify Importar button is hidden, modal HTML for import is not rendered.

- [ ] **Step 4: Commit**

```bash
git add templates/Invoices/index.php
git commit -m "$(cat <<'EOF'
refactor(invoices): render export wizard via shared elements

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Phase 5 — Migrate the 8 catalog modules

Each catalog migration follows the same pattern. Tasks 19-26 each handle one catalog. The task body for each one repeats the same pattern; the only thing that varies is the module name, key field, fields list, and labels.

> **Pattern for every catalog (apply in each task below):**
>
> 1. Modify the Table file: add `implements ExcelExportableInterface`, `use ExcelExportableTrait;`, implement `getExcelFields()`, `getExcelSheetTitle()`, `getExcelDownloadSlug()`.
> 2. Modify the controller: replace `use ExcelCatalogTrait;` with `use ExcelWizardTrait;`. Remove the `protected string $importKeyField` line if present (the wizard uses `is_key` from the Table instead). Remove `use App\Controller\Trait\ExcelCatalogTrait;` import; add `use App\Controller\Trait\ExcelWizardTrait;`.
> 3. Modify the index template: replace `<?= $this->element('catalog_excel_buttons', …) ?>` with `<?= $this->element('excel_wizard/buttons', …) ?>` and append `<?= $this->element('excel_wizard/modals', …) ?>` near the bottom.
> 4. Smoke test the page in the browser.
> 5. Commit with message `refactor(<module>): migrate to ExcelWizardTrait`.

---

### Task 19: CostCenters

**Files:** `src/Model/Table/CostCentersTable.php`, `src/Controller/CostCentersController.php`, `templates/CostCenters/index.php`

- [ ] **Step 1: Update Table**

Add to `CostCentersTable.php`:

```php
use App\Model\Excel\ExcelExportableInterface;
use App\Model\Excel\ExcelExportableTrait;
```

```php
class CostCentersTable extends Table implements ExcelExportableInterface
{
    use ExcelExportableTrait;
```

```php
    public function getExcelFields(): array
    {
        return [
            'code' => ['label' => 'Código', 'type' => 'string', 'is_key' => true, 'required' => true],
            'name' => ['label' => 'Nombre', 'type' => 'string', 'required_new' => true],
        ];
    }

    public function getExcelSheetTitle(): string { return 'Centros de Costos'; }
    public function getExcelDownloadSlug(): string { return 'centros_costos'; }
```

- [ ] **Step 2: Update Controller**

In `CostCentersController.php`:

```php
use App\Controller\Trait\ExcelWizardTrait;
```

Remove `use App\Controller\Trait\ExcelCatalogTrait;`.

In class body, replace `use ExcelCatalogTrait;` with `use ExcelWizardTrait;`.

- [ ] **Step 3: Update template `templates/CostCenters/index.php`**

Replace the existing `<?= $this->element('catalog_excel_buttons', […]) ?>` with:

```php
<?= $this->element('excel_wizard/buttons', [
    'module' => 'CostCenters',
    'importable' => true,
    'canCreate' => !empty($userPermissions['cost_centers']['can_create']),
]) ?>
```

Append near the bottom:

```php
<?= $this->element('excel_wizard/modals', [
    'module' => 'CostCenters',
    'entityName' => 'Centros de Costos',
    'downloadSlug' => 'centros_costos',
    'importable' => true,
]) ?>
```

- [ ] **Step 4: Smoke test `/cost-centers`** — Exportar and Importar work.

- [ ] **Step 5: Commit**

```bash
composer cs-fix
git add src/Model/Table/CostCentersTable.php src/Controller/CostCentersController.php templates/CostCenters/index.php
git commit -m "$(cat <<'EOF'
refactor(cost-centers): migrate to ExcelWizardTrait

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 20: OperationCenters

Apply the catalog pattern with these specifics:

- **Table fields:**
```php
public function getExcelFields(): array
{
    return [
        'code' => ['label' => 'Código', 'type' => 'string', 'is_key' => true, 'required' => true],
        'name' => ['label' => 'Nombre', 'type' => 'string', 'required_new' => true],
    ];
}
public function getExcelSheetTitle(): string { return 'Centros de Operación'; }
public function getExcelDownloadSlug(): string { return 'centros_operacion'; }
```

- **Element parameters:**
  - module `'OperationCenters'`, entityName `'Centros de Operación'`, downloadSlug `'centros_operacion'`, permissions key `'operation_centers'`.

- [ ] **Step 1: Update `src/Model/Table/OperationCentersTable.php`** following Task 19 step 1 (substitute fields).
- [ ] **Step 2: Update `src/Controller/OperationCentersController.php`** following Task 19 step 2.
- [ ] **Step 3: Update `templates/OperationCenters/index.php`** with substituted parameters.
- [ ] **Step 4: Smoke test `/operation-centers`.**
- [ ] **Step 5: Commit** with message `refactor(operation-centers): migrate to ExcelWizardTrait`.

---

### Task 21: Positions

- **Table fields:**
```php
public function getExcelFields(): array
{
    return [
        'code' => ['label' => 'Código', 'type' => 'string', 'is_key' => true, 'required' => true],
        'name' => ['label' => 'Cargo', 'type' => 'string', 'required_new' => true],
    ];
}
public function getExcelSheetTitle(): string { return 'Cargos'; }
public function getExcelDownloadSlug(): string { return 'cargos'; }
```

- **Element parameters:** module `'Positions'`, entityName `'Cargos'`, downloadSlug `'cargos'`, permissions key `'positions'`.

- [ ] Apply pattern; smoke test `/positions`; commit `refactor(positions): migrate to ExcelWizardTrait`.

---

### Task 22: TemporaryOrganizations

- **Table fields:**
```php
public function getExcelFields(): array
{
    return [
        'nit' => ['label' => 'NIT', 'type' => 'string', 'is_key' => true, 'required' => true],
        'name' => ['label' => 'Nombre', 'type' => 'string', 'required_new' => true],
        'active' => ['label' => 'Activo', 'type' => 'boolean'],
    ];
}
public function getExcelSheetTitle(): string { return 'Organizaciones Temporales'; }
public function getExcelDownloadSlug(): string { return 'temporales'; }
```

- **Element parameters:** module `'TemporaryOrganizations'`, entityName `'Organizaciones Temporales'`, downloadSlug `'temporales'`, permissions key `'temporary_organizations'`.

- [ ] Apply pattern; smoke test `/temporary-organizations`; commit `refactor(temporary-organizations): migrate to ExcelWizardTrait`.

---

### Task 23: EducationLevels

- **Table fields** (entity exposes only `name`):
```php
public function getExcelFields(): array
{
    return [
        'name' => ['label' => 'Nombre', 'type' => 'string', 'is_key' => true, 'required' => true],
    ];
}
public function getExcelSheetTitle(): string { return 'Niveles Educativos'; }
public function getExcelDownloadSlug(): string { return 'niveles_educativos'; }
```

- **Element parameters:** module `'EducationLevels'`, entityName `'Niveles Educativos'`, downloadSlug `'niveles_educativos'`, permissions key `'education_levels'`.

- [ ] Apply pattern; smoke test `/education-levels`; commit `refactor(education-levels): migrate to ExcelWizardTrait`.

---

### Task 24: MaritalStatuses

- **Table fields:**
```php
public function getExcelFields(): array
{
    return [
        'name' => ['label' => 'Nombre', 'type' => 'string', 'is_key' => true, 'required' => true],
    ];
}
public function getExcelSheetTitle(): string { return 'Estados Civiles'; }
public function getExcelDownloadSlug(): string { return 'estados_civiles'; }
```

- **Element parameters:** module `'MaritalStatuses'`, entityName `'Estados Civiles'`, downloadSlug `'estados_civiles'`, permissions key `'marital_statuses'`.

- [ ] Apply pattern; smoke test `/marital-statuses`; commit `refactor(marital-statuses): migrate to ExcelWizardTrait`.

---

### Task 25: EmployeeStatuses

- **Table fields:**
```php
public function getExcelFields(): array
{
    return [
        'name' => ['label' => 'Nombre', 'type' => 'string', 'is_key' => true, 'required' => true],
    ];
}
public function getExcelSheetTitle(): string { return 'Estados de Empleado'; }
public function getExcelDownloadSlug(): string { return 'estados_empleado'; }
```

- **Element parameters:** module `'EmployeeStatuses'`, entityName `'Estados de Empleado'`, downloadSlug `'estados_empleado'`, permissions key `'employee_statuses'`.

- [ ] Apply pattern; smoke test `/employee-statuses`; commit `refactor(employee-statuses): migrate to ExcelWizardTrait`.

---

### Task 26: DefaultFolders

- **Table fields:**
```php
public function getExcelFields(): array
{
    return [
        'name' => ['label' => 'Nombre', 'type' => 'string', 'is_key' => true, 'required' => true],
        'sort_order' => ['label' => 'Orden', 'type' => 'integer'],
    ];
}
public function getExcelSheetTitle(): string { return 'Carpetas por Defecto'; }
public function getExcelDownloadSlug(): string { return 'carpetas_defecto'; }
```

- **Element parameters:** module `'DefaultFolders'`, entityName `'Carpetas por Defecto'`, downloadSlug `'carpetas_defecto'`, permissions key `'default_folders'`.

- [ ] Apply pattern; smoke test `/default-folders`; commit `refactor(default-folders): migrate to ExcelWizardTrait`.

---

## Phase 6 — Cleanup

### Task 27: Remove `ExcelCatalogTrait` and the catalog buttons element

**Files to delete:**
- `src/Controller/Trait/ExcelCatalogTrait.php`
- `templates/element/catalog_excel_buttons.php`

- [ ] **Step 1: Verify no references remain**

Run:
```bash
grep -rn "ExcelCatalogTrait\|catalog_excel_buttons" src templates webroot tests
```
Expected: empty output.

- [ ] **Step 2: Delete the files**

```bash
rm src/Controller/Trait/ExcelCatalogTrait.php templates/element/catalog_excel_buttons.php
```

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
chore(excel): remove legacy ExcelCatalogTrait and partial

All catalogs now use the wizard via ExcelWizardTrait + the new
excel_wizard elements.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 28: Remove `ExcelService::exportCatalog()` and `importCatalog()`

**Files:**
- Modify: `src/Service/ExcelService.php`

- [ ] **Step 1: Verify no references remain**

Run:
```bash
grep -rn "exportCatalog\|importCatalog" src templates tests
```
Expected: empty.

- [ ] **Step 2: Edit `src/Service/ExcelService.php`**

Delete the `exportCatalog()` method (lines ~22-68) and `importCatalog()` method (lines ~136-232). Keep only `exportWithLabels()`. Also drop unused imports (`UploadedFileInterface`, `IOFactory`, `TableRegistry`) if they remain.

The final file should expose only `exportWithLabels()`. Verify the resulting file.

- [ ] **Step 3: Run tests**

```bash
composer cs-fix
composer test
```
Expected: PASS (mapping tests, plus tests written in subsequent tasks if already created).

- [ ] **Step 4: Commit**

```bash
git add src/Service/ExcelService.php
git commit -m "$(cat <<'EOF'
chore(excel): drop ExcelService::exportCatalog and importCatalog

These were the entry points used by the old ExcelCatalogTrait. All
modules use ExcelImportService::processImport() and exportWithLabels()
through the wizard now.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Phase 7 — Tests

### Task 29: Add `ExcelImportServiceTest`

**Files:**
- Create: `tests/TestCase/Service/ExcelImportServiceTest.php`

- [ ] **Step 1: Create test fixtures plan**

The test exercises `processImport` end-to-end with a real Excel file. We use a tiny anonymous Table-like double that implements `ExcelExportableInterface` plus the minimum CakePHP Table surface (`find()`, `patchEntity()`, `newEntity()`, `save()`, hooks). Using a real Table requires fixtures. Easier: spin a temp SQLite-based table using CakePHP's TestCase + fixtures for `Positions`. We test the Positions catalog (simple, real DB-backed).

- [ ] **Step 2: Create the test file**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ExcelImportService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelImportServiceTest extends TestCase
{
    protected array $fixtures = ['app.Positions'];

    private ExcelImportService $service;
    private string $tempPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExcelImportService();
    }

    protected function tearDown(): void
    {
        if ($this->tempPath !== '' && file_exists($this->tempPath)) {
            unlink($this->tempPath);
        }
        parent::tearDown();
    }

    private function makeXlsx(array $headers, array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($headers as $col => $h) {
            $sheet->setCellValueByColumnAndRow($col + 1, 1, $h);
        }
        foreach ($rows as $r => $row) {
            foreach ($row as $col => $value) {
                $sheet->setCellValueByColumnAndRow($col + 1, $r + 2, $value);
            }
        }
        $tempFile = tempnam(sys_get_temp_dir(), 'sgi_test_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($tempFile);

        return $this->tempPath = $tempFile;
    }

    public function testCreatesNewRowsWhenKeyMissing(): void
    {
        $positions = TableRegistry::getTableLocator()->get('Positions');
        $tempPath = $this->makeXlsx(
            ['Código', 'Cargo'],
            [['NEW1', 'Nuevo Cargo 1'], ['NEW2', 'Nuevo Cargo 2']],
        );
        $mapping = ['Código' => 'code', 'Cargo' => 'name'];

        $result = $this->service->processImport(
            $tempPath, $positions, $mapping, ['Código', 'Cargo'], userId: 1,
        );

        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->updated);
        $this->assertSame([], $result->errors);
        $this->assertNotNull($positions->find()->where(['code' => 'NEW1'])->first());
    }

    public function testUpdatesExistingRowsAndDetectsUnchanged(): void
    {
        $positions = TableRegistry::getTableLocator()->get('Positions');
        $existing = $positions->save($positions->newEntity(['code' => 'EX1', 'name' => 'Original']));
        $this->assertNotFalse($existing);

        $tempPath = $this->makeXlsx(
            ['Código', 'Cargo'],
            [['EX1', 'Cambiado'], ['EX1', 'Cambiado']], // 2nd row identical → unchanged
        );
        // First import: row #1 updates, row #2 also points to EX1 with same value as row #1
        $result = $this->service->processImport(
            $tempPath, $positions, ['Código' => 'code', 'Cargo' => 'name'],
            ['Código', 'Cargo'], userId: 1,
        );

        $this->assertSame(1, $result->updated);
        $this->assertSame(1, $result->unchanged);
    }

    public function testSkipsRowsWithEmptyKey(): void
    {
        $positions = TableRegistry::getTableLocator()->get('Positions');
        $tempPath = $this->makeXlsx(
            ['Código', 'Cargo'],
            [['', 'Sin codigo'], ['OK1', 'Con codigo']],
        );
        $result = $this->service->processImport(
            $tempPath, $positions, ['Código' => 'code', 'Cargo' => 'name'],
            ['Código', 'Cargo'], userId: 1,
        );

        $this->assertSame(1, $result->skipped);
        $this->assertSame(1, $result->created);
    }

    public function testReturnsErrorWhenRequiredFieldMissingFromMapping(): void
    {
        $positions = TableRegistry::getTableLocator()->get('Positions');
        $tempPath = $this->makeXlsx(['Cargo'], [['Solo nombre']]);
        $result = $this->service->processImport(
            $tempPath, $positions, ['Cargo' => 'name'], ['Cargo'], userId: 1,
        );

        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('Código', $result->errors[0]);
    }

    public function testCallsOnExcelImportCreatedHook(): void
    {
        // Subclass anonymously to capture the hook invocation
        $captured = [];
        $positions = new class extends \App\Model\Table\PositionsTable {
            public array $captured = [];
            public function onExcelImportCreated(\Cake\Datasource\EntityInterface $entity, int $userId): void
            {
                $this->captured[] = ['entity' => $entity, 'userId' => $userId];
            }
        };
        $positions->setTable('positions');
        $positions->setConnection(\Cake\Datasource\ConnectionManager::get('test'));

        $tempPath = $this->makeXlsx(['Código', 'Cargo'], [['HK1', 'Hook test']]);
        $result = $this->service->processImport(
            $tempPath, $positions, ['Código' => 'code', 'Cargo' => 'name'],
            ['Código', 'Cargo'], userId: 42,
        );

        $this->assertSame(1, $result->created);
        $this->assertCount(1, $positions->captured);
        $this->assertSame(42, $positions->captured[0]['userId']);
    }
}
```

- [ ] **Step 3: Ensure the Positions fixture exists**

Run: `ls tests/Fixture/PositionsFixture.php`

If it does not exist, create it:

```php
<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class PositionsFixture extends TestFixture
{
    public array $records = [];
}
```

- [ ] **Step 4: Run tests**

Run: `composer test -- --filter ExcelImportServiceTest`
Expected: 5 tests pass.

- [ ] **Step 5: Commit**

```bash
git add tests/TestCase/Service/ExcelImportServiceTest.php tests/Fixture/PositionsFixture.php
git commit -m "$(cat <<'EOF'
test(excel): add unit tests for ExcelImportService

Covers create/update/unchanged/skip/error paths and verifies the
onExcelImportCreated hook is invoked with the right user id.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 30: Add `ExcelWizardTraitTest` integration test

**Files:**
- Create: `tests/TestCase/Controller/Trait/ExcelWizardTraitTest.php`

- [ ] **Step 1: Create the test**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Trait;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class ExcelWizardTraitTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Users', 'app.Roles', 'app.Permissions',
        'app.Positions',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Authenticate as admin so RBAC doesn't block actions
        $this->session(['Auth' => $this->_adminIdentity()]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
    }

    private function _adminIdentity(): array
    {
        // Replace with whatever your existing IntegrationTestTrait helper returns.
        // The exact shape depends on your Authentication configuration; this is a
        // skeleton — adjust to match your test bootstrap.
        return ['id' => 1, 'role' => (object)['name' => 'Administrador']];
    }

    public function testExportConfigReturnsFieldsJson(): void
    {
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/positions/export-config');

        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertIsArray($body['fields']);
        $names = array_column($body['fields'], 'field');
        $this->assertContains('code', $names);
        $this->assertContains('name', $names);
    }

    public function testExportRejectsEmptySelection(): void
    {
        $this->configRequest(['headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json']]);
        $this->post('/positions/export', json_encode(['fields' => []]));

        $this->assertResponseCode(400);
    }

    public function testImportEndpointsReturn405WhenNotImportable(): void
    {
        // InvoicesTable returns isExcelImportable=false
        $this->post('/invoices/import-upload');
        $this->assertResponseCode(405);

        $this->post('/invoices/import-process');
        $this->assertResponseCode(405);
    }
}
```

> **Note for the implementer:** the `_adminIdentity()` helper above is a skeleton. Adapt it to whatever pattern the existing CakePHP integration tests use for authenticated requests in this project (check `tests/TestCase/Controller/` for an existing example).

- [ ] **Step 2: Run tests**

```bash
composer test -- --filter ExcelWizardTraitTest
```
Expected: 3 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/TestCase/Controller/Trait/ExcelWizardTraitTest.php
git commit -m "$(cat <<'EOF'
test(excel): integration tests for ExcelWizardTrait

Covers exportConfig, export with empty selection, and 405 from import
endpoints when isExcelImportable=false (Invoices).

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 31: Final full test run and CHANGELOG note

**Files:**
- (No file changes unless `composer check` flags issues.)

- [ ] **Step 1: Run the full test + style suite**

```bash
composer check
```
Expected: PASS (PHPUnit + cs-check).

- [ ] **Step 2: Manual end-to-end smoke**

For each migrated module, repeat the smoke checklist from the spec §10 in a real browser:
- Employees, Providers, Invoices, CostCenters, OperationCenters, Positions, TemporaryOrganizations, EducationLevels, MaritalStatuses, EmployeeStatuses, DefaultFolders.

For Invoices specifically:
- POST `/invoices/import-upload` returns 405 (`curl -X POST http://localhost:8765/invoices/import-upload -H 'Cookie: <session>'`).
- Import button is hidden in the UI.

- [ ] **Step 3: If anything fails, fix it inline and commit before continuing.**

- [ ] **Step 4: Final commit (only if any docs/CHANGELOG changes)**

If the project has a CHANGELOG, add an entry under the appropriate version:

```
- Excel wizard import/export available across catalogs, providers, employees, and invoices (export-only).
- Removed legacy ExcelCatalogTrait and ExcelService::exportCatalog/importCatalog.
```

---

## Self-review checklist (run before declaring done)

- [ ] All 11 modules export and (where applicable) import via the wizard.
- [ ] `ExcelCatalogTrait`, `catalog_excel_buttons.php`, `exportCatalog`, `importCatalog`, `FIELD_DEFINITIONS` constant, and `import_debug.log` write are all gone.
- [ ] `composer check` passes.
- [ ] Smoke tests for all migrated modules pass in browser.
- [ ] `EmployeesController` no longer contains `exportConfig`/`export`/`importUpload`/`importProcess` action bodies.
- [ ] `InvoicesController` no longer contains an `export()` method.
- [ ] `ProvidersController` no longer contains `export()` or `import()` methods.
- [ ] `_actionToPermission` includes the three new actions (`exportConfig`, `importUpload`, `importProcess`).

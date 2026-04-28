# Excel Wizard reusable across SGI modules — design spec

**Date:** 2026-04-27
**Status:** Approved (design)
**Author:** Alexander Caicedo + Claude

## 1. Problem

Today the Employees module has a rich Excel import/export wizard:

- 3-step import (upload → mapping → results) with auto-mapping, alias resolution, FK lookup by code and by name, change detection (`unchanged` vs `updated`), required-field validation, history audit, and per-row error reporting.
- Export modal with field selection, drag-and-drop reordering, Spanish labels, AJAX download.

Other SGI modules use a simpler mechanism (`ExcelCatalogTrait` + `ExcelService::exportCatalog/importCatalog`) that dumps raw columns and does a naive upsert. Two parallel systems exist; the simple one duplicates effort and lacks the safety nets of the wizard. Adding a new module requires deciding which path to take and writing boilerplate for both.

## 2. Goals

1. Make the Employees-style wizard the **only** import/export mechanism in SGI.
2. Make adding the wizard to a new module a **3-step drop-in**: implement an interface in the Table, `use` a trait in the Controller, include a template element in `index.php`.
3. Eliminate code duplication: remove the simple `ExcelCatalogTrait` path and the `exportCatalog/importCatalog` methods.
4. Preserve every behavior the Employees wizard has today (auto-mapping, FK resolution, change detection, history, callbacks, RBAC).

## 3. Non-goals

- Importing invoices. Invoices get **export-only** because their pipeline state, field-access policy, and history requirements make bulk upsert unsafe.
- Adding import/export to modules that don't have it today (out of scope; can be done later by following the drop-in steps).
- Migrating `PaymentSchedulings::importExcel` (different flow: imports rows into a parent invoice, not stand-alone records).
- Migrating `DianCrosschecks` Excel handling (specialized cross-validation flow).
- Replacing the JavaScript wizard logic — `excel-mapper.js` is already module-agnostic.

## 4. Scope

### Modules covered

| Module | Mode | Upsert key |
|---|---|---|
| Employees | export + import (already wired; refactored only) | `document_number` |
| Providers | export + import | `document_number` |
| Invoices | **export only** | n/a |
| CostCenters | export + import | `code` |
| OperationCenters | export + import | `code` |
| Positions | export + import | `code` |
| TemporaryOrganizations | export + import | `nit` |
| EducationLevels | export + import | `name` |
| MaritalStatuses | export + import | `name` |
| EmployeeStatuses | export + import | `name` |
| DefaultFolders | export + import | `name` |

Total: **11 controllers, 11 tables, 11 `index.php` templates**.

## 5. Architecture

```
┌─ Table layer ──────────────────────────────────────────────────┐
│ Each migrated Table implements ExcelExportableInterface         │
│ and uses ExcelExportableTrait for default hook implementations. │
│                                                                 │
│   getExcelFields()        → field definitions array            │
│   getExcelSheetTitle()    → 'Empleados', 'Cargos', …           │
│   getExcelDownloadSlug()  → 'empleados', 'cargos', …           │
│   isExcelImportable()     → bool, default true                 │
│   getExcelExportContains()→ associations to eager-load         │
│   onExcelImportCreated()  → optional post-create hook          │
│   onExcelImportUpdated()  → optional post-update hook          │
└────────────────────────────────────────────────────────────────┘

┌─ Controller layer ─────────────────────────────────────────────┐
│ Each migrated Controller `use`s ExcelWizardTrait, exposing     │
│ four AJAX/HTTP actions:                                         │
│                                                                 │
│   exportConfig()  GET  → JSON of exportable fields             │
│   export()        POST → XLSX file with selected fields        │
│   importUpload()  POST → JSON: temp file + auto-mapping        │
│   importProcess() POST → JSON: ImportResult summary            │
│                                                                 │
│ When isExcelImportable() === false, importUpload() and         │
│ importProcess() return HTTP 405. The buttons element hides     │
│ the Import button using the same flag.                         │
└────────────────────────────────────────────────────────────────┘

┌─ Service layer (consolidated) ─────────────────────────────────┐
│ ExcelMappingService → no longer holds FIELD_DEFINITIONS;       │
│                       methods receive Table instances.         │
│ ExcelImportService  → callbacks via Table hooks instead of     │
│                       hardcoded EmployeeHistoryService.        │
│ ExcelService        → only exportWithLabels() remains.         │
│ ImportResult        → unchanged.                               │
└────────────────────────────────────────────────────────────────┘

┌─ View layer ───────────────────────────────────────────────────┐
│ templates/element/excel_wizard/buttons.php                     │
│ templates/element/excel_wizard/modals.php                      │
│ webroot/js/excel-mapper.js  (already generic)                  │
└────────────────────────────────────────────────────────────────┘
```

### Adding the wizard to a new module (the drop-in)

1. **Table:** implement `ExcelExportableInterface`, `use ExcelExportableTrait`, declare `getExcelFields()`, `getExcelSheetTitle()`, `getExcelDownloadSlug()`. Override `isExcelImportable()`/hooks if needed.
2. **Controller:** `use ExcelWizardTrait;` and add `exportConfig`, `export`, `importUpload`, `importProcess` to the action permission map.
3. **Template:** include `excel_wizard/buttons` in the page header and `excel_wizard/modals` at the bottom of `index.php`.

No changes to routes (CakePHP fallbacks resolve the four actions), no changes to global JS, no changes to CSS.

## 6. Detailed component design

### 6.1 `App\Model\Excel\ExcelExportableInterface`

```php
namespace App\Model\Excel;

use Cake\Datasource\EntityInterface;

interface ExcelExportableInterface
{
    /**
     * Field definitions used by the wizard.
     * Same shape as the current ExcelMappingService::FIELD_DEFINITIONS['Employees'] entry.
     *
     * @return array<string, array{
     *   label: string,
     *   type: 'string'|'date'|'decimal'|'integer'|'boolean',
     *   required?: bool,
     *   required_new?: bool,
     *   is_key?: bool,
     *   aliases?: array<string>,
     *   fk?: bool,
     *   fk_table?: string,
     *   fk_code?: string,
     *   display_only?: bool,
     *   fk_resolve?: string,
     *   fk_target?: string
     * }>
     */
    public function getExcelFields(): array;

    public function getExcelSheetTitle(): string;
    public function getExcelDownloadSlug(): string;

    public function isExcelImportable(): bool;
    public function getExcelExportContains(): array;

    public function onExcelImportCreated(EntityInterface $entity, int $userId): void;
    public function onExcelImportUpdated(EntityInterface $original, EntityInterface $entity, int $userId): void;
}
```

### 6.2 `App\Model\Excel\ExcelExportableTrait`

Provides defaults so most Tables only need to implement the three abstract-style methods.

```php
trait ExcelExportableTrait
{
    public function isExcelImportable(): bool { return true; }
    public function getExcelExportContains(): array { return []; }
    public function onExcelImportCreated(EntityInterface $entity, int $userId): void {}
    public function onExcelImportUpdated(EntityInterface $original, EntityInterface $entity, int $userId): void {}
}
```

### 6.3 `App\Controller\Trait\ExcelWizardTrait`

Replaces `ExcelCatalogTrait`. Implements the four actions, delegating to `ExcelMappingService`, `ExcelService`, and `ExcelImportService`. Reads `$this->fetchTable()` and verifies it implements `ExcelExportableInterface`; otherwise throws `LogicException` at controller boot to fail fast.

`importUpload` and `importProcess` short-circuit with HTTP 405 when `$table->isExcelImportable() === false`.

### 6.4 `ExcelMappingService` (refactored)

- Drops the `FIELD_DEFINITIONS` constant.
- All methods accept either an `ExcelExportableInterface` Table or the field definitions array directly. The signature passing the array preserves testability without needing a Table fixture.
- Behavior unchanged: same auto-mapping, same lookups, same validation messages.

### 6.5 `ExcelImportService` (refactored)

- `processImport()` no longer accepts `EmployeeHistoryService`/`?int $userId`. Instead it receives the `ExcelExportableInterface` Table and `int $userId`, then calls `$table->onExcelImportCreated($entity, $userId)` after each create and `$table->onExcelImportUpdated($original, $entity, $userId)` after each update.
- The `Employee`-specific type hint and the debug log file write (`TMP . 'import_debug.log'`) are removed.
- `EmployeesTable::onExcelImportCreated()` calls `EmployeeDocumentService::createDefaultFolders()`. `EmployeesTable::onExcelImportUpdated()` calls `EmployeeHistoryService::recordChanges()`. Both services are resolved inside the Table via `TableRegistry::getTableLocator()` or simple `new` (consistent with how `EmployeesController` resolves them today).

### 6.6 `ExcelService` (reduced)

- `exportWithLabels()` stays as-is.
- `exportCatalog()` and `importCatalog()` are removed.

### 6.7 Template elements

**`templates/element/excel_wizard/buttons.php`**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var string $module
 * @var bool $importable
 * @var bool $canCreate
 */
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

**`templates/element/excel_wizard/modals.php`** holds the export modal (with `data-module`), the import modal (rendered only when `$importable`), and `$this->Html->script('excel-mapper', ['block' => true])`. Markup is taken verbatim from the current `templates/Employees/index.php` modals, with the title and `data-module` parametrized.

### 6.8 JavaScript

`webroot/js/excel-mapper.js` keeps its current logic. One small change: the download filename fallback (`a.download = 'empleados_…'`) reads `data-download-slug` from the export modal so that catalogs and providers don't fall back to the Employees name.

## 7. RBAC

`AppController` already enforces permissions via `_enforcePermission()` based on a per-action map. Add to that map:

| Action | Permission |
|---|---|
| `exportConfig` | `can_view` |
| `export` | `can_view` |
| `importUpload` | `can_create` |
| `importProcess` | `can_create` |

The `permissions` table is unchanged. Admin still bypasses (existing behavior).

## 8. Migration plan and order

1. **Infrastructure** — interface, table trait, controller trait, refactor `ExcelMappingService` and `ExcelImportService`, create the two template elements, update `excel-mapper.js` for `data-download-slug`.
2. **Migrate Employees** — move `FIELD_DEFINITIONS['Employees']` into `EmployeesTable::getExcelFields()`, move folder/history hooks into the Table, controller switches to `use ExcelWizardTrait;`, `index.php` uses elements. Smoke test before continuing.
3. **Migrate Providers and Invoices** — Providers gets full wizard; Invoices gets export-only via `isExcelImportable() = false`.
4. **Migrate the 8 catalogs** — one at a time, smoke test each.
5. **Delete** `ExcelCatalogTrait`, `ExcelService::exportCatalog`, `ExcelService::importCatalog`, `templates/element/catalog_excel_buttons.php`, `ExcelMappingService::FIELD_DEFINITIONS`, the import debug log write.
6. **Tests** — see §10.

## 9. Files touched

### New
- `src/Model/Excel/ExcelExportableInterface.php`
- `src/Model/Excel/ExcelExportableTrait.php`
- `src/Controller/Trait/ExcelWizardTrait.php`
- `templates/element/excel_wizard/buttons.php`
- `templates/element/excel_wizard/modals.php`
- `tests/TestCase/Service/ExcelMappingServiceTest.php` (or expand if exists)
- `tests/TestCase/Service/ExcelImportServiceTest.php`
- `tests/TestCase/Controller/Trait/ExcelWizardTraitTest.php`

### Modified
- `src/Service/ExcelMappingService.php` — drops constant, accepts Table.
- `src/Service/ExcelImportService.php` — uses Table hooks, drops `Employee` type, drops debug log.
- `src/Service/ExcelService.php` — removes `exportCatalog`/`importCatalog`.
- `src/Controller/AppController.php` — adds the four wizard actions to the permission map.
- `webroot/js/excel-mapper.js` — reads `data-download-slug`.
- 11 Tables: `EmployeesTable`, `ProvidersTable`, `InvoicesTable`, `CostCentersTable`, `OperationCentersTable`, `PositionsTable`, `TemporaryOrganizationsTable`, `EducationLevelsTable`, `MaritalStatusesTable`, `EmployeeStatusesTable`, `DefaultFoldersTable`.
- 11 Controllers (same list).
- 11 `index.php` templates (same list).

### Deleted
- `src/Controller/Trait/ExcelCatalogTrait.php`
- `templates/element/catalog_excel_buttons.php`
- Methods `ExcelService::exportCatalog`, `ExcelService::importCatalog`
- Constant `ExcelMappingService::FIELD_DEFINITIONS`
- Inline export/import actions inside `EmployeesController`, `ProvidersController`, `InvoicesController`
- Debug log line in `ExcelImportService` (`file_put_contents(TMP . 'import_debug.log', …)`)

## 10. Testing

### Automated

- **`ExcelMappingServiceTest`** — `getExportableFields`, `getImportableFields`, `autoMapColumns` with aliases, `validateMapping` with missing required fields, `getLabelMap`. Uses a stub Table (or array fixture) implementing the interface.
- **`ExcelImportServiceTest`** — full `processImport` coverage: upsert path, change detection, FK by code, FK by name (`display_only` + `fk_resolve`), missing required-on-new, callbacks invoked with right args, per-row error capture.
- **`ExcelWizardTraitTest`** — integration test using `IntegrationTestTrait` against a representative migrated controller (e.g. `PositionsController`): export config, export with fields, import upload, import process. Plus one case asserting 405 from `importUpload` when `isExcelImportable() === false`.

### Manual smoke (per migrated module)

| Case | Expected |
|---|---|
| Export all fields | XLSX with Spanish headers and full data. |
| Export subset + reorder via drag handle | XLSX respects selection and order. |
| Export with no fields selected | Inline error in modal. |
| Import with exact headers | Auto-map 100%, all rows pre-checked. |
| Import with alias headers | Auto-map resolves alias. |
| Import missing required field mapping | Live indicator, Import button disabled. |
| Import unknown FK code | Per-row error, other rows succeed. |
| Import idempotent (no real changes) | Summary shows `unchanged: N`. |
| Import row with empty key field | `skipped++`, no error. |
| Invoices: POST `/invoices/import-upload` | HTTP 405. |
| Invoices: UI does not show Import button | Verified visually. |
| User without `can_view`: POST `/empleados/export` | HTTP 403. |
| User without `can_create`: POST `/empleados/import-upload` | HTTP 403. |

### Regression check (Employees only)

Run an identical import in dev before and after the migration. Compare:

- Row count in `employee_folders` (default folders created on insert).
- Row count in `employee_histories` (audit entries on update).

Counts must match exactly.

## 11. Risks

| Risk | Mitigation |
|---|---|
| Employees regression after refactor | Step 2 isolated, manually smoke-tested before continuing. Regression check in §10. |
| Catalog `export` action changed from GET to POST | Old GET links lived only in `catalog_excel_buttons.php`, deleted in step 5. `grep` for stragglers before deletion. |
| New actions not resolved by routes | `routes.php` ends with `$builder->fallbacks()`, which resolves them by convention. Verify in step 1. |
| Tables resolving services via `TableLocator`/`new` (e.g. `EmployeeHistoryService` inside the Table) feels like a layering violation | Acceptable: matches how `EmployeesController` already resolves them today. Pure DI would require a service container, out of scope. |
| Catalogs with very few fields show a near-empty export modal | Accepted UX trade-off. Optional later refinement: hide drag handle when `count($fields) <= 2`. |

## 12. Out-of-scope follow-ups (record only)

- Add the wizard to `EmployeeNovelties`, `PaymentSchedulings`, `PettyCashRecords`, `BankingEntities`, `ExpenseTypes`, `NoveltyTypes`, `Approvers`, `Roles`, `Users`. (User originally chose option B; these were explicitly excluded.)
- Replace the previous `file_put_contents(TMP . 'import_debug.log', …)` in `ExcelImportService` with `StructuredLogger` events if observability needs grow.
- Hide drag handle on tiny exports (≤ 2 fields).

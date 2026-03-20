# Convention Violations Fix — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix all code that violates the architectural conventions defined in ARCHITECTURE.md — hardcoded strings, missing pagination config, services instantiated outside `initialize()`, and business logic in wrong layers.

**Architecture:** Bottom-up approach — first add missing constants (foundation), then fix services, then controllers, then entity, then templates. Each task is a self-contained commit that doesn't break anything.

**Tech Stack:** CakePHP 5.3 · PHP 8.2+

---

## Task 1: Add Pipeline Status Constants to InvoiceConstants

**Files:**
- Modify: `src/Constants/InvoiceConstants.php`

**Step 1: Add pipeline status constants after the existing payment constants**

In `src/Constants/InvoiceConstants.php`, add after line 29 (after `PAYMENT_PARTIAL`):

```php
    // Pipeline statuses
    public const STATUS_APROBACION = 'aprobacion';
    public const STATUS_CONTABILIDAD = 'contabilidad';
    public const STATUS_TESORERIA = 'tesoreria';
    public const STATUS_PAGADA = 'pagada';

    public const PIPELINE_STATUSES = [
        self::STATUS_APROBACION,
        self::STATUS_CONTABILIDAD,
        self::STATUS_TESORERIA,
        self::STATUS_PAGADA,
    ];

    // Document types
    public const DOCTYPE_FACTURA = 'Factura';
    public const DOCTYPE_NOTA_DEBITO = 'Nota Debito';
    public const DOCTYPE_CAJA_MENOR = 'Caja menor';
    public const DOCTYPE_TARJETA_CREDITO = 'Tarjeta de Crédito';
    public const DOCTYPE_REINTEGRO = 'Reintegro';
    public const DOCTYPE_LEGALIZACION = 'Legalización';
    public const DOCTYPE_RECIBO = 'Recibo';
    public const DOCTYPE_ANTICIPO = 'Anticipo';
```

**Step 2: Update the existing DOCUMENT_TYPES array to use the new constants**

Replace the hardcoded `DOCUMENT_TYPES` array with references to the new constants:

```php
    public const DOCUMENT_TYPES = [
        self::DOCTYPE_FACTURA,
        self::DOCTYPE_NOTA_DEBITO,
        self::DOCTYPE_CAJA_MENOR,
        self::DOCTYPE_TARJETA_CREDITO,
        self::DOCTYPE_REINTEGRO,
        self::DOCTYPE_LEGALIZACION,
        self::DOCTYPE_RECIBO,
        self::DOCTYPE_ANTICIPO,
    ];
```

**Step 3: Commit**

```bash
git add src/Constants/InvoiceConstants.php
git commit -m "feat: add pipeline status and document type constants to InvoiceConstants"
```

---

## Task 2: Update InvoicePipelineService to Use InvoiceConstants

**Files:**
- Modify: `src/Service/InvoicePipelineService.php`

**Step 1: Replace hardcoded status strings with InvoiceConstants references**

Add `use App\Constants\InvoiceConstants;` if not present.

Replace line 30:
```php
// OLD
public const STATUSES = ['aprobacion', 'contabilidad', 'tesoreria', 'pagada'];
// NEW
public const STATUSES = InvoiceConstants::PIPELINE_STATUSES;
```

Replace lines 33-36 (STATUS_LABELS):
```php
public const STATUS_LABELS = [
    InvoiceConstants::STATUS_APROBACION    => 'Aprobación',
    InvoiceConstants::STATUS_CONTABILIDAD  => 'Contabilidad',
    InvoiceConstants::STATUS_TESORERIA     => 'Tesorería',
    InvoiceConstants::STATUS_PAGADA        => 'Pagada',
];
```

Replace lines 40-43 (STATUS_ICONS):
```php
public const STATUS_ICONS = [
    InvoiceConstants::STATUS_APROBACION    => 'bi-check-circle',
    InvoiceConstants::STATUS_CONTABILIDAD  => 'bi-calculator',
    InvoiceConstants::STATUS_TESORERIA     => 'bi-bank',
    InvoiceConstants::STATUS_PAGADA        => 'bi-cash-coin',
];
```

Replace lines 48-51 (ROLE_VISIBLE_STATUSES):
```php
public const ROLE_VISIBLE_STATUSES = [
    RoleConstants::REGISTRO_REVISION => [InvoiceConstants::STATUS_APROBACION],
    RoleConstants::CONTABILIDAD      => [InvoiceConstants::STATUS_CONTABILIDAD],
    RoleConstants::TESORERIA         => [InvoiceConstants::STATUS_TESORERIA],
    RoleConstants::ADMIN             => InvoiceConstants::PIPELINE_STATUSES,
];
```

Replace lines 67-81 (SECTIONS_BY_ROLE keys) — change the string keys:
```php
'aprobacion'    => [...]  →  InvoiceConstants::STATUS_APROBACION    => [...]
'contabilidad'  => [...]  →  InvoiceConstants::STATUS_CONTABILIDAD  => [...]
'tesoreria'     => [...]  →  InvoiceConstants::STATUS_TESORERIA     => [...]
```

Replace lines 96, 113, 130 (EDITABLE_FIELDS keys):
```php
'aprobacion'    => [...]  →  InvoiceConstants::STATUS_APROBACION    => [...]
'contabilidad'  => [...]  →  InvoiceConstants::STATUS_CONTABILIDAD  => [...]
'tesoreria'     => [...]  →  InvoiceConstants::STATUS_TESORERIA     => [...]
```

Replace lines 146-149 (TRANSITIONS):
```php
public const TRANSITIONS = [
    InvoiceConstants::STATUS_APROBACION    => InvoiceConstants::STATUS_CONTABILIDAD,
    InvoiceConstants::STATUS_CONTABILIDAD  => InvoiceConstants::STATUS_TESORERIA,
    InvoiceConstants::STATUS_TESORERIA     => InvoiceConstants::STATUS_PAGADA,
    InvoiceConstants::STATUS_PAGADA        => null,
];
```

Replace line 368 (`'aprobacion'` in method body):
```php
// OLD
$currentStatus === 'aprobacion'
// NEW
$currentStatus === InvoiceConstants::STATUS_APROBACION
```

**Step 2: Commit**

```bash
git add src/Service/InvoicePipelineService.php
git commit -m "refactor: use InvoiceConstants for pipeline statuses in InvoicePipelineService"
```

---

## Task 3: Fix Invoice Entity Hardcoded String

**Files:**
- Modify: `src/Model/Entity/Invoice.php`

**Step 1: Replace hardcoded 'pagada' on line 61**

```php
// OLD (line 61)
return ($this->pipeline_status ?? '') === 'pagada';
// NEW
return ($this->pipeline_status ?? '') === InvoiceConstants::STATUS_PAGADA;
```

The `use App\Constants\InvoiceConstants;` import already exists (line 6).

**Step 2: Commit**

```bash
git add src/Model/Entity/Invoice.php
git commit -m "refactor: use InvoiceConstants::STATUS_PAGADA in Invoice entity"
```

---

## Task 4: Fix NotificationService Hardcoded Strings

**Files:**
- Modify: `src/Service/NotificationService.php`

**Step 1: Add import and replace lines 188-191**

Add `use App\Constants\InvoiceConstants;` and `use App\Constants\RoleConstants;` at the top if not present.

Replace `getStatusRoleMapping()` (lines 187-192):
```php
return [
    InvoiceConstants::STATUS_APROBACION    => null,
    InvoiceConstants::STATUS_CONTABILIDAD  => RoleConstants::CONTABILIDAD,
    InvoiceConstants::STATUS_TESORERIA     => RoleConstants::TESORERIA,
    InvoiceConstants::STATUS_PAGADA        => null,
];
```

**Step 2: Commit**

```bash
git add src/Service/NotificationService.php
git commit -m "refactor: use constants in NotificationService status-role mapping"
```

---

## Task 5: Fix PettyCashService Hardcoded Strings

**Files:**
- Modify: `src/Service/PettyCashService.php`

**Step 1: Add import if missing**

Add `use App\Constants\InvoiceConstants;` at top if not already present.

**Step 2: Replace hardcoded strings**

Line 29 — `'Caja menor'`:
```php
// OLD
if ($invoice->document_type !== 'Caja menor') {
// NEW
if ($invoice->document_type !== InvoiceConstants::DOCTYPE_CAJA_MENOR) {
```

Line 35 — `'contabilidad'`:
```php
// OLD
if ($invoice->pipeline_status !== 'contabilidad') {
// NEW
if ($invoice->pipeline_status !== InvoiceConstants::STATUS_CONTABILIDAD) {
```

Line 144 — `'contabilidad'`:
```php
'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
```

Line 149 — `'tesoreria'`:
```php
'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
```

Line 157 — `'pagada'`:
```php
'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
```

Line 242 — `'Caja menor'`:
```php
'Invoices.document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR,
```

Line 243 — `'contabilidad'`:
```php
'Invoices.pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
```

**Step 3: Commit**

```bash
git add src/Service/PettyCashService.php
git commit -m "refactor: use InvoiceConstants in PettyCashService"
```

---

## Task 6: Fix LegalizationService Hardcoded Strings

**Files:**
- Modify: `src/Service/LegalizationService.php`

**Step 1: Add import if missing**

Add `use App\Constants\InvoiceConstants;` at top if not already present.

**Step 2: Replace hardcoded strings**

Line 29 — `'Legalización'`:
```php
if ($invoice->document_type !== InvoiceConstants::DOCTYPE_LEGALIZACION) {
```

Line 35 — `'aprobacion'`:
```php
if ($invoice->pipeline_status !== InvoiceConstants::STATUS_APROBACION) {
```

Line 143 — `'contabilidad'`:
```php
'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
```

Line 147 — `'tesoreria'`:
```php
'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
```

Line 154 — `'pagada'`:
```php
'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
```

Line 235 — `'Legalización'`:
```php
'Invoices.document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
```

Line 236 — `'aprobacion'`:
```php
'Invoices.pipeline_status' => InvoiceConstants::STATUS_APROBACION,
```

**Step 3: Commit**

```bash
git add src/Service/LegalizationService.php
git commit -m "refactor: use InvoiceConstants in LegalizationService"
```

---

## Task 7: Fix AppController Hardcoded Strings

**Files:**
- Modify: `src/Controller/AppController.php`

**Step 1: Add import**

Add `use App\Constants\InvoiceConstants;` at top if not present.

**Step 2: Replace hardcoded strings**

Line 165 — `'Rechazada'`:
```php
->where(['area_approval' => InvoiceConstants::APPROVAL_REJECTED])
```

Line 170 — `'pagada'`:
```php
'pipeline_status !=' => InvoiceConstants::STATUS_PAGADA,
```

Line 176 — `'pagado'` (PettyCash):
```php
// Add use App\Constants\PettyCashConstants; at top
->where(['status !=' => PettyCashConstants::STATUS_PAGADO])
```

Line 181 — `'pagado'` (Legalization):
```php
// Add use App\Constants\LegalizationConstants; at top
->where(['status !=' => LegalizationConstants::STATUS_PAGADO])
```

Line 186 — `['pagada', 'rechazada']` (Novelty):
```php
// Add use App\Constants\NoveltyConstants; at top
->where(['pipeline_status NOT IN' => [NoveltyConstants::STATUS_PAGADA, NoveltyConstants::STATUS_RECHAZADA]])
```

**Step 3: Commit**

```bash
git add src/Controller/AppController.php
git commit -m "refactor: use constants in AppController sidebar counters"
```

---

## Task 8: Fix InvoicesController Hardcoded Strings

**Files:**
- Modify: `src/Controller/InvoicesController.php`

**Step 1: Add import if missing**

Ensure `use App\Constants\InvoiceConstants;` is present (likely already is).

**Step 2: Replace hardcoded strings**

Line 53 — `'Caja menor'`:
```php
'Invoices.document_type !=' => InvoiceConstants::DOCTYPE_CAJA_MENOR,
```

Line 54 — `'aprobacion'`:
```php
'Invoices.pipeline_status' => InvoiceConstants::STATUS_APROBACION,
```

Line 103 — `'pagada'`:
```php
'Invoices.pipeline_status !=' => InvoiceConstants::STATUS_PAGADA,
```

Line 139 — `'aprobacion'`:
```php
$isApproved = $invoice->pipeline_status === InvoiceConstants::STATUS_APROBACION && $invoice->area_approval === InvoiceConstants::APPROVAL_APPROVED;
```

Line 159 — `'aprobacion'`:
```php
$data['pipeline_status'] = InvoiceConstants::STATUS_APROBACION;
```

Line 209 — `'aprobacion'`:
```php
$isApproved = $invoice->pipeline_status === InvoiceConstants::STATUS_APROBACION && $invoice->area_approval === InvoiceConstants::APPROVAL_APPROVED;
```

**Step 3: Commit**

```bash
git add src/Controller/InvoicesController.php
git commit -m "refactor: use InvoiceConstants in InvoicesController"
```

---

## Task 9: Fix DashboardController Hardcoded Strings

**Files:**
- Modify: `src/Controller/DashboardController.php`

**Step 1: Add imports**

```php
use App\Constants\InvoiceConstants;
use App\Service\InvoicePipelineService;
```

**Step 2: Replace all hardcoded strings**

Lines 38-42 (invoice stats):
```php
'aprobacion' => $this->_safeCount('Invoices', ['pipeline_status' => InvoiceConstants::STATUS_APROBACION]),
'contabilidad' => $this->_safeCount('Invoices', ['pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD]),
'tesoreria' => $this->_safeCount('Invoices', ['pipeline_status' => InvoiceConstants::STATUS_TESORERIA]),
'pagada' => $this->_safeCount('Invoices', ['pipeline_status' => InvoiceConstants::STATUS_PAGADA]),
'rechazada' => $this->_safeCount('Invoices', ['area_approval' => InvoiceConstants::APPROVAL_REJECTED]),
```

Line 277 — `'pagada'`:
```php
->where(array_merge(['pipeline_status' => InvoiceConstants::STATUS_PAGADA], $dateConditions))
```

Line 283 — status array:
```php
'pipeline_status IN' => [InvoiceConstants::STATUS_APROBACION, InvoiceConstants::STATUS_CONTABILIDAD, InvoiceConstants::STATUS_TESORERIA],
```

Line 286 — `'Rechazada'`:
```php
'area_approval !=' => InvoiceConstants::APPROVAL_REJECTED,
```

Line 300 — `'pagada'`:
```php
'pipeline_status !=' => InvoiceConstants::STATUS_PAGADA,
```

Line 303 — `'Rechazada'`:
```php
'area_approval !=' => InvoiceConstants::APPROVAL_REJECTED,
```

Line 332 — status loop:
```php
foreach (InvoiceConstants::PIPELINE_STATUSES as $status) {
```

Line 341 — `'Rechazada'`:
```php
->where(array_merge(['area_approval' => InvoiceConstants::APPROVAL_REJECTED], $dateConditions))
```

**Step 3: Commit**

```bash
git add src/Controller/DashboardController.php
git commit -m "refactor: use InvoiceConstants in DashboardController"
```

---

## Task 10: Fix EmployeeNoveltiesController Hardcoded Role

**Files:**
- Modify: `src/Controller/EmployeeNoveltiesController.php`

**Step 1: Verify import exists**

`use App\Constants\RoleConstants;` — check if already imported (file already uses constants from line 6-7). If not, add it.

**Step 2: Replace line 51**

```php
// OLD
if ($roleName !== 'Administrador') {
// NEW
if ($roleName !== RoleConstants::ADMIN) {
```

**Step 3: Commit**

```bash
git add src/Controller/EmployeeNoveltiesController.php
git commit -m "refactor: use RoleConstants::ADMIN in EmployeeNoveltiesController"
```

---

## Task 11: Move Services from Action Methods to initialize()

**Files:**
- Modify: `src/Controller/EmployeeNoveltiesController.php`
- Modify: `src/Controller/SystemSettingsController.php`
- Modify: `src/Controller/DianCrosschecksController.php`
- Modify: `src/Controller/NoveltyLiquidationDocsController.php`

**Step 1: EmployeeNoveltiesController — add LeaveDocumentService and NoveltySignatureService to initialize()**

Add properties after line 26:
```php
private LeaveDocumentService $leaveDocumentService;
private NoveltySignatureService $signatureService;
```

Add to `initialize()` (after line 36):
```php
$this->leaveDocumentService = new LeaveDocumentService();
$this->signatureService = new NoveltySignatureService();
```

Replace `new LeaveDocumentService()` on lines 181 and 213 with `$this->leaveDocumentService`.
Replace `new NoveltySignatureService()` on line 290 with `$this->signatureService`.

**Step 2: SystemSettingsController — add NotificationService to initialize()**

Add property and initialize the service. Replace `new NotificationService()` on line 68 with `$this->notificationService`.

**Step 3: DianCrosschecksController — add DianCrosscheckService to initialize()**

Add property and initialize. Replace `new DianCrosscheckService()` on line 51 with `$this->crosscheckService`.

**Step 4: NoveltyLiquidationDocsController — add NoveltySignatureService to initialize()**

Add property and initialize. Replace `new NoveltySignatureService()` on line 194 with `$this->signatureService`.

**Step 5: Commit**

```bash
git add src/Controller/EmployeeNoveltiesController.php src/Controller/SystemSettingsController.php src/Controller/DianCrosschecksController.php src/Controller/NoveltyLiquidationDocsController.php
git commit -m "refactor: move service instantiation to initialize() in controllers"
```

---

## Task 12: Add Missing $paginate Property to All Controllers

**Files:**
- Modify: `src/Controller/ApproversController.php`
- Modify: `src/Controller/CostCentersController.php`
- Modify: `src/Controller/DefaultFoldersController.php`
- Modify: `src/Controller/DianCrosschecksController.php`
- Modify: `src/Controller/EducationLevelsController.php`
- Modify: `src/Controller/EmployeeStatusesController.php`
- Modify: `src/Controller/ExpenseTypesController.php`
- Modify: `src/Controller/InvoiceHistoriesController.php`
- Modify: `src/Controller/InvoicesController.php`
- Modify: `src/Controller/LegalizationRecordsController.php`
- Modify: `src/Controller/MaritalStatusesController.php`
- Modify: `src/Controller/OperationCentersController.php`
- Modify: `src/Controller/PettyCashRecordsController.php`
- Modify: `src/Controller/PositionsController.php`
- Modify: `src/Controller/ProvidersController.php`
- Modify: `src/Controller/RolesController.php`
- Modify: `src/Controller/TemporaryOrganizationsController.php`
- Modify: `src/Controller/UsersController.php`

**Step 1: Add pagination property to each controller**

For each controller listed, add after the class opening brace (or after existing properties):

```php
public array $paginate = ['limit' => 15, 'maxLimit' => 15];
```

**Pattern:** Open each file, find the class declaration line, and add the property as the first line inside the class (before any existing property or method).

For controllers that already have an `initialize()` method (like `PettyCashRecordsController`, `LegalizationRecordsController`, `InvoicesController`), add the property before `initialize()`.

For simple controllers without `initialize()` (like `ProvidersController`, `CostCentersController`, etc.), add it right after the class opening brace.

**Step 2: Commit**

```bash
git add src/Controller/ApproversController.php src/Controller/CostCentersController.php src/Controller/DefaultFoldersController.php src/Controller/DianCrosschecksController.php src/Controller/EducationLevelsController.php src/Controller/EmployeeStatusesController.php src/Controller/ExpenseTypesController.php src/Controller/InvoiceHistoriesController.php src/Controller/InvoicesController.php src/Controller/LegalizationRecordsController.php src/Controller/MaritalStatusesController.php src/Controller/OperationCentersController.php src/Controller/PettyCashRecordsController.php src/Controller/PositionsController.php src/Controller/ProvidersController.php src/Controller/RolesController.php src/Controller/TemporaryOrganizationsController.php src/Controller/UsersController.php
git commit -m "fix: add missing paginate config (limit 15) to all controllers"
```

---

## Task 13: Fix Hardcoded Strings in Templates — Invoices

**Files:**
- Modify: `templates/Invoices/index.php`
- Modify: `templates/Invoices/view.php`
- Modify: `templates/Invoices/edit.php`

**Step 1: templates/Invoices/index.php**

At the top of the file, add PHP use statement:
```php
<?php
use App\Constants\InvoiceConstants;
use App\Service\InvoicePipelineService;
```

Replace lines 19-22 (status map) to use constants from `InvoicePipelineService::STATUS_LABELS`:
```php
$statusMap = [
    InvoiceConstants::STATUS_APROBACION    => ['Aprobación',    'bg-info text-dark'],
    InvoiceConstants::STATUS_CONTABILIDAD  => ['Contabilidad',  'bg-primary'],
    InvoiceConstants::STATUS_TESORERIA     => ['Tesorería',     'bg-warning text-dark'],
    InvoiceConstants::STATUS_PAGADA        => ['Pagada',        'bg-success'],
];
```

Replace lines 28-31 (filter labels):
```php
$statusFilterLabels = InvoicePipelineService::STATUS_LABELS;
```

Replace line 167 — `'Rechazada'`:
```php
$isRejected = ($invoice->area_approval === InvoiceConstants::APPROVAL_REJECTED);
```

Replace line 168 — `'aprobacion'` and `'Aprobada'`:
```php
$isApproved = ($invoice->pipeline_status === InvoiceConstants::STATUS_APROBACION && $invoice->area_approval === InvoiceConstants::APPROVAL_APPROVED);
```

Replace line 169 — `'Pago Parcial'` and `'tesoreria'`:
```php
$isPartialPay = ($invoice->pipeline_status === InvoiceConstants::STATUS_TESORERIA && $invoice->payment_status === InvoiceConstants::PAYMENT_PARTIAL);
```

**Step 2: templates/Invoices/view.php**

Add use statements at top. Replace all hardcoded status strings with `InvoiceConstants::*` references. Key replacements:

Lines 14-17 (status map), lines 22-23 (approval map), lines 27 (dian map), line 104 (`'tesoreria'` and `'Pago Parcial'`), lines 246 (`'Pendiente'`), lines 256 (`'Pendiente'`), lines 286-288 (`'Pago Parcial'`, `'Pago total'`), lines 330-333 (status labels), line 390 (badge colors).

**Step 3: templates/Invoices/edit.php**

Add use statements at top. Replace:
- Line 28 — approval options: use `InvoiceConstants::APPROVAL_STATUSES` or build from constants
- Line 41 — payment options: use `InvoiceConstants::PAYMENT_FULL` and `InvoiceConstants::PAYMENT_PARTIAL`

```php
$paymentStatusOptions = ['' => '-- Seleccione --', InvoiceConstants::PAYMENT_FULL => 'Pago total', InvoiceConstants::PAYMENT_PARTIAL => 'Pago Parcial'];
```

**Step 4: Commit**

```bash
git add templates/Invoices/index.php templates/Invoices/view.php templates/Invoices/edit.php
git commit -m "refactor: use constants in Invoice templates"
```

---

## Task 14: Fix Hardcoded Strings in Templates — Dashboard, Pipeline Element, and Others

**Files:**
- Modify: `templates/Dashboard/index.php`
- Modify: `templates/element/pipeline_progress.php`
- Modify: `templates/PettyCashRecords/edit.php`
- Modify: `templates/LegalizationRecords/edit.php`
- Modify: `templates/LegalizationRecords/index.php`
- Modify: `templates/LegalizationRecords/view.php`

**Step 1: templates/Dashboard/index.php**

Add `use App\Constants\InvoiceConstants;` at top.

Replace line 202 — `'Rechazada'`:
```php
<?php if (($invoice->area_approval ?? '') === InvoiceConstants::APPROVAL_REJECTED): ?>
```

**Step 2: templates/element/pipeline_progress.php**

Add `use App\Constants\InvoiceConstants;` at top.

Replace line 22:
```php
$isPartialPayment = ($currentStatus === InvoiceConstants::STATUS_TESORERIA && $paymentStatus === InvoiceConstants::PAYMENT_PARTIAL);
```

Replace lines 16-19 (status icons) with `InvoicePipelineService::STATUS_ICONS` or constants:
```php
$statusIcons = InvoicePipelineService::STATUS_ICONS;
```

Replace line 90 — `'tesoreria'`:
```php
<?php elseif ($status === InvoiceConstants::STATUS_TESORERIA && $isPartialPayment): ?>
```

**Step 3: templates/PettyCashRecords/edit.php**

Replace line 36:
```php
use App\Constants\InvoiceConstants;
// ...
$paymentStatusOptions = ['' => '-- Seleccione --', InvoiceConstants::PAYMENT_FULL => 'Pago total', InvoiceConstants::PAYMENT_PARTIAL => 'Pago Parcial'];
```

**Step 4: templates/LegalizationRecords/edit.php**

Same replacement as PettyCashRecords/edit.php line 36.

**Step 5: templates/LegalizationRecords/index.php and view.php**

Replace hardcoded status strings (`'contabilidad'`, `'tesoreria'`, `'aprobacion'`, `'pagada'`) with `InvoiceConstants::STATUS_*` or `LegalizationConstants::STATUS_*` as appropriate.

**Step 6: Commit**

```bash
git add templates/Dashboard/index.php templates/element/pipeline_progress.php templates/PettyCashRecords/edit.php templates/LegalizationRecords/edit.php templates/LegalizationRecords/index.php templates/LegalizationRecords/view.php
git commit -m "refactor: use constants in Dashboard, pipeline element, and PettyCash/Legalization templates"
```

---

## Task 15: Run Code Style Check and Fix

**Step 1: Run code style check**

```bash
composer cs-check
```

**Step 2: Fix any code style issues**

```bash
composer cs-fix
```

**Step 3: Commit if there were fixes**

```bash
git add -u
git commit -m "fix: code style fixes after convention refactoring"
```

---

## Summary of Changes

| Task | Category | Files Changed | Impact |
|------|----------|--------------|--------|
| 1 | Constants | InvoiceConstants.php | Add pipeline + doctype constants |
| 2 | Service | InvoicePipelineService.php | Reference new constants |
| 3 | Entity | Invoice.php | 1 hardcoded string |
| 4 | Service | NotificationService.php | 4 hardcoded strings |
| 5 | Service | PettyCashService.php | 7 hardcoded strings |
| 6 | Service | LegalizationService.php | 7 hardcoded strings |
| 7 | Controller | AppController.php | 5 hardcoded strings |
| 8 | Controller | InvoicesController.php | 6 hardcoded strings |
| 9 | Controller | DashboardController.php | 10+ hardcoded strings |
| 10 | Controller | EmployeeNoveltiesController.php | 1 hardcoded role |
| 11 | Controller | 4 controllers | Services in initialize() |
| 12 | Controller | 18 controllers | Missing $paginate |
| 13 | Templates | 3 Invoice templates | Hardcoded strings |
| 14 | Templates | 6 other templates | Hardcoded strings |
| 15 | Quality | All changed files | Code style |

**Total: ~15 commits, ~30 files modified**

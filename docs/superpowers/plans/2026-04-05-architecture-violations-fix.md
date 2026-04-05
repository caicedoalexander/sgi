# Architecture Violations Fix Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all architecture violations detected in the SGI codebase audit against ARCHITECTURE.md rules.

**Architecture:** Six categories of fixes — critical bug, hardcoded strings, service DI pattern, controller service instantiation, Table business logic extraction, and removal of dead `formatDateEs` references from ARCHITECTURE.md.

**Tech Stack:** CakePHP 5.3, PHP 8.2+

---

### Task 1: Fix critical bug — undefined `$service` variable in EmployeeNoveltiesController

**Files:**
- Modify: `src/Controller/EmployeeNoveltiesController.php:536`

- [ ] **Step 1: Fix the undefined variable**

In `exportPdf()` method, line 536 uses `$service->generatePdf(...)` but `$service` is never defined. The controller already has `$this->leaveDocumentService` initialized in `initialize()` (line 41).

Change line 536 from:
```php
$pdfContent = $service->generatePdf((int)$id, (int)$template->id);
```
to:
```php
$pdfContent = $this->leaveDocumentService->generatePdf((int)$id, (int)$template->id);
```

- [ ] **Step 2: Verify the fix compiles**

Run:
```bash
php -l src/Controller/EmployeeNoveltiesController.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Controller/EmployeeNoveltiesController.php
git commit -m "fix: use leaveDocumentService property instead of undefined \$service in exportPdf()"
```

---

### Task 2: Replace hardcoded domain strings with constants

**Files:**
- Modify: `src/Controller/ExternalApprovalsController.php:144`
- Modify: `templates/Invoices/edit.php:499,503,523,568-572,592-596`

Constants to use (already defined in `src/Constants/InvoiceConstants.php`):
- `InvoiceConstants::STATUS_APROBACION` = `'aprobacion'`
- `InvoiceConstants::APPROVAL_REJECTED` = `'Rechazada'`
- `InvoiceConstants::APPROVER_STATUS_APPROVED` = `'Aprobada'`
- `InvoiceConstants::APPROVER_STATUS_REJECTED` = `'Rechazada'`

- [ ] **Step 1: Fix ExternalApprovalsController.php**

At line 144, change:
```php
if ($invoice->pipeline_status === 'aprobacion') {
```
to:
```php
if ($invoice->pipeline_status === InvoiceConstants::STATUS_APROBACION) {
```

Also add the import at the top of the file (after existing `use` statements around line 6-10):
```php
use App\Constants\InvoiceConstants;
```

- [ ] **Step 2: Fix templates/Invoices/edit.php — rejection check (line 499)**

Change:
```php
<?php if (($invoice->area_approval ?? '') === 'Rechazada'): ?>
```
to:
```php
<?php if (($invoice->area_approval ?? '') === \App\Constants\InvoiceConstants::APPROVAL_REJECTED): ?>
```

- [ ] **Step 3: Fix templates/Invoices/edit.php — rejector loop (line 503)**

Change:
```php
if ($a->status === 'Rechazada') { $rejector = $a; break; }
```
to:
```php
if ($a->status === \App\Constants\InvoiceConstants::APPROVER_STATUS_REJECTED) { $rejector = $a; break; }
```

- [ ] **Step 4: Fix templates/Invoices/edit.php — aprobacion check (line 523)**

Change:
```php
<?php if (!$hasPendingApprovals && !empty($editableFields) && $currentStatus === 'aprobacion'): ?>
```
to:
```php
<?php if (!$hasPendingApprovals && !empty($editableFields) && $currentStatus === \App\Constants\InvoiceConstants::STATUS_APROBACION): ?>
```

- [ ] **Step 5: Fix templates/Invoices/edit.php — match counters (lines 568-572)**

Change:
```php
match ($a->status) {
    'Aprobada' => $approvedCount++,
    'Rechazada' => $rejectedCount++,
    default => $pendingCount++,
};
```
to:
```php
match ($a->status) {
    \App\Constants\InvoiceConstants::APPROVER_STATUS_APPROVED => $approvedCount++,
    \App\Constants\InvoiceConstants::APPROVER_STATUS_REJECTED => $rejectedCount++,
    default => $pendingCount++,
};
```

- [ ] **Step 6: Fix templates/Invoices/edit.php — status icons (lines 592-596)**

Change:
```php
$statusIcon = match ($approval->status) {
    'Aprobada' => '<i class="bi bi-check-circle-fill" style="color:#469D61;"></i>',
    'Rechazada' => '<i class="bi bi-x-circle-fill" style="color:#dc3545;"></i>',
    default => '<i class="bi bi-clock" style="color:#888;"></i>',
};
```
to:
```php
$statusIcon = match ($approval->status) {
    \App\Constants\InvoiceConstants::APPROVER_STATUS_APPROVED => '<i class="bi bi-check-circle-fill" style="color:#469D61;"></i>',
    \App\Constants\InvoiceConstants::APPROVER_STATUS_REJECTED => '<i class="bi bi-x-circle-fill" style="color:#dc3545;"></i>',
    default => '<i class="bi bi-clock" style="color:#888;"></i>',
};
```

- [ ] **Step 7: Verify syntax**

Run:
```bash
php -l src/Controller/ExternalApprovalsController.php
php -l templates/Invoices/edit.php
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 8: Commit**

```bash
git add src/Controller/ExternalApprovalsController.php templates/Invoices/edit.php
git commit -m "refactor: replace hardcoded domain strings with InvoiceConstants"
```

---

### Task 3: Fix service DI to use nullable constructor pattern

**Files:**
- Modify: `src/Service/DianCrosscheckService.php:18,23-26`
- Modify: `src/Service/N8nService.php:8-9,14-18`
- Modify: `src/Service/NotificationService.php:17,19-22`

- [ ] **Step 1: Fix DianCrosscheckService**

Change lines 18-26 from:
```php
private N8nService $n8nService;

/**
 * Constructor.
 */
public function __construct()
{
    $this->n8nService = new N8nService();
}
```
to:
```php
private N8nService $n8nService;

/**
 * Constructor.
 */
public function __construct(?N8nService $n8nService = null)
{
    $this->n8nService = $n8nService ?? new N8nService();
}
```

- [ ] **Step 2: Fix N8nService**

Change lines 8-18 from:
```php
private WebhookService $webhookService;
private SystemSettingsService $settingsService;

/**
 * Constructor.
 */
public function __construct()
{
    $this->webhookService = new WebhookService();
    $this->settingsService = new SystemSettingsService();
}
```
to:
```php
private WebhookService $webhookService;
private SystemSettingsService $settingsService;

/**
 * Constructor.
 */
public function __construct(
    ?WebhookService $webhookService = null,
    ?SystemSettingsService $settingsService = null,
) {
    $this->webhookService = $webhookService ?? new WebhookService();
    $this->settingsService = $settingsService ?? new SystemSettingsService();
}
```

- [ ] **Step 3: Fix NotificationService**

Change lines 17-22 from:
```php
private SystemSettingsService $settings;

public function __construct()
{
    $this->settings = new SystemSettingsService();
}
```
to:
```php
private SystemSettingsService $settings;

public function __construct(?SystemSettingsService $settings = null)
{
    $this->settings = $settings ?? new SystemSettingsService();
}
```

- [ ] **Step 4: Verify syntax**

Run:
```bash
php -l src/Service/DianCrosscheckService.php
php -l src/Service/N8nService.php
php -l src/Service/NotificationService.php
```
Expected: `No syntax errors detected` for all three.

- [ ] **Step 5: Commit**

```bash
git add src/Service/DianCrosscheckService.php src/Service/N8nService.php src/Service/NotificationService.php
git commit -m "refactor: apply nullable DI pattern to service constructors"
```

---

### Task 4: Move inline service instantiation to controller `initialize()`

**Files:**
- Modify: `src/Controller/ProvidersController.php`
- Modify: `src/Controller/RolesController.php`
- Modify: `src/Controller/EmployeeNoveltiesController.php`
- Modify: `src/Controller/LeaveDocumentTemplatesController.php`
- Modify: `src/Controller/EmployeesController.php`
- Modify: `src/Controller/ExternalApprovalsController.php`

Note: `AppController` is excluded — its service instantiations in `beforeFilter`/`_setSidebarCounters` are special cases for the base controller lifecycle (services depend on the authenticated user context which is not available in `initialize()`).

- [ ] **Step 1: Fix ProvidersController**

Add a property and `initialize()` method. The controller currently has no `initialize()`. Add after the `$paginate` declaration (line 10):

```php
private ExcelService $excelService;

public function initialize(): void
{
    parent::initialize();
    $this->excelService = new ExcelService();
}
```

Then replace line 77:
```php
$excelService = new ExcelService();
$filePath = $excelService->exportCatalog('Proveedores', $query);
```
with:
```php
$filePath = $this->excelService->exportCatalog('Proveedores', $query);
```

And replace line 105:
```php
$excelService = new ExcelService();
$result = $excelService->importCatalog('Providers', $file, 'document_number');
```
with:
```php
$result = $this->excelService->importCatalog('Providers', $file, 'document_number');
```

- [ ] **Step 2: Fix RolesController**

Add a property and `initialize()` method after line 10:

```php
private AuthorizationService $authService;

public function initialize(): void
{
    parent::initialize();
    $this->authService = new AuthorizationService();
}
```

Replace all 3 inline instantiations:
- Line 35: `$authService = new AuthorizationService();` → remove, use `$this->authService`
- Line 59: `$authService = new AuthorizationService();` → remove, use `$this->authService`
- Line 68: `$authService = new AuthorizationService();` → remove, use `$this->authService`

In `add()` (line 35-36), change:
```php
$authService = new AuthorizationService();
$authService->savePermissionsForRole($role->id, $data['permissions']);
```
to:
```php
$this->authService->savePermissionsForRole($role->id, $data['permissions']);
```

In `edit()` (line 59-60), change:
```php
$authService = new AuthorizationService();
$authService->savePermissionsForRole($role->id, $data['permissions'] ?? []);
```
to:
```php
$this->authService->savePermissionsForRole($role->id, $data['permissions'] ?? []);
```

In `edit()` (line 68-70), change:
```php
$authService = new AuthorizationService();
$modules = AuthorizationService::MODULES;
$permissionsMatrix = $authService->getPermissionsForRoleAsMatrix((int)$id);
```
to:
```php
$modules = AuthorizationService::MODULES;
$permissionsMatrix = $this->authService->getPermissionsForRoleAsMatrix((int)$id);
```

- [ ] **Step 3: Fix EmployeeNoveltiesController**

Add `NotificationService` to the existing properties (after line 29):
```php
private NotificationService $notificationService;
```

Add to the existing `initialize()` method (after line 43):
```php
$this->notificationService = new NotificationService();
```

Replace line 638:
```php
$notificationService = new NotificationService();
$notificationService->sendNoveltyApprovalEmail($approver, $noveltyForEmail, $approvalUrl);
```
with:
```php
$this->notificationService->sendNoveltyApprovalEmail($approver, $noveltyForEmail, $approvalUrl);
```

Replace line 786:
```php
$notificationService = new NotificationService();
$notificationService->sendNoveltyApprovalEmail($approver, $novelty, $approvalUrl);
```
with:
```php
$this->notificationService->sendNoveltyApprovalEmail($approver, $novelty, $approvalUrl);
```

- [ ] **Step 4: Fix LeaveDocumentTemplatesController**

Add a property and `initialize()` method after line 11:

```php
private LeaveDocumentService $leaveDocumentService;

public function initialize(): void
{
    parent::initialize();
    $this->leaveDocumentService = new LeaveDocumentService();
}
```

Replace all 3 inline instantiations:
- Line 38 in `add()`: `$service = new LeaveDocumentService();` → use `$this->leaveDocumentService`
- Line 100 in `delete()`: `$service = new LeaveDocumentService();` → use `$this->leaveDocumentService`
- Line 156 in `preview()`: `$service = new LeaveDocumentService();` → use `$this->leaveDocumentService`

In `add()`, change:
```php
$service = new LeaveDocumentService();
$result = $service->uploadTemplate($file);
```
to:
```php
$result = $this->leaveDocumentService->uploadTemplate($file);
```

In `delete()`, change:
```php
$service = new LeaveDocumentService();
$service->deleteTemplateFile($template->file_path);
```
to:
```php
$this->leaveDocumentService->deleteTemplateFile($template->file_path);
```

In `preview()`, change:
```php
$service = new LeaveDocumentService();
$pdfContent = $service->generatePreviewPdf((int)$id);
```
to:
```php
$pdfContent = $this->leaveDocumentService->generatePreviewPdf((int)$id);
```

- [ ] **Step 5: Fix EmployeesController**

Add `ExcelMappingService`, `ExcelService`, and `ExcelImportService` as properties (after line 24):
```php
private ExcelMappingService $mappingService;
private ExcelService $excelService;
private ExcelImportService $importService;
```

Add to the existing `initialize()` method (after line 31):
```php
$this->mappingService = new ExcelMappingService();
$this->excelService = new ExcelService();
$this->importService = new ExcelImportService();
```

Replace inline instantiations:
- Line 42: `$mappingService = new ExcelMappingService();` → `$this->mappingService`
- Line 295: `$mappingService = new ExcelMappingService();` → `$this->mappingService`
- Line 299: `$mappingService->...` → `$this->mappingService->...` (also 2 more refs on nearby lines)
- Line 369: `$excelService = new ExcelService();` → `$this->excelService`
- Line 411: `$importService = new ExcelImportService();` → `$this->importService`
- Line 414: `$mappingService = new ExcelMappingService();` → `$this->mappingService`
- Line 471: `$importService = new ExcelImportService();` → `$this->importService`

Also remove the duplicate `$historyService = new EmployeeHistoryService()` at line 472 — `$this->historyService` already exists from `initialize()`.

- [ ] **Step 6: Fix ExternalApprovalsController**

Add `InvoicePipelineService` as a property (after line 15):
```php
private InvoicePipelineService $pipelineService;
```

Add to the existing `initialize()` method (after line 21):
```php
$this->pipelineService = new InvoicePipelineService();
```

Replace line 140:
```php
$pipelineService = new InvoicePipelineService();
$invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
$invoice = $invoicesTable->get($result['invoice_id']);

if ($invoice->pipeline_status === InvoiceConstants::STATUS_APROBACION) {
    $identity = $this->Authentication->getIdentity();
    $pipelineService->advance($invoice, 'Admin', (int)$identity->getIdentifier());
}
```
with:
```php
$invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
$invoice = $invoicesTable->get($result['invoice_id']);

if ($invoice->pipeline_status === InvoiceConstants::STATUS_APROBACION) {
    $identity = $this->Authentication->getIdentity();
    $this->pipelineService->advance($invoice, 'Admin', (int)$identity->getIdentifier());
}
```

Also add the import at the top:
```php
use App\Constants\InvoiceConstants;
```

(Note: the `InvoiceConstants` import is needed here because Task 2 already changed the hardcoded `'aprobacion'` to `InvoiceConstants::STATUS_APROBACION`.)

- [ ] **Step 7: Verify syntax for all modified files**

Run:
```bash
php -l src/Controller/ProvidersController.php
php -l src/Controller/RolesController.php
php -l src/Controller/EmployeeNoveltiesController.php
php -l src/Controller/LeaveDocumentTemplatesController.php
php -l src/Controller/EmployeesController.php
php -l src/Controller/ExternalApprovalsController.php
```
Expected: `No syntax errors detected` for all six.

- [ ] **Step 8: Commit**

```bash
git add src/Controller/ProvidersController.php src/Controller/RolesController.php src/Controller/EmployeeNoveltiesController.php src/Controller/LeaveDocumentTemplatesController.php src/Controller/EmployeesController.php src/Controller/ExternalApprovalsController.php
git commit -m "refactor: move service instantiation to controller initialize() methods"
```

---

### Task 5: Extract business logic from InvoiceReadsTable into a service

**Files:**
- Modify: `src/Model/Table/InvoiceReadsTable.php`
- Modify: Look up callers of `markAsRead` to update references

- [ ] **Step 1: Find all callers of markAsRead**

Run:
```bash
grep -rn 'markAsRead' src/ templates/
```

This identifies all places that call `markAsRead()` so we can update them.

- [ ] **Step 2: Refactor markAsRead to use ORM instead of raw SQL**

The raw SQL `INSERT...ON DUPLICATE KEY UPDATE` should be replaced with CakePHP ORM methods. This keeps the method in the Table class but removes the raw SQL violation. Replace lines 21-32 in `src/Model/Table/InvoiceReadsTable.php`:

```php
/**
 * Marca una factura como leída por el usuario (insert o update).
 */
public function markAsRead(int $invoiceId, int $userId): void
{
    $this->getConnection()->execute(
        'INSERT INTO invoice_reads (invoice_id, user_id, last_visited_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE last_visited_at = NOW()',
        [$invoiceId, $userId],
    );
}
```

with:

```php
/**
 * Marca una factura como leída por el usuario (insert o update).
 */
public function markAsRead(int $invoiceId, int $userId): void
{
    $existing = $this->find()
        ->where(['invoice_id' => $invoiceId, 'user_id' => $userId])
        ->first();

    if ($existing) {
        $existing->last_visited_at = new \Cake\I18n\DateTime();
        $this->save($existing);
    } else {
        $entity = $this->newEntity([
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'last_visited_at' => new \Cake\I18n\DateTime(),
        ]);
        $this->save($entity);
    }
}
```

- [ ] **Step 3: Verify syntax**

Run:
```bash
php -l src/Model/Table/InvoiceReadsTable.php
```
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add src/Model/Table/InvoiceReadsTable.php
git commit -m "refactor: replace raw SQL in InvoiceReadsTable with CakePHP ORM methods"
```

---

### Task 6: Remove `formatDateEs` references from ARCHITECTURE.md

**Files:**
- Modify: `ARCHITECTURE.md:429,574-581`

The function `formatDateEs()` was never implemented and has been removed from the project spec. Remove all references from the architecture doc.

- [ ] **Step 1: Remove from section 3.7 template checklist (line 429)**

Change:
```markdown
- `templates/element/pagination.php` for paginated lists
- `.flatpickr-date` class on date inputs
- `.currency-input` class on money inputs
- `.clickable-row` with `data-href` on table rows
- `AppView::formatDateEs()` for Spanish-formatted dates
```
to:
```markdown
- `templates/element/pagination.php` for paginated lists
- `.flatpickr-date` class on date inputs
- `.currency-input` class on money inputs
- `.clickable-row` with `data-href` on table rows
```

- [ ] **Step 2: Remove section 4.6 Date Formatting entirely (lines 574-581)**

Remove the entire section:
```markdown
### 4.6 Date Formatting

Use `AppView::formatDateEs()` for Spanish-formatted dates in views:

\```php
$this->AppView->formatDateEs($entity->created);
// Output: "Lunes, 17 Febrero 2026"
\```
```

Renumber subsequent sections: 4.7 → 4.6, 4.8 → 4.7, etc. through 4.15 → 4.14.

- [ ] **Step 3: Commit**

```bash
git add ARCHITECTURE.md
git commit -m "docs: remove formatDateEs references — function was never implemented"
```

---

## Summary

| Task | Category | Files | Impact |
|------|----------|-------|--------|
| 1 | Critical bug fix | 1 | Fixes runtime crash in novelty PDF export |
| 2 | Hardcoded strings | 2 | 6 occurrences → use constants |
| 3 | Service DI pattern | 3 | 3 constructors → nullable pattern |
| 4 | Controller initialize() | 6 | ~15 inline instantiations → centralized |
| 5 | Table business logic | 1 | Raw SQL → ORM methods |
| 6 | Dead doc references | 1 | Remove `formatDateEs` from ARCHITECTURE.md |

**Total files modified:** 14
**Execution order:** Tasks 1-6 are independent and can be executed in parallel or any order.

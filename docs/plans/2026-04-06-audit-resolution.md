# Resolving Architecture Audit Findings — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Resolve all critical, warning, and improvement findings from `AUDIT.md`, raising the overall architecture score from 6.7/10 to 8+/10.

**Architecture:** Phased approach: fix critical resilience and data-integrity gaps first (Phases 1-2), then improve layer compliance (Phase 3), eliminate code duplication (Phase 4), fix transaction gaps (Phase 5), and standardize service returns (Phase 6). Each phase is independently deployable.

**Tech Stack:** CakePHP 5.3, PHP 8.2+, MySQL/MariaDB, PHPUnit, Composer

---

## Phase 1: Resilience — External Integration Safety

> AUDIT refs: §2.2 (Critical), §7.1 (Priority 1)
> Impact: A single external API outage can cascade through the entire invoice pipeline.

---

### Task 1: Add Retry with Exponential Backoff to WebhookService

**Files:**
- Modify: `src/Service/WebhookService.php`

**Step 1: Add retry configuration to constructor**

Replace the constructor and add retry constants:

```php
private const MAX_RETRIES = 3;
private const BASE_DELAY_MS = 1000; // 1s, 2s, 4s

private Client $client;
private int $maxRetries;

public function __construct(int $timeout = 30, int $maxRetries = self::MAX_RETRIES)
{
    $this->client = new Client([
        'timeout' => $timeout,
    ]);
    $this->maxRetries = $maxRetries;
}
```

**Step 2: Extract a retry-capable request method**

Add this private method to `WebhookService`:

```php
/**
 * Execute an HTTP request with exponential backoff retry.
 *
 * @param callable $request Closure that performs the HTTP call and returns a Response.
 * @return array{success: bool, statusCode: int, body: string, error: ?string}
 */
private function executeWithRetry(callable $request): array
{
    $lastException = null;

    for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
        try {
            $response = $request();

            // Don't retry client errors (4xx), only server errors (5xx) and timeouts
            if ($response->isOk() || ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500)) {
                return [
                    'success' => $response->isOk(),
                    'statusCode' => $response->getStatusCode(),
                    'body' => (string)$response->getBody(),
                    'error' => $response->isOk() ? null : "HTTP {$response->getStatusCode()}",
                ];
            }

            // Server error — will retry
            $lastException = new Exception("HTTP {$response->getStatusCode()}");
        } catch (Exception $e) {
            $lastException = $e;
        }

        // Exponential backoff: 1s, 2s, 4s
        if ($attempt < $this->maxRetries) {
            $delayMs = self::BASE_DELAY_MS * (2 ** $attempt);
            usleep($delayMs * 1000);
            Log::warning("WebhookService: retry #{$attempt + 1} after {$delayMs}ms — {$lastException->getMessage()}");
        }
    }

    Log::error("WebhookService: all {$this->maxRetries} retries exhausted — {$lastException->getMessage()}");

    return [
        'success' => false,
        'statusCode' => 0,
        'body' => '',
        'error' => "Retries exhausted: {$lastException->getMessage()}",
    ];
}
```

**Step 3: Refactor `post()` and `sendFile()` to use retry**

Replace the `post()` method:

```php
public function post(string $url, mixed $body, array $headers = []): array
{
    return $this->executeWithRetry(function () use ($url, $body, $headers) {
        return $this->client->post($url, (string)$body, [
            'headers' => $headers,
        ]);
    });
}
```

Replace the `sendFile()` method:

```php
public function sendFile(
    string $url,
    string $filePath,
    string $fieldName = 'file',
    array $extraData = [],
    array $headers = [],
): array {
    if (!file_exists($filePath)) {
        return [
            'success' => false,
            'statusCode' => 0,
            'body' => '',
            'error' => "File not found: {$filePath}",
        ];
    }

    return $this->executeWithRetry(function () use ($url, $filePath, $fieldName, $extraData, $headers) {
        return $this->client->post($url, array_merge($extraData, [
            $fieldName => fopen($filePath, 'r'),
        ]), [
            'headers' => $headers,
            'type' => 'multipart/form-data',
        ]);
    });
}
```

**Step 4: Run code style check**

Run: `composer cs-check`
Expected: PASS (or only pre-existing issues)

**Step 5: Commit**

```bash
git add src/Service/WebhookService.php
git commit -m "fix: add retry with exponential backoff to WebhookService

Resolves AUDIT §2.2 — WebhookService had no retry, no backoff.
Now retries up to 3 times (1s/2s/4s) on server errors and timeouts.
Client errors (4xx) are not retried."
```

---

### Task 2: Make NotificationService Return Partial Success

**Files:**
- Modify: `src/Service/NotificationService.php`
- Modify: `src/Service/InvoicePipelineService.php` (caller must handle new return)

**Step 1: Change `sendStatusChangeNotification` return type from `void` to `array`**

In `src/Service/NotificationService.php`, replace the `sendStatusChangeNotification` method signature and the error-throwing block at the end:

```php
/**
 * Send status change notification. Returns result instead of throwing.
 *
 * @return array{sent: int, failed: array<string>}
 */
public function sendStatusChangeNotification(Invoice $invoice, string $fromStatus, string $toStatus): array
{
    $smtpConfig = $this->settings->getGroup('smtp');

    if (empty($smtpConfig['smtp_host']) || empty($smtpConfig['smtp_from_email'])) {
        return ['sent' => 0, 'failed' => ['SMTP no configurado. Configure el correo en Ajustes del Sistema.']];
    }

    $recipients = $this->getRecipientsForStatus($toStatus);

    if (empty($recipients)) {
        Log::info("No hay destinatarios para notificación de estado '{$toStatus}' - factura #{$invoice->id}");

        return ['sent' => 0, 'failed' => []];
    }

    $this->configureTransport($smtpConfig);

    $statusLabels = InvoicePipelineService::STATUS_LABELS;
    $fromLabel = $statusLabels[$fromStatus] ?? $fromStatus;
    $toLabel = $statusLabels[$toStatus] ?? $toStatus;
    $invoiceNumber = $invoice->invoice_number ?: '#' . $invoice->id;

    $sent = 0;
    $failed = [];
    foreach ($recipients as $recipient) {
        try {
            $mailer = new Mailer();
            $mailer->setTransport('sgi_dynamic');
            $mailer->setFrom(
                $smtpConfig['smtp_from_email'],
                $smtpConfig['smtp_from_name'] ?? 'SGI',
            );
            $mailer->setTo($recipient->email);
            $mailer->setSubject("SGI - Factura {$invoiceNumber} avanzó a {$toLabel}");
            $mailer->setEmailFormat('html');
            $mailer->setViewVars([
                'invoiceNumber' => $invoiceNumber,
                'fromLabel' => $fromLabel,
                'toLabel' => $toLabel,
                'invoiceId' => $invoice->id,
            ]);
            $mailer->viewBuilder()
                ->setTemplate('invoice_status_changed')
                ->setLayout('default');
            $mailer->deliver();
            $sent++;
        } catch (Exception $e) {
            Log::error("Email notification failed for {$recipient->email}: " . $e->getMessage());
            $failed[] = $recipient->email . ': ' . $e->getMessage();
        }
    }

    return ['sent' => $sent, 'failed' => $failed];
}
```

**Step 2: Update callers in InvoicePipelineService**

In `src/Service/InvoicePipelineService.php`, find the call to `sendStatusChangeNotification` inside `saveAndAdvance()` or `advance()`. Replace the try/catch that catches the notification exception with:

```php
$notifResult = $this->notificationService->sendStatusChangeNotification($invoice, $fromStatus, $nextStatus);
if (!empty($notifResult['failed'])) {
    $notificationErrors = $notifResult['failed'];
}
```

Remove the `try { ... } catch (Exception $e) { $notificationErrors[] = ... }` wrapper — the method no longer throws.

**Step 3: Run code style check**

Run: `composer cs-check`

**Step 4: Commit**

```bash
git add src/Service/NotificationService.php src/Service/InvoicePipelineService.php
git commit -m "fix: NotificationService returns partial success instead of throwing

Resolves AUDIT §2.2 — NotificationService was all-or-nothing.
Now returns ['sent' => n, 'failed' => [...]] so one failed email
doesn't block the entire invoice pipeline advancement."
```

---

### Task 3: Add Retry Mechanism for DianCrosscheckService

**Files:**
- Modify: `src/Service/DianCrosscheckService.php`

**Step 1: Add retry logic when n8n webhook fails**

In `processUpload()`, replace the n8n send block (lines 73-89) with:

```php
// Send to n8n with retry tracking
if ($this->n8nService->isConfigured('n8n_webhook_dian_crosscheck')) {
    $result = $this->n8nService->sendFile(
        'n8n_webhook_dian_crosscheck',
        $filePath,
        'file',
        ['crosscheck_id' => $entity->id, 'file_name' => $fileName],
    );

    if ($result['success']) {
        $entity->status = 'procesando';
        $entity->n8n_response = $result['body'];
    } else {
        $entity->status = 'error_envio';
        $entity->error_message = $result['error'];
        $entity->attempt_count = 1;
        Log::warning("DianCrosscheck #{$entity->id}: webhook failed, queued for retry — {$result['error']}");
    }
    $table->save($entity);
}
```

**Step 2: Add a `retryFailed()` method for cron/manual retry**

Add this method to `DianCrosscheckService`:

```php
/**
 * Retry failed webhook sends. Call from a cron job or admin action.
 *
 * @param int $maxAttempts Maximum retry attempts before marking as permanent failure.
 * @return array{retried: int, succeeded: int, failed: int}
 */
public function retryFailed(int $maxAttempts = 3): array
{
    $table = TableRegistry::getTableLocator()->get('DianCrosschecks');
    $pending = $table->find()
        ->where([
            'status' => 'error_envio',
            'attempt_count <' => $maxAttempts,
        ])
        ->all();

    $retried = 0;
    $succeeded = 0;
    $failed = 0;

    foreach ($pending as $entity) {
        $retried++;
        $filePath = WWW_ROOT . $entity->file_path;

        if (!file_exists($filePath)) {
            $entity->status = 'error_permanente';
            $entity->error_message = 'Archivo no encontrado para reintento';
            $table->save($entity);
            $failed++;
            continue;
        }

        $result = $this->n8nService->sendFile(
            'n8n_webhook_dian_crosscheck',
            $filePath,
            'file',
            ['crosscheck_id' => $entity->id, 'file_name' => $entity->file_name],
        );

        $entity->attempt_count = ($entity->attempt_count ?? 0) + 1;

        if ($result['success']) {
            $entity->status = 'procesando';
            $entity->n8n_response = $result['body'];
            $entity->error_message = null;
            $succeeded++;
        } else {
            $entity->error_message = $result['error'];
            if ($entity->attempt_count >= $maxAttempts) {
                $entity->status = 'error_permanente';
            }
            $failed++;
        }

        $table->save($entity);
    }

    return compact('retried', 'succeeded', 'failed');
}
```

**Step 3: Create migration for `attempt_count` column**

Run: `php bin/cake migrations create AddAttemptCountToDianCrosschecks`

Edit the generated migration file:

```php
use Migrations\BaseMigration;

class AddAttemptCountToDianCrosschecks extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('dian_crosschecks');
        $table->addColumn('attempt_count', 'integer', [
            'default' => 0,
            'null' => false,
            'after' => 'error_message',
        ]);
        $table->update();
    }
}
```

**Step 4: Run migration**

Run: `php bin/cake migrations migrate`

**Step 5: Commit**

```bash
git add src/Service/DianCrosscheckService.php config/Migrations/*AddAttemptCount*
git commit -m "fix: add retry mechanism for DIAN crosscheck webhook

Resolves AUDIT §2.2 + §7.1 — DianCrosscheckService was fire-and-forget.
Now tracks attempt_count, marks as 'error_envio' on first failure,
and provides retryFailed() for cron-based recovery."
```

---

## Phase 2: History Services — Fix N+1 Queries and Add Transactions

> AUDIT refs: §2.3 (Critical)
> Impact: 10 field changes = 10 individual INSERTs, no transaction wrapping.

---

### Task 4: Fix InvoiceHistoryService — Batch Save with Transaction

**Files:**
- Modify: `src/Service/InvoiceHistoryService.php`

**Step 1: Replace loop-and-save with batch collect and `saveMany`**

Replace the `recordChanges()` method (lines 38-88):

```php
public function recordChanges(Invoice $original, Invoice $modified, int $userId): void
{
    $fieldsToTrack = [
        'invoice_number', 'registration_date', 'issue_date', 'due_date',
        'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
        'detail', 'amount', 'expense_type_id', 'cost_center_id',
        'confirmed_by', 'approver_id', 'area_approval', 'area_approval_date',
        'dian_validation', 'accrued', 'accrual_date', 'ready_for_payment',
        'payment_status', 'payment_date', 'pipeline_status',
    ];

    $historiesTable = TableRegistry::getTableLocator()->get('InvoiceHistories');
    $entities = [];

    foreach ($fieldsToTrack as $field) {
        $oldVal = $original->get($field);
        $newVal = $modified->get($field);

        // Normalizar DateTime a string para comparacion
        if ($oldVal instanceof DateTimeInterface) {
            $oldVal = $oldVal->format('Y-m-d');
        }
        if ($newVal instanceof DateTimeInterface) {
            $newVal = $newVal->format('Y-m-d');
        }

        // Normalizar booleanos
        if (is_bool($oldVal) || is_bool($newVal)) {
            $oldVal = (bool)$oldVal;
            $newVal = (bool)$newVal;
        }

        // Normalizar null y string vacio
        if ($oldVal === '') {
            $oldVal = null;
        }
        if ($newVal === '') {
            $newVal = null;
        }

        if ($oldVal !== $newVal) {
            $entities[] = $historiesTable->newEntity([
                'invoice_id' => $original->id,
                'user_id' => $userId,
                'field_changed' => $field,
                'old_value' => $oldVal !== null ? (string)$oldVal : null,
                'new_value' => $newVal !== null ? (string)$newVal : null,
            ]);
        }
    }

    if (!empty($entities)) {
        $historiesTable->getConnection()->transactional(function () use ($historiesTable, $entities) {
            $historiesTable->saveMany($entities);
        });
    }
}
```

**Step 2: Run code style check**

Run: `composer cs-check`

**Step 3: Commit**

```bash
git add src/Service/InvoiceHistoryService.php
git commit -m "fix: batch save invoice history with transaction

Resolves AUDIT §2.3 — InvoiceHistoryService used individual INSERTs
in a loop. Now collects entities and uses saveMany() inside a
transaction for atomicity and performance."
```

---

### Task 5: Fix EmployeeHistoryService — Batch Save with Transaction

**Files:**
- Modify: `src/Service/EmployeeHistoryService.php`

**Step 1: Replace loop-and-save with batch collect and `saveMany`**

Replace the `recordChanges()` method (lines 51-94):

```php
public function recordChanges(Employee $original, Employee $modified, int $userId): void
{
    $fieldsToTrack = array_keys(self::FIELD_LABELS);

    $historiesTable = TableRegistry::getTableLocator()->get('EmployeeHistories');
    $entities = [];

    foreach ($fieldsToTrack as $field) {
        $oldVal = $original->get($field);
        $newVal = $modified->get($field);

        // Normalize DateTime to string for comparison
        if ($oldVal instanceof DateTimeInterface) {
            $oldVal = $oldVal->format('Y-m-d');
        }
        if ($newVal instanceof DateTimeInterface) {
            $newVal = $newVal->format('Y-m-d');
        }

        // Normalize booleans
        if (is_bool($oldVal) || is_bool($newVal)) {
            $oldVal = (bool)$oldVal;
            $newVal = (bool)$newVal;
        }

        // Normalize null and empty string
        if ($oldVal === '') {
            $oldVal = null;
        }
        if ($newVal === '') {
            $newVal = null;
        }

        if ($oldVal !== $newVal) {
            $entities[] = $historiesTable->newEntity([
                'employee_id' => $original->id,
                'user_id' => $userId,
                'field_changed' => $field,
                'old_value' => $oldVal !== null ? (string)$oldVal : null,
                'new_value' => $newVal !== null ? (string)$newVal : null,
            ]);
        }
    }

    if (!empty($entities)) {
        $historiesTable->getConnection()->transactional(function () use ($historiesTable, $entities) {
            $historiesTable->saveMany($entities);
        });
    }
}
```

**Step 2: Commit**

```bash
git add src/Service/EmployeeHistoryService.php
git commit -m "fix: batch save employee history with transaction

Same fix as InvoiceHistoryService — AUDIT §2.3."
```

---

### Task 6: Fix NoveltyHistoryService — Batch Save with Transaction

**Files:**
- Modify: `src/Service/NoveltyHistoryService.php`

**Step 1: Replace loop-and-save with batch collect and `saveMany`**

Replace `recordChanges()` method (lines 42-61):

```php
public function recordChanges(object $original, object $modified, int $userId): void
{
    $table = TableRegistry::getTableLocator()->get('NoveltyHistories');
    $entities = [];

    foreach (array_keys(self::FIELD_LABELS) as $field) {
        $oldVal = $this->normalize($original->$field ?? null);
        $newVal = $this->normalize($modified->$field ?? null);

        if ($oldVal !== $newVal) {
            $entities[] = $table->newEntity([
                'novelty_id' => $modified->id,
                'user_id' => $userId,
                'field_changed' => $field,
                'old_value' => $oldVal === '' ? null : $oldVal,
                'new_value' => $newVal === '' ? null : $newVal,
            ]);
        }
    }

    if (!empty($entities)) {
        $table->getConnection()->transactional(function () use ($table, $entities) {
            $table->saveMany($entities);
        });
    }
}
```

**Step 2: Commit**

```bash
git add src/Service/NoveltyHistoryService.php
git commit -m "fix: batch save novelty history with transaction

Same fix as InvoiceHistoryService — AUDIT §2.3."
```

---

## Phase 3: Layer Compliance — Extract Services from Controllers

> AUDIT refs: §2.1 (Critical), §3.6 (Warning), §7.3 (Priority 3)
> Impact: 100+ lines of query logic in `AppController.beforeFilter()` on every request.

---

### Task 7: Extract SidebarCounterService from AppController

**Files:**
- Create: `src/Service/SidebarCounterService.php`
- Modify: `src/Controller/AppController.php`

**Step 1: Create `SidebarCounterService`**

Create `src/Service/SidebarCounterService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\LegalizationConstants;
use App\Constants\NoveltyConstants;
use App\Constants\PettyCashConstants;
use Cake\ORM\TableRegistry;
use Exception;

class SidebarCounterService
{
    private InvoicePipelineService $invoicePipeline;
    private NoveltyPipelineService $noveltyPipeline;

    public function __construct(
        ?InvoicePipelineService $invoicePipeline = null,
        ?NoveltyPipelineService $noveltyPipeline = null,
    ) {
        $this->invoicePipeline = $invoicePipeline ?? new InvoicePipelineService();
        $this->noveltyPipeline = $noveltyPipeline ?? new NoveltyPipelineService();
    }

    /**
     * Get all sidebar counters for a given role.
     *
     * @param string $roleName Current user's role name.
     * @return array<string, mixed> All counter values keyed by name.
     */
    public function getCounters(string $roleName): array
    {
        try {
            return [
                'sidebarCounters' => $this->getInvoiceStatusCounters($roleName),
                'totalInvoicesCount' => $this->getCount('Invoices'),
                'rejectedInvoicesCount' => $this->getCount('Invoices', ['area_approval' => InvoiceConstants::APPROVAL_REJECTED]),
                'overdueInvoicesCount' => $this->getOverdueInvoicesCount(),
                'pettyCashCount' => $this->getCount('PettyCashRecords', ['status !=' => PettyCashConstants::STATUS_PAGADO]),
                'legalizationCount' => $this->getCount('LegalizationRecords', ['status !=' => LegalizationConstants::STATUS_PAGADO]),
                'noveltiesCount' => $this->getNoveltiesCount($roleName),
                'rejectedNoveltiesCount' => $this->getCount('EmployeeNovelties', ['pipeline_status' => NoveltyConstants::STATUS_RECHAZADA]),
                'activeNoveltiesCount' => $this->getActiveNoveltiesCount(),
                'liquidationCounters' => $this->getLiquidationCounters(),
            ];
        } catch (Exception $e) {
            return [
                'sidebarCounters' => [],
                'totalInvoicesCount' => 0,
                'rejectedInvoicesCount' => 0,
                'overdueInvoicesCount' => 0,
                'pettyCashCount' => 0,
                'legalizationCount' => 0,
                'noveltiesCount' => 0,
                'rejectedNoveltiesCount' => 0,
                'activeNoveltiesCount' => 0,
                'liquidationCounters' => [],
            ];
        }
    }

    private function getInvoiceStatusCounters(string $roleName): array
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $visibleStatuses = $this->invoicePipeline->getVisibleStatuses($roleName);

        $counters = [];
        foreach ($visibleStatuses as $status) {
            $counters[$status] = $invoicesTable->find()
                ->where(['pipeline_status' => $status])
                ->count();
        }

        return $counters;
    }

    private function getOverdueInvoicesCount(): int
    {
        return TableRegistry::getTableLocator()->get('Invoices')->find()
            ->where([
                'due_date <' => date('Y-m-d'),
                'pipeline_status !=' => InvoiceConstants::STATUS_PAGADA,
            ])
            ->count();
    }

    private function getNoveltiesCount(string $roleName): int
    {
        $noveltyVisibleStatuses = $this->noveltyPipeline->getVisibleStatuses($roleName);
        if (empty($noveltyVisibleStatuses)) {
            return 0;
        }

        return TableRegistry::getTableLocator()->get('EmployeeNovelties')->find()
            ->where([
                'pipeline_status IN' => $noveltyVisibleStatuses,
                'pipeline_status !=' => NoveltyConstants::STATUS_RECHAZADA,
            ])
            ->where(function ($exp) {
                return $exp->or([
                    'pipeline_status !=' => NoveltyConstants::STATUS_CONTABILIDAD,
                    'liquidation_doc_id IS' => null,
                ]);
            })
            ->count();
    }

    private function getActiveNoveltiesCount(): int
    {
        $today = date('Y-m-d');

        return TableRegistry::getTableLocator()->get('EmployeeNovelties')->find()
            ->where([
                'pipeline_status IN' => NoveltyConstants::ACTIVE_STATUSES,
            ])
            ->where(function ($exp) use ($today) {
                return $exp->or([
                    $exp->and([
                        'schedule_type' => NoveltyConstants::SCHEDULE_DAYS,
                        'start_date <=' => $today,
                        'end_date >=' => $today,
                    ]),
                    $exp->and([
                        'schedule_type' => NoveltyConstants::SCHEDULE_HOURS,
                        'permission_date' => $today,
                    ]),
                ]);
            })
            ->count();
    }

    private function getLiquidationCounters(): array
    {
        $liquidationTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');
        $counters = [];
        foreach ([NoveltyConstants::STATUS_CONTABILIDAD, NoveltyConstants::STATUS_TESORERIA, NoveltyConstants::STATUS_REVISION_FIRMAS, NoveltyConstants::STATUS_GDP] as $status) {
            $counters[$status] = $liquidationTable->find()
                ->where(['pipeline_status' => $status])
                ->count();
        }

        return $counters;
    }

    private function getCount(string $tableName, array $conditions = []): int
    {
        $query = TableRegistry::getTableLocator()->get($tableName)->find();
        if (!empty($conditions)) {
            $query->where($conditions);
        }

        return $query->count();
    }
}
```

**Step 2: Simplify `AppController._setSidebarCounters()`**

Replace the entire `_setSidebarCounters` method in `src/Controller/AppController.php`:

```php
protected function _setSidebarCounters(object $user): void
{
    $roleName = $this->_getUserRoleName($user);
    $counterService = new \App\Service\SidebarCounterService();
    $counters = $counterService->getCounters($roleName);

    foreach ($counters as $key => $value) {
        $this->set($key, $value);
    }
}
```

**Step 3: Add the import at the top of AppController (if desired — or use FQCN as above)**

No import needed if using FQCN in the method body.

**Step 4: Run code style check**

Run: `composer cs-check`

**Step 5: Commit**

```bash
git add src/Service/SidebarCounterService.php src/Controller/AppController.php
git commit -m "refactor: extract SidebarCounterService from AppController

Resolves AUDIT §2.1 — AppController._setSidebarCounters() had 100+
lines of query logic running on every authenticated request.
Extracted to SidebarCounterService with same behavior."
```

---

### Task 8: Extract DashboardStatisticsService from DashboardController

**Files:**
- Create: `src/Service/DashboardStatisticsService.php`
- Modify: `src/Controller/DashboardController.php`

**Step 1: Create `DashboardStatisticsService`**

Create `src/Service/DashboardStatisticsService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\ContractTypeConstants;
use App\Constants\EmployeeStatusConstants;
use App\Constants\InvoiceConstants;
use App\Constants\NoveltyConstants;
use Cake\ORM\TableRegistry;
use Exception;

class DashboardStatisticsService
{
    /**
     * Get invoice pipeline status counts.
     */
    public function getInvoiceStats(): array
    {
        return [
            'total' => $this->safeCount('Invoices'),
            'aprobacion' => $this->safeCount('Invoices', ['pipeline_status' => InvoiceConstants::STATUS_APROBACION]),
            'contabilidad' => $this->safeCount('Invoices', ['pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD]),
            'tesoreria' => $this->safeCount('Invoices', ['pipeline_status' => InvoiceConstants::STATUS_TESORERIA]),
            'pagada' => $this->safeCount('Invoices', ['pipeline_status' => InvoiceConstants::STATUS_PAGADA]),
            'rechazada' => $this->safeCount('Invoices', ['area_approval' => InvoiceConstants::APPROVAL_REJECTED]),
        ];
    }

    /**
     * Get recent invoices for dashboard widget.
     */
    public function getRecentInvoices(int $limit = 5): array
    {
        try {
            return TableRegistry::getTableLocator()->get('Invoices')
                ->find()
                ->select(['id', 'invoice_number', 'pipeline_status', 'area_approval', 'modified'])
                ->contain(['Providers' => ['fields' => ['id', 'name']]])
                ->orderByDesc('modified')
                ->limit($limit)
                ->toArray();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get invoice financial stats for a date range.
     */
    public function getInvoiceFinancialStats(string $from, string $to): array
    {
        try {
            $table = TableRegistry::getTableLocator()->get('Invoices');
            $dateConditions = ['Invoices.created >=' => $from, 'Invoices.created <=' => $to . ' 23:59:59'];

            $totalPaid = $table->find()
                ->where(array_merge(['pipeline_status' => InvoiceConstants::STATUS_PAGADA], $dateConditions))
                ->select(['total' => $table->find()->func()->sum('amount')])
                ->first();

            $totalInProcess = $table->find()
                ->where(array_merge([
                    'pipeline_status IN' => [InvoiceConstants::STATUS_APROBACION, InvoiceConstants::STATUS_CONTABILIDAD, InvoiceConstants::STATUS_TESORERIA],
                    'OR' => [
                        'area_approval IS' => null,
                        'area_approval !=' => InvoiceConstants::APPROVAL_REJECTED,
                    ],
                ], $dateConditions))
                ->select(['total' => $table->find()->func()->sum('amount')])
                ->first();

            $avgAmount = $table->find()
                ->where($dateConditions)
                ->select(['avg' => $table->find()->func()->avg('amount')])
                ->first();

            $overdue = $table->find()
                ->where([
                    'due_date <' => date('Y-m-d'),
                    'pipeline_status !=' => InvoiceConstants::STATUS_PAGADA,
                    'OR' => [
                        'area_approval IS' => null,
                        'area_approval !=' => InvoiceConstants::APPROVAL_REJECTED,
                    ],
                ])
                ->count();

            return [
                'total_paid' => (float)($totalPaid->total ?? 0),
                'total_in_process' => (float)($totalInProcess->total ?? 0),
                'avg_amount' => (float)($avgAmount->avg ?? 0),
                'overdue' => $overdue,
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get invoice chart data for a date range.
     */
    public function getInvoiceChartData(string $from, string $to): array
    {
        try {
            $table = TableRegistry::getTableLocator()->get('Invoices');
            $dateConditions = ['Invoices.created >=' => $from, 'Invoices.created <=' => $to . ' 23:59:59'];

            $statusAmounts = [];
            foreach (InvoiceConstants::PIPELINE_STATUSES as $status) {
                $result = $table->find()
                    ->where(array_merge(['pipeline_status' => $status], $dateConditions))
                    ->select(['total' => $table->find()->func()->sum('amount')])
                    ->first();
                $statusAmounts[$status] = (float)($result->total ?? 0);
            }

            $rejected = $table->find()
                ->where(array_merge(['area_approval' => InvoiceConstants::APPROVAL_REJECTED], $dateConditions))
                ->select(['total' => $table->find()->func()->sum('amount')])
                ->first();
            $statusAmounts['rechazada'] = (float)($rejected->total ?? 0);

            $monthlyData = $table->getConnection()->execute(
                "SELECT DATE_FORMAT(created, '%Y-%m') as month,
                        COUNT(*) as count,
                        COALESCE(SUM(amount), 0) as total
                 FROM invoices
                 WHERE created >= ? AND created <= ?
                 GROUP BY DATE_FORMAT(created, '%Y-%m')
                 ORDER BY month ASC",
                [$from, $to . ' 23:59:59'],
            )->fetchAll('assoc');

            return [
                'donut_status' => $statusAmounts,
                'monthly' => $monthlyData,
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get recent novelties for dashboard widget.
     */
    public function getRecentNovelties(int $limit = 5): array
    {
        try {
            return TableRegistry::getTableLocator()->get('EmployeeNovelties')
                ->find()
                ->select(['id', 'employee_id', 'novelty_type_id', 'created'])
                ->contain([
                    'Employees' => ['fields' => ['id', 'first_name', 'last_name1', 'last_name2']],
                    'NoveltyTypes' => ['fields' => ['id', 'name']],
                ])
                ->orderByDesc('created')
                ->limit($limit)
                ->toArray();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get RRHH basic stats.
     */
    public function getRrhhBasicStats(): array
    {
        $stats = [];
        $stats['active_employees'] = $this->safeCount(
            'Employees',
            ['employee_status_id' => EmployeeStatusConstants::ACTIVO],
        );

        $monthStart = date('Y-m-01 00:00:00');
        $stats['novelties_month'] = $this->safeCount('EmployeeNovelties', ['created >=' => $monthStart]);

        $today = date('Y-m-d');
        try {
            $stats['active_novelties'] = TableRegistry::getTableLocator()->get('EmployeeNovelties')
                ->find()
                ->where(['pipeline_status IN' => NoveltyConstants::ACTIVE_STATUSES])
                ->where(function ($exp) use ($today) {
                    return $exp->or([
                        $exp->and([
                            'schedule_type' => NoveltyConstants::SCHEDULE_DAYS,
                            'start_date <=' => $today,
                            'end_date >=' => $today,
                        ]),
                        $exp->and([
                            'schedule_type' => NoveltyConstants::SCHEDULE_HOURS,
                            'permission_date' => $today,
                        ]),
                    ]);
                })
                ->count();
        } catch (Exception $e) {
            $stats['active_novelties'] = 0;
        }

        return $stats;
    }

    /**
     * Get RRHH extended statistics for a date range.
     */
    public function getRrhhExtendedStats(string $from, string $to): array
    {
        try {
            $empTable = TableRegistry::getTableLocator()->get('Employees');

            $avgAge = $empTable->getConnection()->execute(
                "SELECT AVG(TIMESTAMPDIFF(YEAR, birth_date, CURDATE())) as avg_age
                 FROM employees
                 WHERE employee_status_id = ? AND birth_date IS NOT NULL",
                [EmployeeStatusConstants::ACTIVO],
            )->fetch('assoc');

            $avgTenure = $empTable->getConnection()->execute(
                "SELECT AVG(TIMESTAMPDIFF(YEAR, hire_date, CURDATE())) as avg_tenure
                 FROM employees
                 WHERE employee_status_id = ? AND hire_date IS NOT NULL",
                [EmployeeStatusConstants::ACTIVO],
            )->fetch('assoc');

            $newHires = $empTable->find()
                ->where([
                    'employee_status_id' => EmployeeStatusConstants::ACTIVO,
                    'hire_date >=' => $from,
                    'hire_date <=' => $to,
                ])
                ->count();

            $terminations = $empTable->find()
                ->where([
                    'termination_date >=' => $from,
                    'termination_date <=' => $to,
                ])
                ->count();

            return [
                'avg_age' => round((float)($avgAge['avg_age'] ?? 0), 1),
                'avg_tenure' => round((float)($avgTenure['avg_tenure'] ?? 0), 1),
                'new_hires' => $newHires,
                'terminations' => $terminations,
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get RRHH chart data for a date range.
     */
    public function getRrhhChartData(string $from, string $to): array
    {
        try {
            $empTable = TableRegistry::getTableLocator()->get('Employees');

            $contractTypes = [];
            foreach (ContractTypeConstants::ALL as $type) {
                $contractTypes[$type] = $empTable->find()
                    ->where([
                        'employee_status_id' => EmployeeStatusConstants::ACTIVO,
                        'contract_type' => $type,
                    ])
                    ->count();
            }

            $monthlyNovelties = TableRegistry::getTableLocator()->get('EmployeeNovelties')
                ->getConnection()->execute(
                    "SELECT DATE_FORMAT(created, '%Y-%m') as month,
                            COUNT(*) as count
                     FROM employee_novelties
                     WHERE created >= ? AND created <= ?
                     GROUP BY DATE_FORMAT(created, '%Y-%m')
                     ORDER BY month ASC",
                    [$from, $to . ' 23:59:59'],
                )->fetchAll('assoc');

            return [
                'donut_contract' => $contractTypes,
                'monthly_novelties' => $monthlyNovelties,
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get catalog counts.
     */
    public function getCatalogStats(): array
    {
        return [
            'providers' => $this->safeCount('Providers', ['active' => true]),
            'operation_centers' => $this->safeCount('OperationCenters'),
            'expense_types' => $this->safeCount('ExpenseTypes'),
            'cost_centers' => $this->safeCount('CostCenters'),
        ];
    }

    /**
     * Get admin stats.
     */
    public function getAdminStats(): array
    {
        return [
            'users' => $this->safeCount('Users', ['active' => true]),
            'roles' => $this->safeCount('Roles'),
        ];
    }

    private function safeCount(string $tableName, array $conditions = []): int
    {
        try {
            $query = TableRegistry::getTableLocator()->get($tableName)->find();
            if (!empty($conditions)) {
                $query->where($conditions);
            }

            return $query->count();
        } catch (Exception $e) {
            return 0;
        }
    }
}
```

**Step 2: Simplify `DashboardController::index()`**

Replace the entire `index()` method in `src/Controller/DashboardController.php`:

```php
public function index()
{
    $identity = $this->Authentication->getIdentity();
    if (!$identity) {
        return $this->redirect(['controller' => 'Users', 'action' => 'login']);
    }

    [$currentPeriod, $dateFrom, $dateTo] = $this->_getPeriodDates();

    $userPermissions = $this->viewBuilder()->getVar('userPermissions') ?? [];
    $canView = fn(string $module): bool => !empty($userPermissions[$module]['can_view']);

    $stats = new \App\Service\DashboardStatisticsService();

    // --- Facturacion ---
    $invoiceStats = [];
    $recentInvoices = [];
    $invoiceFinancialStats = [];
    $invoiceChartData = [];
    if ($canView('invoices')) {
        $invoiceStats = $stats->getInvoiceStats();
        $recentInvoices = $stats->getRecentInvoices();
        $invoiceFinancialStats = $stats->getInvoiceFinancialStats($dateFrom, $dateTo);
        $invoiceChartData = $stats->getInvoiceChartData($dateFrom, $dateTo);
    }

    // --- RRHH ---
    $rrhhStats = [];
    $recentNovelties = [];
    $rrhhExtendedStats = [];
    $rrhhChartData = [];
    if ($canView('employees') || $canView('employee_novelties')) {
        $rrhhStats = $stats->getRrhhBasicStats();
        $recentNovelties = $stats->getRecentNovelties();
        $rrhhExtendedStats = $stats->getRrhhExtendedStats($dateFrom, $dateTo);
        $rrhhChartData = $stats->getRrhhChartData($dateFrom, $dateTo);
    }

    // --- Catalogos ---
    $catalogStats = $canView('providers') || $canView('operation_centers') || $canView('expense_types') || $canView('cost_centers')
        ? $stats->getCatalogStats()
        : [];

    // --- Administracion ---
    $adminStats = $canView('users') || $canView('roles')
        ? $stats->getAdminStats()
        : [];

    $this->set(compact(
        'invoiceStats',
        'recentInvoices',
        'invoiceFinancialStats',
        'invoiceChartData',
        'rrhhStats',
        'recentNovelties',
        'rrhhExtendedStats',
        'rrhhChartData',
        'catalogStats',
        'adminStats',
        'currentPeriod',
        'dateFrom',
        'dateTo',
    ));
}
```

**Step 3: Remove extracted private methods from DashboardController**

Delete these methods from `DashboardController` (they're now in the service):
- `_safeCount()`
- `_safeQuery()`
- `_getRrhhExtendedStats()`
- `_getRrhhChartData()`
- `_getInvoiceFinancialStats()`
- `_getInvoiceChartData()`

Keep only `_getPeriodDates()` (parses request params — belongs in controller).

**Step 4: Run code style check**

Run: `composer cs-check`

**Step 5: Commit**

```bash
git add src/Service/DashboardStatisticsService.php src/Controller/DashboardController.php
git commit -m "refactor: extract DashboardStatisticsService from DashboardController

Resolves AUDIT §3.6 — DashboardController had inline queries and
complex calculations for 6+ modules (435 lines). Extracted to
DashboardStatisticsService, keeping only period parsing in controller."
```

---

## Phase 4: Eliminate Code Duplication

> AUDIT refs: §3.3 (Warning), §7.2 (Priority 2)
> Impact: 3 history services share identical normalization logic; 4 document services share validation/upload logic.

---

### Task 9: Extract Shared Document Upload Logic to Trait

**Files:**
- Create: `src/Service/Trait/DocumentUploadTrait.php`
- Modify: `src/Service/InvoiceDocumentService.php`
- Modify: `src/Service/LegalizationDocumentService.php`
- Modify: `src/Service/PettyCashDocumentService.php`

**Step 1: Create `DocumentUploadTrait`**

Create directory and file `src/Service/Trait/DocumentUploadTrait.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Trait;

use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;

trait DocumentUploadTrait
{
    private const MAX_DOC_SIZE = 10 * 1024 * 1024; // 10 MB

    private const ALLOWED_DOC_MIMES = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/gif',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * Validate, move, and persist an uploaded document.
     *
     * @param UploadedFile $file The uploaded file.
     * @param string $tableName ORM table name (e.g. 'InvoiceDocuments').
     * @param string $subDir Upload subdirectory (e.g. 'invoices/42').
     * @param string $prefix File name prefix (e.g. 'inv_').
     * @param array $entityFields Extra entity fields to merge.
     * @return object|string Entity on success, error message on failure.
     */
    protected function uploadAndSave(
        UploadedFile $file,
        string $tableName,
        string $subDir,
        string $prefix,
        array $entityFields,
    ): object|string {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return 'No se recibió ningún archivo válido.';
        }

        if ($file->getSize() > self::MAX_DOC_SIZE) {
            return 'El archivo excede el tamaño máximo de 10MB.';
        }

        $mimeType = $file->getClientMediaType();
        if (!in_array($mimeType, self::ALLOWED_DOC_MIMES)) {
            return 'Tipo de archivo no permitido. Use PDF, imágenes, Word o Excel.';
        }

        $uploadDir = WWW_ROOT . 'uploads' . DS . $subDir;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $originalName = $file->getClientFilename();
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $uniqueName = uniqid($prefix) . '.' . $extension;
        $filePath = $uploadDir . DS . $uniqueName;

        $file->moveTo($filePath);

        $documentsTable = TableRegistry::getTableLocator()->get($tableName);
        $document = $documentsTable->newEntity(array_merge($entityFields, [
            'file_path' => 'uploads/' . $subDir . '/' . $uniqueName,
            'file_name' => $originalName,
            'file_size' => $file->getSize(),
            'mime_type' => $mimeType,
        ]));

        if (!$documentsTable->save($document)) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            return 'No se pudo guardar el documento.';
        }

        return $document;
    }

    /**
     * Delete a document record and its physical file.
     */
    protected function deleteDocumentRecord(string $tableName, int $documentId): bool
    {
        $documentsTable = TableRegistry::getTableLocator()->get($tableName);
        $document = $documentsTable->get($documentId);

        $filePath = WWW_ROOT . $document->file_path;
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        return $documentsTable->delete($document);
    }
}
```

**Step 2: Refactor `InvoiceDocumentService` to use the trait**

Replace the file content of `src/Service/InvoiceDocumentService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Trait\DocumentUploadTrait;
use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;

class InvoiceDocumentService
{
    use DocumentUploadTrait;

    public function uploadDocument(
        int $invoiceId,
        string $pipelineStatus,
        UploadedFile $file,
        ?int $uploadedBy,
        ?string $documentType = null,
    ): object|string {
        return $this->uploadAndSave($file, 'InvoiceDocuments', 'invoices/' . $invoiceId, 'inv_', [
            'invoice_id' => $invoiceId,
            'pipeline_status' => $pipelineStatus,
            'document_type' => $documentType,
            'uploaded_by' => $uploadedBy,
        ]);
    }

    public function deleteDocument(int $documentId): bool
    {
        return $this->deleteDocumentRecord('InvoiceDocuments', $documentId);
    }

    public function canDeleteDocument(object $document, string $currentPipelineStatus): bool
    {
        return $document->pipeline_status === $currentPipelineStatus;
    }

    public function getDocumentsByStatus(int $invoiceId): array
    {
        $documentsTable = TableRegistry::getTableLocator()->get('InvoiceDocuments');
        $documents = $documentsTable->find()
            ->where(['invoice_id' => $invoiceId])
            ->contain(['UploadedByUsers'])
            ->order(['InvoiceDocuments.created' => 'DESC'])
            ->all();

        $grouped = [];
        foreach ($documents as $doc) {
            $grouped[$doc->pipeline_status][] = $doc;
        }

        return $grouped;
    }
}
```

**Step 3: Refactor `LegalizationDocumentService` to use the trait**

Replace `src/Service/LegalizationDocumentService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Trait\DocumentUploadTrait;
use Laminas\Diactoros\UploadedFile;

class LegalizationDocumentService
{
    use DocumentUploadTrait;

    public function uploadDocument(
        int $recordId,
        UploadedFile $file,
        ?int $uploadedBy,
        ?string $documentType = null,
    ): object|string {
        return $this->uploadAndSave($file, 'LegalizationDocuments', 'legalizations/' . $recordId, 'leg_', [
            'legalization_record_id' => $recordId,
            'document_type' => $documentType,
            'uploaded_by' => $uploadedBy,
        ]);
    }

    public function deleteDocument(int $documentId): bool
    {
        return $this->deleteDocumentRecord('LegalizationDocuments', $documentId);
    }
}
```

**Step 4: Refactor `PettyCashDocumentService` to use the trait**

Replace `src/Service/PettyCashDocumentService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Trait\DocumentUploadTrait;
use Laminas\Diactoros\UploadedFile;

class PettyCashDocumentService
{
    use DocumentUploadTrait;

    public function uploadDocument(
        int $recordId,
        UploadedFile $file,
        ?int $uploadedBy,
        ?string $documentType = null,
    ): object|string {
        return $this->uploadAndSave($file, 'PettyCashDocuments', 'petty_cash/' . $recordId, 'pc_', [
            'petty_cash_record_id' => $recordId,
            'document_type' => $documentType,
            'uploaded_by' => $uploadedBy,
        ]);
    }

    public function deleteDocument(int $documentId): bool
    {
        return $this->deleteDocumentRecord('PettyCashDocuments', $documentId);
    }
}
```

**Step 5: Run code style check**

Run: `composer cs-check`

**Step 6: Commit**

```bash
git add src/Service/Trait/DocumentUploadTrait.php src/Service/InvoiceDocumentService.php src/Service/LegalizationDocumentService.php src/Service/PettyCashDocumentService.php
git commit -m "refactor: extract DocumentUploadTrait to eliminate duplication

Resolves AUDIT §3.3 — InvoiceDocumentService, LegalizationDocumentService,
and PettyCashDocumentService shared identical validation, move, and
delete logic. Consolidated into DocumentUploadTrait."
```

---

### Task 10: Extract Shared History Normalization Logic

> NOTE: NoveltyDocumentService already has its own `upload()` private method that handles novelty-specific logic (group docs, liquidation docs). It should NOT use DocumentUploadTrait since its abstraction level is different.

**Files:**
- Create: `src/Service/Trait/HistoryNormalizationTrait.php`
- Modify: `src/Service/InvoiceHistoryService.php`
- Modify: `src/Service/EmployeeHistoryService.php`
- Modify: `src/Service/NoveltyHistoryService.php`

**Step 1: Create `HistoryNormalizationTrait`**

Create `src/Service/Trait/HistoryNormalizationTrait.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Trait;

use DateTimeInterface;

trait HistoryNormalizationTrait
{
    /**
     * Normalize a value for comparison and storage.
     *
     * Converts DateTime to 'Y-m-d', bools to bool, empty string to null.
     *
     * @return mixed Normalized value.
     */
    protected function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value;
        }

        if ($value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Normalize a value to its string representation for history storage.
     *
     * @return string
     */
    protected function normalizeToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string)$value;
    }
}
```

**Step 2: Update InvoiceHistoryService to use the trait**

In `src/Service/InvoiceHistoryService.php`, add the trait usage and simplify the normalization inside `recordChanges()`:

Add after the class declaration:
```php
use App\Service\Trait\HistoryNormalizationTrait;
```

Add inside the class:
```php
use HistoryNormalizationTrait;
```

Replace the normalization block inside the foreach of `recordChanges()`:

```php
foreach ($fieldsToTrack as $field) {
    $oldVal = $this->normalizeValue($original->get($field));
    $newVal = $this->normalizeValue($modified->get($field));

    // Normalizar booleanos para comparacion consistente
    if (is_bool($oldVal) || is_bool($newVal)) {
        $oldVal = (bool)$oldVal;
        $newVal = (bool)$newVal;
    }

    if ($oldVal !== $newVal) {
        $entities[] = $historiesTable->newEntity([
            'invoice_id' => $original->id,
            'user_id' => $userId,
            'field_changed' => $field,
            'old_value' => $oldVal !== null ? (string)$oldVal : null,
            'new_value' => $newVal !== null ? (string)$newVal : null,
        ]);
    }
}
```

**Step 3: Update EmployeeHistoryService similarly**

Same pattern — add `use HistoryNormalizationTrait;` and replace normalization block.

**Step 4: Update NoveltyHistoryService**

NoveltyHistoryService already has a `normalize()` method. Replace it with the trait's `normalizeToString()`:

Add the trait, then in `recordChanges()` replace `$this->normalize(...)` with `$this->normalizeToString(...)`. Remove the old private `normalize()` method.

**Step 5: Run code style check**

Run: `composer cs-check`

**Step 6: Commit**

```bash
git add src/Service/Trait/HistoryNormalizationTrait.php src/Service/InvoiceHistoryService.php src/Service/EmployeeHistoryService.php src/Service/NoveltyHistoryService.php
git commit -m "refactor: extract HistoryNormalizationTrait to eliminate duplication

Resolves AUDIT §3.3 — Three history services shared identical
DateTime/bool/null normalization. Consolidated into a trait."
```

---

## Phase 5: Transaction Safety

> AUDIT ref: §3.7 (Warning)

---

### Task 11: Wrap NoveltyPipelineService::advance() in Transaction

**Files:**
- Modify: `src/Service/NoveltyPipelineService.php:175-206`

**Step 1: Add transaction wrapping**

Replace the `advance()` method. The key change is wrapping the save in `transactional()`:

```php
public function advance(EmployeeNovelty $novelty, int $userId): array
{
    if ($novelty->isGrouped()) {
        return [
            'success' => false,
            'error' => 'Esta novedad pertenece a un documento de liquidación. Debe avanzar desde el documento grupal.',
        ];
    }

    if ($novelty->isRejected()) {
        return ['success' => false, 'error' => 'La novedad fue rechazada. El flujo ha terminado.'];
    }

    $errors = $this->validateTransition($novelty, $novelty->pipeline_status);
    if (!empty($errors)) {
        return ['success' => false, 'error' => implode(' ', $errors)];
    }

    $nextStatus = $this->getNextStatus($novelty);
    if (!$nextStatus) {
        return ['success' => false, 'error' => 'Esta novedad ya está en el estado final.'];
    }

    $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');

    $result = $noveltiesTable->getConnection()->transactional(function () use ($noveltiesTable, $novelty, $nextStatus) {
        $novelty->pipeline_status = $nextStatus;

        return $noveltiesTable->save($novelty);
    });

    if (!$result) {
        return ['success' => false, 'error' => 'No se pudo avanzar el estado.'];
    }

    return ['success' => true, 'error' => null, 'nextStatus' => $nextStatus];
}
```

**Step 2: Run code style check**

Run: `composer cs-check`

**Step 3: Commit**

```bash
git add src/Service/NoveltyPipelineService.php
git commit -m "fix: wrap NoveltyPipelineService::advance() in transaction

Resolves AUDIT §3.7 — advance() saved without transaction unlike
advanceGroup() which properly uses transactional()."
```

---

## Phase 6: Standardize Service Returns

> AUDIT refs: §7.4 (Priority 4)
> Impact: Mixed return types make error handling inconsistent across the codebase.

---

### Task 12: Create ServiceResult DTO

**Files:**
- Create: `src/Service/ServiceResult.php`

**Step 1: Create the `ServiceResult` class**

Create `src/Service/ServiceResult.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

class ServiceResult
{
    public function __construct(
        public readonly bool $success,
        public readonly mixed $data = null,
        public readonly array $errors = [],
    ) {
    }

    public static function ok(mixed $data = null): self
    {
        return new self(success: true, data: $data);
    }

    public static function fail(string|array $errors): self
    {
        $errors = is_string($errors) ? [$errors] : $errors;

        return new self(success: false, errors: $errors);
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function firstError(): ?string
    {
        return $this->errors[0] ?? null;
    }
}
```

**Step 2: Commit**

```bash
git add src/Service/ServiceResult.php
git commit -m "feat: add ServiceResult DTO for standardized service returns

Resolves AUDIT §7.4 — Provides ok()/fail() factory methods.
Existing services can adopt incrementally."
```

---

### Task 13: Fix DianCrosscheckService Mixed Return Type

**Files:**
- Modify: `src/Service/DianCrosscheckService.php`
- Modify: `src/Controller/DianCrosschecksController.php` (caller)

**Step 1: Change `processUpload()` to return `ServiceResult`**

In `src/Service/DianCrosscheckService.php`, add the import:

```php
use App\Service\ServiceResult;
```

Change the method signature and returns:

```php
/**
 * Process an uploaded DIAN crosscheck file.
 */
public function processUpload(UploadedFile $file, int $userId): ServiceResult
{
    $mime = $file->getClientMediaType();
    if (!in_array($mime, self::ALLOWED_MIMES, true)) {
        return ServiceResult::fail('El archivo debe ser un archivo Excel (.xls o .xlsx).');
    }

    if ($file->getSize() > self::MAX_SIZE) {
        return ServiceResult::fail('El archivo no debe superar los 10 MB.');
    }

    // ... (existing upload + save logic unchanged) ...

    if (!$table->save($entity)) {
        return ServiceResult::fail('Error al guardar el registro en la base de datos.');
    }

    // ... (existing n8n send logic unchanged) ...

    return ServiceResult::ok($entity);
}
```

**Step 2: Update the caller in DianCrosschecksController**

Find the call to `processUpload()` and update from:

```php
$result = $this->dianService->processUpload($file, $userId);
if (is_string($result)) {
    $this->Flash->error($result);
} else {
    $this->Flash->success('...');
}
```

To:

```php
$result = $this->dianService->processUpload($file, $userId);
if (!$result->success) {
    $this->Flash->error($result->firstError());
} else {
    $this->Flash->success('...');
}
```

**Step 3: Run code style check**

Run: `composer cs-check`

**Step 4: Commit**

```bash
git add src/Service/DianCrosscheckService.php src/Controller/DianCrosschecksController.php
git commit -m "refactor: DianCrosscheckService uses ServiceResult instead of mixed returns

Resolves AUDIT §7.4 — processUpload() returned string|Entity.
Now returns ServiceResult with typed success/failure."
```

---

## Summary of Changes by Audit Finding

| Audit Section | Severity | Task(s) | Score Impact |
|---------------|----------|---------|-------------|
| §2.1 AppController layer violation | Critical | Task 7 | Layer Compliance 7→9 |
| §2.2 No resilience in external APIs | Critical | Tasks 1, 2, 3 | Resilience 3→7 |
| §2.3 History N+1 + no transactions | Critical | Tasks 4, 5, 6 | Transaction Safety 7→9 |
| §3.3 Code duplication | Warning | Tasks 9, 10 | Code Duplication 5→7 |
| §3.6 Dashboard business logic | Warning | Task 8 | Layer Compliance 7→9 |
| §3.7 Missing transaction in advance() | Warning | Task 11 | Transaction Safety 7→9 |
| §7.4 Mixed return types | Priority 4 | Tasks 12, 13 | Error Handling 6→7 |

**Estimated new overall score: ~8.0/10** (up from 6.7/10)

### Not addressed in this plan (deferred per AUDIT §7.5):

| Item | When to Consider |
|------|-----------------|
| Interfaces for core services | When adding formal test suite |
| CakePHP Event system for notifications | When adding more notification channels |
| Value Objects (Money, DateRange) | When adding multi-currency or complex date logic |
| Data-driven pipeline configuration | When adding 5th+ pipeline state or 5th+ role |
| Split EmployeeNoveltiesController | When adding more novelty features |

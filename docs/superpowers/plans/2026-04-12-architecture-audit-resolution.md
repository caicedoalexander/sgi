# Architecture Audit Resolution Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolve all 10 critical issues and 43 warnings from the SGI Architecture Audit (2026-04-12), organized in 4 phases by priority.

**Architecture:** Incremental refactoring of a CakePHP 5.3 Service-Oriented monolith. Each task produces a working commit. No behavior changes to end users unless noted. All changes preserve the existing ServiceResult pattern, Constants layer, and pipeline state machine.

**Tech Stack:** PHP 8.2+, CakePHP 5.3, PHPUnit, MySQL/MariaDB

---

## Phase 1: Critical Fixes (Fix Now)

### Task 1: Wrap PaymentSchedulingService::applyPayments in a transaction (CRIT-09)

**Files:**
- Modify: `src/Service/PaymentSchedulingService.php:234-292`

This is the simplest critical fix. The `applyPayments()` method loops creating payments across multiple invoices with no transaction. A partial failure leaves the system inconsistent.

- [ ] **Step 1: Read current applyPayments method**

Confirm the method at `PaymentSchedulingService.php:234-292` has no `transactional()` wrapper.

- [ ] **Step 2: Wrap the payment loop in a transaction**

In `src/Service/PaymentSchedulingService.php`, replace the body of `applyPayments()` with a transaction:

```php
public function applyPayments(int $schedulingId, int $authorizedBy): array
{
    $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');
    $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
    $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
    $schedulingsTable = TableRegistry::getTableLocator()->get('PaymentSchedulings');

    $scheduling = $schedulingsTable->get($schedulingId);
    $items = $itemsTable->find()
        ->where(['payment_scheduling_id' => $schedulingId])
        ->all();

    $connection = $paymentsTable->getConnection();

    return $connection->transactional(function () use (
        $items, $paymentsTable, $invoicesTable, $scheduling, $schedulingId, $authorizedBy
    ) {
        $appliedInvoiceIds = [];
        $errors = [];

        foreach ($items as $item) {
            $payment = $paymentsTable->newEntity([
                'invoice_id' => $item->invoice_id,
                'banking_entity_id' => $item->banking_entity_id,
                'amount' => $item->amount,
                'payment_date' => date('Y-m-d'),
                'payment_scheduling_id' => $schedulingId,
                'authorized' => true,
                'authorized_by' => $authorizedBy,
                'authorized_date' => date('Y-m-d'),
                'created_by' => $scheduling->created_by,
            ]);

            if (!$paymentsTable->save($payment)) {
                $errors[] = "No se pudo crear pago para factura ID {$item->invoice_id}";
                continue;
            }

            $appliedInvoiceIds[] = $item->invoice_id;
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors, 'advanced_to_pagada' => [], 'partial_payment' => []];
        }

        $advanced = [];
        $partial = [];
        foreach (array_unique($appliedInvoiceIds) as $invoiceId) {
            $this->paymentService->recalculatePaymentStatus($invoiceId);

            $invoice = $invoicesTable->get($invoiceId);
            if ($invoice->payment_status === InvoiceConstants::PAYMENT_FULL) {
                $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
                $invoicesTable->save($invoice);
                $advanced[] = $invoiceId;
            } else {
                $partial[] = $invoiceId;
            }
        }

        return [
            'success' => true,
            'errors' => [],
            'advanced_to_pagada' => $advanced,
            'partial_payment' => $partial,
        ];
    });
}
```

Key difference: if any payment save fails, the entire transaction rolls back. No partial application.

- [ ] **Step 3: Run code style check**

Run: `composer cs-check`

- [ ] **Step 4: Commit**

```bash
git add src/Service/PaymentSchedulingService.php
git commit -m "fix: wrap PaymentSchedulingService::applyPayments in transaction (CRIT-09)"
```

---

### Task 2: Move inline service instantiation to constructors (CRIT-05)

**Files:**
- Modify: `src/Service/InvoicePipelineService.php` (lines 235, 380)
- Modify: `src/Service/ApprovalTokenService.php` (lines 124, 192)
- Modify: `src/Controller/InvoicesController.php` (line 714)

Three files create services with `new` inside business methods instead of injecting via constructor.

- [ ] **Step 1: Fix InvoicePipelineService**

Add `InvoicePaymentService` as a constructor dependency in `src/Service/InvoicePipelineService.php`:

```php
private InvoiceHistoryService $historyService;
private NotificationService $notificationService;
private InvoicePaymentService $paymentService;

public function __construct(
    ?InvoiceHistoryService $historyService = null,
    ?NotificationService $notificationService = null,
    ?InvoicePaymentService $paymentService = null,
) {
    $this->historyService = $historyService ?? new InvoiceHistoryService();
    $this->notificationService = $notificationService ?? new NotificationService();
    $this->paymentService = $paymentService ?? new InvoicePaymentService();
}
```

Then replace both `new InvoicePaymentService()` calls at lines ~235 and ~380 with `$this->paymentService`.

In `validateTransitionRequirements()` (~line 235):
```php
// Before:
$paymentService = new InvoicePaymentService();
// After: (delete the line, use $this->paymentService)
if ($rule['field'] === '_has_pending_payment') {
    if (!$this->paymentService->hasPendingAuthorization($invoice->id)) {
```

In `saveAndAdvance()` transaction (~line 380):
```php
// Before:
$paymentService = new InvoicePaymentService();
$paymentService->recalculatePaymentStatus($invoice->id);
// After:
$this->paymentService->recalculatePaymentStatus($invoice->id);
```

- [ ] **Step 2: Fix ApprovalTokenService**

In `src/Service/ApprovalTokenService.php`, add constructor injection for `NoveltyObservationService`:

```php
private InvoiceHistoryService $historyService;
private NotificationService $notificationService;
private NoveltyObservationService $observationService;

public function __construct(
    ?InvoiceHistoryService $historyService = null,
    ?NotificationService $notificationService = null,
    ?NoveltyObservationService $observationService = null,
) {
    $this->historyService = $historyService ?? new InvoiceHistoryService();
    $this->notificationService = $notificationService ?? new NotificationService();
    $this->observationService = $observationService ?? new NoveltyObservationService();
}
```

Replace in `applyInvoiceAction()` (~line 124):
```php
// Before:
$pipeline = new InvoicePipelineService($this->historyService, $this->notificationService);
// After:  (keep as is - this is passing existing deps forward, acceptable)
```

Replace in `applyNoveltyAction()` (~line 192):
```php
// Before:
$observationService = new NoveltyObservationService();
$observationService->addToNovelty(...)
// After:
$this->observationService->addToNovelty(...)
```

- [ ] **Step 3: Fix InvoicesController::authorizePayment**

In `src/Controller/InvoicesController.php`, the `authorizePayment()` method at line 714 creates `new InvoicePaymentService()`. But we'll address this more thoroughly in Task 4 (routing payment actions through services). For now, add InvoicePaymentService to the controller:

Add to `initialize()`:
```php
private InvoicePaymentService $paymentService;

// In initialize():
$this->paymentService = new InvoicePaymentService();
```

Replace at line 714:
```php
// Before:
$paymentService = new InvoicePaymentService();
$result = $paymentService->authorizePayment((int)$paymentId, (int)$user->id);
// After:
$result = $this->paymentService->authorizePayment((int)$paymentId, (int)$user->id);
```

- [ ] **Step 4: Run code style check**

Run: `composer cs-check`

- [ ] **Step 5: Commit**

```bash
git add src/Service/InvoicePipelineService.php src/Service/ApprovalTokenService.php src/Controller/InvoicesController.php
git commit -m "refactor: inject services via constructor instead of inline new (CRIT-05)"
```

---

### Task 3: Route payment actions through InvoicePaymentService (CRIT-01)

**Files:**
- Modify: `src/Service/InvoicePaymentService.php` (add methods)
- Modify: `src/Controller/InvoicesController.php` (simplify addPayment, authorizePayment, rejectPayment)

The controller's `addPayment()`, `authorizePayment()`, and `rejectPayment()` directly set `pipeline_status`, bypassing `InvoiceHistoryService`. We need to move the state transition logic into `InvoicePaymentService` so history is always recorded.

- [ ] **Step 1: Add InvoiceHistoryService dependency to InvoicePaymentService**

In `src/Service/InvoicePaymentService.php`:

```php
use App\Constants\InvoiceConstants;
use Cake\ORM\TableRegistry;

class InvoicePaymentService
{
    private InvoiceHistoryService $historyService;

    public function __construct(?InvoiceHistoryService $historyService = null)
    {
        $this->historyService = $historyService ?? new InvoiceHistoryService();
    }
```

- [ ] **Step 2: Add registerPayment() method to InvoicePaymentService**

This replaces the direct save + status change logic in `InvoicesController::addPayment()`:

```php
/**
 * Register a new payment and advance invoice to autorizacion_pago.
 * Records history for the pipeline status change.
 */
public function registerPayment(int $invoiceId, array $paymentData, int $createdBy): ServiceResult
{
    $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
    $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

    $invoice = $invoicesTable->get($invoiceId);
    $currentStatus = $invoice->pipeline_status;

    $payment = $paymentsTable->newEntity([
        'invoice_id' => $invoiceId,
        'banking_entity_id' => $paymentData['banking_entity_id'] ?? null,
        'amount' => $paymentData['amount'] ?? null,
        'payment_date' => $paymentData['payment_date'] ?? null,
        'created_by' => $createdBy,
    ]);

    if (!$paymentsTable->save($payment)) {
        $errors = [];
        foreach ($payment->getErrors() as $field => $fieldErrors) {
            foreach ($fieldErrors as $msg) {
                $errors[] = "$field: $msg";
            }
        }

        return ServiceResult::fail('No se pudo registrar el pago.' . (!empty($errors) ? ' ' . implode(', ', $errors) : ''));
    }

    $invoice->pipeline_status = InvoiceConstants::STATUS_AUTORIZACION_PAGO;
    $invoicesTable->save($invoice);

    $this->historyService->recordStatusChange(
        $invoiceId,
        $currentStatus,
        InvoiceConstants::STATUS_AUTORIZACION_PAGO,
        $createdBy,
    );

    return ServiceResult::ok('Pago registrado. La factura pasó a Autorización de Pago.');
}
```

- [ ] **Step 3: Extend authorizePayment() to handle pipeline transitions + history**

Replace the existing `authorizePayment()` in `src/Service/InvoicePaymentService.php`:

```php
/**
 * Authorize a payment, recalculate status, and handle pipeline transitions.
 * Records history for all status changes.
 */
public function authorizePayment(int $paymentId, int $authorizedBy): array
{
    $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
    $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
    $payment = $paymentsTable->get($paymentId);

    $payment->authorized = true;
    $payment->authorized_by = $authorizedBy;
    $payment->authorized_date = date('Y-m-d');

    if (!$paymentsTable->save($payment)) {
        return ['success' => false, 'paymentStatus' => null, 'newPipelineStatus' => null];
    }

    $this->recalculatePaymentStatus($payment->invoice_id);

    $invoice = $invoicesTable->get($payment->invoice_id);
    $previousStatus = $invoice->pipeline_status;
    $newPipelineStatus = null;

    if ($invoice->payment_status === InvoiceConstants::PAYMENT_FULL) {
        $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
        $newPipelineStatus = InvoiceConstants::STATUS_PAGADA;
    } else {
        $invoice->pipeline_status = InvoiceConstants::STATUS_TESORERIA;
        $newPipelineStatus = InvoiceConstants::STATUS_TESORERIA;
    }

    $invoicesTable->save($invoice);

    $this->historyService->recordStatusChange(
        $invoice->id,
        $previousStatus,
        $newPipelineStatus,
        $authorizedBy,
    );

    return [
        'success' => true,
        'paymentStatus' => $invoice->payment_status,
        'newPipelineStatus' => $newPipelineStatus,
    ];
}
```

- [ ] **Step 4: Add rejectPayment() method to InvoicePaymentService**

```php
/**
 * Reject (delete) a pending payment and return invoice to tesoreria.
 * Records history for the status change.
 */
public function rejectPayment(int $paymentId, int $rejectedBy): ServiceResult
{
    $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
    $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
    $payment = $paymentsTable->get($paymentId);

    if ($payment->authorized) {
        return ServiceResult::fail('No se puede rechazar un pago ya autorizado.');
    }

    $invoiceId = $payment->invoice_id;
    $invoice = $invoicesTable->get($invoiceId);
    $previousStatus = $invoice->pipeline_status;

    if (!$paymentsTable->delete($payment)) {
        return ServiceResult::fail('No se pudo rechazar el pago.');
    }

    $invoice->pipeline_status = InvoiceConstants::STATUS_TESORERIA;
    $invoicesTable->save($invoice);

    $this->historyService->recordStatusChange(
        $invoiceId,
        $previousStatus,
        InvoiceConstants::STATUS_TESORERIA,
        $rejectedBy,
    );

    return ServiceResult::ok('Pago rechazado. Factura devuelta a Tesorería.');
}
```

- [ ] **Step 5: Simplify InvoicesController payment actions**

In `src/Controller/InvoicesController.php`, simplify the three payment actions to delegate to `InvoicePaymentService`:

**addPayment():**
```php
public function addPayment($invoiceId = null)
{
    $this->request->allowMethod(['post']);
    $invoice = $this->Invoices->get($invoiceId);

    $roleName = $this->_getRoleName();
    $currentStatus = $invoice->pipeline_status;

    if (
        $roleName !== RoleConstants::ADMIN && (
        $roleName !== RoleConstants::TESORERIA ||
        $currentStatus !== InvoiceConstants::STATUS_TESORERIA
        )
    ) {
        $this->Flash->error('No tiene permisos para registrar pagos en este estado.');

        return $this->redirect(['action' => 'edit', $invoiceId]);
    }

    $result = $this->paymentService->registerPayment(
        (int)$invoiceId,
        $this->request->getData(),
        (int)$this->_getCurrentUser()->id,
    );

    if ($result->success) {
        $this->Flash->success($result->data);
    } else {
        $this->Flash->error($result->data);
    }

    return $this->redirect(['action' => 'edit', $invoiceId]);
}
```

**authorizePayment():**
```php
public function authorizePayment($invoiceId = null, $paymentId = null)
{
    $this->request->allowMethod(['post']);
    $roleName = $this->_getRoleName();

    if ($roleName !== RoleConstants::CONTADOR && $roleName !== RoleConstants::ADMIN) {
        $this->Flash->error('Solo el Contador puede autorizar pagos.');

        return $this->redirect(['action' => 'edit', $invoiceId]);
    }

    $result = $this->paymentService->authorizePayment((int)$paymentId, (int)$this->_getCurrentUser()->id);

    if ($result['success']) {
        if ($result['newPipelineStatus'] === InvoiceConstants::STATUS_PAGADA) {
            $this->Flash->success('Pago autorizado. Factura marcada como Pagada.');
        } else {
            $this->Flash->success('Pago autorizado. Factura devuelta a Tesorería (Pago Parcial).');
        }
    } else {
        $this->Flash->error('No se pudo autorizar el pago.');
    }

    return $this->redirect(['action' => 'edit', $invoiceId]);
}
```

**rejectPayment():**
```php
public function rejectPayment($invoiceId = null, $paymentId = null)
{
    $this->request->allowMethod(['post']);
    $roleName = $this->_getRoleName();

    if ($roleName !== RoleConstants::CONTADOR && $roleName !== RoleConstants::ADMIN) {
        $this->Flash->error('Solo el Contador puede rechazar pagos.');

        return $this->redirect(['action' => 'edit', $invoiceId]);
    }

    $result = $this->paymentService->rejectPayment((int)$paymentId, (int)$this->_getCurrentUser()->id);

    if ($result->success) {
        $this->Flash->success($result->data);
    } else {
        $this->Flash->error($result->data);
    }

    return $this->redirect(['action' => 'edit', $invoiceId]);
}
```

- [ ] **Step 6: Add ServiceResult import to InvoicePaymentService**

Add `use App\Service\ServiceResult;` to the imports in `InvoicePaymentService.php`.

- [ ] **Step 7: Run cs-check and tests**

Run: `composer check`

- [ ] **Step 8: Commit**

```bash
git add src/Service/InvoicePaymentService.php src/Controller/InvoicesController.php
git commit -m "fix: route payment state changes through InvoicePaymentService with history (CRIT-01)"
```

---

### Task 4: Record per-invoice history in grouped advances (CRIT-10)

**Files:**
- Modify: `src/Service/LegalizationService.php:137-176`
- Modify: `src/Service/PettyCashService.php:138-179`

Both services use `updateAll()` to advance invoice pipeline status, bypassing `InvoiceHistoryService`. We need to record a history entry per invoice after the bulk update.

- [ ] **Step 1: Add InvoiceHistoryService dependency to both services**

In both `src/Service/LegalizationService.php` and `src/Service/PettyCashService.php`, add:

```php
private InvoiceHistoryService $historyService;

public function __construct(?InvoiceHistoryService $historyService = null)
{
    $this->historyService = $historyService ?? new InvoiceHistoryService();
}
```

- [ ] **Step 2: Record history after updateAll in LegalizationService::advanceStatus**

Inside the `transactional()` callback in `LegalizationService::advanceStatus()`, after the `updateAll()` call and before the `return`, add history recording:

```php
if (!empty($updateData)) {
    $invoicesTable->updateAll(
        $updateData,
        ['legalization_record_id' => $record->id],
    );

    // Record per-invoice history for pipeline status change
    $newPipelineStatus = $updateData['pipeline_status'] ?? null;
    if ($newPipelineStatus) {
        $invoices = $invoicesTable->find()
            ->where(['legalization_record_id' => $record->id])
            ->all();
        foreach ($invoices as $inv) {
            $this->historyService->recordStatusChange(
                $inv->id,
                $currentStatus === LegalizationConstants::STATUS_AGRUPACION
                    ? InvoiceConstants::STATUS_APROBACION
                    : ($currentStatus === LegalizationConstants::STATUS_CONTABILIDAD
                        ? InvoiceConstants::STATUS_CONTABILIDAD
                        : InvoiceConstants::STATUS_TESORERIA),
                $newPipelineStatus,
                0, // system user
            );
        }
    }
}
```

Note: We need access to the `$currentStatus` variable inside the closure. Ensure it is passed via `use`.

Actually, the mapping from legalization status to invoice pipeline status needs to be computed from the *previous* state. Since we already know the previous legalization status maps to specific invoice statuses, we can use the invoices' state before the updateAll. Let me revise.

A cleaner approach: fetch the invoice IDs and their current pipeline_status *before* `updateAll`, then record history after:

```php
// Inside the transactional callback, BEFORE updateAll:
$invoicesBefore = $invoicesTable->find()
    ->select(['id', 'pipeline_status'])
    ->where(['legalization_record_id' => $record->id])
    ->all()
    ->toArray();

if (!empty($updateData)) {
    $invoicesTable->updateAll(
        $updateData,
        ['legalization_record_id' => $record->id],
    );

    // Record per-invoice audit trail
    $newPipelineStatus = $updateData['pipeline_status'] ?? null;
    if ($newPipelineStatus) {
        foreach ($invoicesBefore as $inv) {
            $this->historyService->recordStatusChange(
                $inv->id,
                $inv->pipeline_status,
                $newPipelineStatus,
                0,
            );
        }
    }
}
```

- [ ] **Step 3: Same change in PettyCashService::advanceStatus**

Apply the same pattern in `PettyCashService::advanceStatus()`, using `petty_cash_record_id` instead of `legalization_record_id`.

- [ ] **Step 4: Run cs-check**

Run: `composer cs-check`

- [ ] **Step 5: Commit**

```bash
git add src/Service/LegalizationService.php src/Service/PettyCashService.php
git commit -m "fix: record per-invoice history on grouped advances (CRIT-10)"
```

---

### Task 5: Add Circuit Breaker to external service calls (CRIT-03, CRIT-04)

**Files:**
- Create: `src/Service/CircuitBreaker.php`
- Modify: `src/Service/WebhookService.php`
- Modify: `src/Service/NotificationService.php`

The WebhookService blocking `usleep()` retries can block up to 97s. NotificationService has no retry at all. A circuit breaker prevents cascading failures.

- [ ] **Step 1: Create CircuitBreaker class**

Create `src/Service/CircuitBreaker.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Cache\Cache;
use Cake\Log\Log;

class CircuitBreaker
{
    private string $name;
    private int $failureThreshold;
    private int $recoveryTimeoutSeconds;

    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    public function __construct(
        string $name,
        int $failureThreshold = 5,
        int $recoveryTimeoutSeconds = 60,
    ) {
        $this->name = $name;
        $this->failureThreshold = $failureThreshold;
        $this->recoveryTimeoutSeconds = $recoveryTimeoutSeconds;
    }

    /**
     * Execute a callable through the circuit breaker.
     *
     * @param callable $action The action to execute.
     * @param callable|null $fallback Optional fallback when circuit is open.
     * @return mixed The result of $action or $fallback.
     * @throws \RuntimeException When circuit is open and no fallback provided.
     */
    public function call(callable $action, ?callable $fallback = null): mixed
    {
        $state = $this->_getState();

        if ($state === self::STATE_OPEN) {
            if ($this->_shouldAttemptReset()) {
                $this->_setState(self::STATE_HALF_OPEN);
            } else {
                Log::warning("CircuitBreaker [{$this->name}]: OPEN — skipping call");

                if ($fallback) {
                    return $fallback();
                }

                throw new \RuntimeException("Circuit breaker [{$this->name}] is open");
            }
        }

        try {
            $result = $action();
            $this->_onSuccess();

            return $result;
        } catch (\Throwable $e) {
            $this->_onFailure();
            Log::error("CircuitBreaker [{$this->name}]: failure — {$e->getMessage()}");

            if ($fallback) {
                return $fallback();
            }

            throw $e;
        }
    }

    public function isOpen(): bool
    {
        return $this->_getState() === self::STATE_OPEN;
    }

    private function _onSuccess(): void
    {
        $this->_setFailureCount(0);
        $this->_setState(self::STATE_CLOSED);
    }

    private function _onFailure(): void
    {
        $count = $this->_getFailureCount() + 1;
        $this->_setFailureCount($count);

        if ($count >= $this->failureThreshold) {
            $this->_setState(self::STATE_OPEN);
            $this->_setOpenedAt(time());
            Log::warning("CircuitBreaker [{$this->name}]: OPENED after {$count} failures");
        }
    }

    private function _shouldAttemptReset(): bool
    {
        $openedAt = $this->_getOpenedAt();

        return $openedAt && (time() - $openedAt) >= $this->recoveryTimeoutSeconds;
    }

    private function _cacheKey(string $suffix): string
    {
        return "circuit_breaker_{$this->name}_{$suffix}";
    }

    private function _getState(): string
    {
        return Cache::read($this->_cacheKey('state'), '_cake_core_') ?: self::STATE_CLOSED;
    }

    private function _setState(string $state): void
    {
        Cache::write($this->_cacheKey('state'), $state, '_cake_core_');
    }

    private function _getFailureCount(): int
    {
        return (int)(Cache::read($this->_cacheKey('failures'), '_cake_core_') ?: 0);
    }

    private function _setFailureCount(int $count): void
    {
        Cache::write($this->_cacheKey('failures'), $count, '_cake_core_');
    }

    private function _getOpenedAt(): ?int
    {
        $val = Cache::read($this->_cacheKey('opened_at'), '_cake_core_');

        return $val ? (int)$val : null;
    }

    private function _setOpenedAt(int $timestamp): void
    {
        Cache::write($this->_cacheKey('opened_at'), $timestamp, '_cake_core_');
    }
}
```

- [ ] **Step 2: Integrate circuit breaker into WebhookService**

In `src/Service/WebhookService.php`, add a circuit breaker that wraps the existing retry logic:

```php
private CircuitBreaker $circuitBreaker;

public function __construct(int $timeout = 30, int $maxRetries = self::MAX_RETRIES)
{
    $this->client = new Client(['timeout' => $timeout]);
    $this->maxRetries = $maxRetries;
    $this->circuitBreaker = new CircuitBreaker('webhook', failureThreshold: 3, recoveryTimeoutSeconds: 120);
}
```

In `executeWithRetry()`, wrap the entire retry loop:

```php
private function executeWithRetry(callable $request): array
{
    return $this->circuitBreaker->call(
        function () use ($request) {
            // ... existing retry loop unchanged ...
        },
        function () {
            return [
                'success' => false,
                'statusCode' => 0,
                'body' => '',
                'error' => 'Circuit breaker is open — external service unavailable',
            ];
        },
    );
}
```

- [ ] **Step 3: Integrate circuit breaker into NotificationService**

In `src/Service/NotificationService.php`, add a circuit breaker for SMTP:

```php
private CircuitBreaker $smtpCircuitBreaker;

public function __construct(?SystemSettingsService $settings = null)
{
    $this->settings = $settings ?? new SystemSettingsService();
    $this->smtpCircuitBreaker = new CircuitBreaker('smtp', failureThreshold: 3, recoveryTimeoutSeconds: 300);
}
```

Wrap each `$mailer->deliver()` call in `sendStatusChangeNotification()`:

```php
try {
    $this->smtpCircuitBreaker->call(function () use ($mailer) {
        $mailer->deliver();
    });
    $sent++;
} catch (\Throwable $e) {
    Log::error("Email notification failed for {$recipient->email}: " . $e->getMessage());
    $failed[] = $recipient->email . ': ' . $e->getMessage();
}
```

Apply the same pattern to `sendApprovalLinkNotification()` and `sendNoveltyApprovalEmail()`.

- [ ] **Step 4: Run cs-check**

Run: `composer cs-check`

- [ ] **Step 5: Commit**

```bash
git add src/Service/CircuitBreaker.php src/Service/WebhookService.php src/Service/NotificationService.php
git commit -m "feat: add circuit breaker to WebhookService and NotificationService (CRIT-03, CRIT-04)"
```

---

## Phase 2: High Priority (Next Sprint)

### Task 6: Extract InvoicePipelineService responsibilities (CRIT-07)

**Files:**
- Create: `src/Service/InvoiceFieldAccessPolicy.php`
- Modify: `src/Service/InvoicePipelineService.php`

The pipeline service has 489 lines with 8 responsibilities. Extract field/section visibility into a separate policy class, leaving the pipeline as an orchestrator.

- [ ] **Step 1: Create InvoiceFieldAccessPolicy**

Create `src/Service/InvoiceFieldAccessPolicy.php` containing the moved constants and methods:
- `ALL_FIELDS`
- `EDITABLE_FIELDS`
- `VISIBLE_SECTIONS_BY_ROLE`
- `COLLAPSIBLE_SECTIONS_BY_ROLE`
- `getEditableFields()`
- `getVisibleSections()`
- `getCollapsibleSections()`
- `filterEntityData()`

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;

class InvoiceFieldAccessPolicy
{
    // Move ALL_FIELDS, EDITABLE_FIELDS, VISIBLE_SECTIONS_BY_ROLE,
    // COLLAPSIBLE_SECTIONS_BY_ROLE constants here from InvoicePipelineService.

    // Move these methods here:
    // getEditableFields(), getVisibleSections(), getCollapsibleSections(),
    // filterEntityData()

    // Keep getStatusIndex() as a private helper here too.
}
```

- [ ] **Step 2: Delegate from InvoicePipelineService**

In `src/Service/InvoicePipelineService.php`:
- Add `InvoiceFieldAccessPolicy` as a constructor dependency
- Replace the removed methods with delegating calls:

```php
private InvoiceFieldAccessPolicy $fieldPolicy;

public function __construct(
    ?InvoiceHistoryService $historyService = null,
    ?NotificationService $notificationService = null,
    ?InvoicePaymentService $paymentService = null,
    ?InvoiceFieldAccessPolicy $fieldPolicy = null,
) {
    // ...
    $this->fieldPolicy = $fieldPolicy ?? new InvoiceFieldAccessPolicy();
}

public function getEditableFields(string $roleName, string $status): array
{
    return $this->fieldPolicy->getEditableFields($roleName, $status);
}

// Same for getVisibleSections, getCollapsibleSections, filterEntityData
```

This preserves the public API while moving responsibility. Callers don't need changes.

- [ ] **Step 3: Run cs-check and verify no broken calls**

Run: `composer check`

- [ ] **Step 4: Commit**

```bash
git add src/Service/InvoiceFieldAccessPolicy.php src/Service/InvoicePipelineService.php
git commit -m "refactor: extract InvoiceFieldAccessPolicy from InvoicePipelineService (CRIT-07)"
```

---

### Task 7: Decompose EmployeeNoveltiesController (CRIT-06)

**Files:**
- Create: `src/Controller/NoveltyDocumentsController.php`
- Modify: `src/Controller/EmployeeNoveltiesController.php`
- Modify: `config/routes.php` (if custom routes needed)

The controller has 939 lines and 20+ actions. Extract document-related and observation actions.

- [ ] **Step 1: Create NoveltyDocumentsController**

Move these actions from `EmployeeNoveltiesController`:
- `uploadDocument()`
- `deleteDocument()`

Into `src/Controller/NoveltyDocumentsController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\NoveltyDocumentService;

class NoveltyDocumentsController extends AppController
{
    private NoveltyDocumentService $documentService;

    public function initialize(): void
    {
        parent::initialize();
        $this->documentService = new NoveltyDocumentService();
    }

    public function upload(?string $noveltyId = null)
    {
        // Move uploadDocument() logic here, adjusting redirects to
        // ['controller' => 'EmployeeNovelties', 'action' => 'edit', $noveltyId]
    }

    public function delete(?string $noveltyId = null, ?string $documentId = null)
    {
        // Move deleteDocument() logic here
    }
}
```

- [ ] **Step 2: Update routes**

In `config/routes.php`, add routes BEFORE `$builder->fallbacks()`:

```php
$builder->connect(
    '/employee-novelties/{noveltyId}/documents/upload',
    ['controller' => 'NoveltyDocuments', 'action' => 'upload'],
)->setPass(['noveltyId']);

$builder->connect(
    '/employee-novelties/{noveltyId}/documents/{documentId}/delete',
    ['controller' => 'NoveltyDocuments', 'action' => 'delete'],
)->setPass(['noveltyId', 'documentId']);
```

- [ ] **Step 3: Update templates referencing old routes**

Search templates for form actions pointing to `uploadDocument` and `deleteDocument` on EmployeeNovelties and update them to the new controller/action.

- [ ] **Step 4: Remove the moved actions from EmployeeNoveltiesController**

Delete the `uploadDocument()` and `deleteDocument()` methods from `EmployeeNoveltiesController`.

- [ ] **Step 5: Add controller to permissions**

Add `NoveltyDocumentsController` to `$controllerModuleMap` in `AppController` and `AuthorizationService::MODULES`.

- [ ] **Step 6: Run cs-check**

Run: `composer cs-check`

- [ ] **Step 7: Commit**

```bash
git add src/Controller/NoveltyDocumentsController.php src/Controller/EmployeeNoveltiesController.php config/routes.php templates/
git commit -m "refactor: extract NoveltyDocumentsController from EmployeeNoveltiesController (CRIT-06)"
```

---

### Task 8: Extract payment actions from InvoicesController (CRIT-06 cont.)

**Files:**
- Create: `src/Controller/InvoicePaymentsController.php`
- Modify: `src/Controller/InvoicesController.php`
- Modify: `config/routes.php`
- Modify: templates referencing old routes

Similar to Task 7 but for invoice payment actions.

- [ ] **Step 1: Create InvoicePaymentsController**

Move from `InvoicesController`:
- `addPayment()`
- `authorizePayment()`
- `rejectPayment()`
- `deletePayment()`

Into `src/Controller/InvoicePaymentsController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\InvoicePaymentService;

class InvoicePaymentsController extends AppController
{
    private InvoicePaymentService $paymentService;

    public function initialize(): void
    {
        parent::initialize();
        $this->paymentService = new InvoicePaymentService();
    }

    // Move addPayment(), authorizePayment(), rejectPayment(), deletePayment()
    // Update redirects to ['controller' => 'Invoices', 'action' => 'edit', $invoiceId]
}
```

- [ ] **Step 2: Add routes**

```php
$builder->connect(
    '/invoices/{invoiceId}/payments/add',
    ['controller' => 'InvoicePayments', 'action' => 'addPayment'],
)->setPass(['invoiceId']);

$builder->connect(
    '/invoices/{invoiceId}/payments/{paymentId}/authorize',
    ['controller' => 'InvoicePayments', 'action' => 'authorizePayment'],
)->setPass(['invoiceId', 'paymentId']);

$builder->connect(
    '/invoices/{invoiceId}/payments/{paymentId}/reject',
    ['controller' => 'InvoicePayments', 'action' => 'rejectPayment'],
)->setPass(['invoiceId', 'paymentId']);

$builder->connect(
    '/invoices/{invoiceId}/payments/{paymentId}/delete',
    ['controller' => 'InvoicePayments', 'action' => 'deletePayment'],
)->setPass(['invoiceId', 'paymentId']);
```

- [ ] **Step 3: Update templates and remove from InvoicesController**

Update all form actions in `templates/Invoices/edit.php` that post to payment actions. Remove the methods from `InvoicesController`.

- [ ] **Step 4: Add controller to permissions mapping**

Map `InvoicePaymentsController` to the `invoices` module in `$controllerModuleMap`.

- [ ] **Step 5: Run cs-check**

Run: `composer cs-check`

- [ ] **Step 6: Commit**

```bash
git add src/Controller/InvoicePaymentsController.php src/Controller/InvoicesController.php config/routes.php templates/
git commit -m "refactor: extract InvoicePaymentsController from InvoicesController (CRIT-06)"
```

---

### Task 9: Add structured logging with correlation IDs (CRIT-08)

**Files:**
- Create: `src/Service/StructuredLogger.php`
- Create: `src/Middleware/CorrelationIdMiddleware.php`
- Modify: `src/Application.php` (add middleware)
- Modify: key services to use structured logger

- [ ] **Step 1: Create CorrelationIdMiddleware**

Create `src/Middleware/CorrelationIdMiddleware.php`:

```php
<?php
declare(strict_types=1);

namespace App\Middleware;

use Cake\Http\MiddlewareQueue;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CorrelationIdMiddleware implements MiddlewareInterface
{
    private static ?string $currentCorrelationId = null;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $correlationId = $request->getHeaderLine('X-Correlation-ID') ?: bin2hex(random_bytes(8));
        self::$currentCorrelationId = $correlationId;

        $request = $request->withAttribute('correlationId', $correlationId);
        $response = $handler->handle($request);

        return $response->withHeader('X-Correlation-ID', $correlationId);
    }

    public static function getId(): ?string
    {
        return self::$currentCorrelationId;
    }
}
```

- [ ] **Step 2: Create StructuredLogger**

Create `src/Service/StructuredLogger.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Middleware\CorrelationIdMiddleware;
use Cake\Log\Log;

class StructuredLogger
{
    private string $context;

    public function __construct(string $context)
    {
        $this->context = $context;
    }

    public function info(string $message, array $data = []): void
    {
        Log::info($this->_format($message, $data));
    }

    public function warning(string $message, array $data = []): void
    {
        Log::warning($this->_format($message, $data));
    }

    public function error(string $message, array $data = []): void
    {
        Log::error($this->_format($message, $data));
    }

    private function _format(string $message, array $data): string
    {
        $entry = [
            'context' => $this->context,
            'correlationId' => CorrelationIdMiddleware::getId(),
            'message' => $message,
        ];

        if (!empty($data)) {
            $entry['data'] = $data;
        }

        return json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
```

- [ ] **Step 3: Register middleware**

In `src/Application.php`, add to `middleware()`:

```php
use App\Middleware\CorrelationIdMiddleware;

// Add early in the middleware queue:
$middlewareQueue->add(new CorrelationIdMiddleware());
```

- [ ] **Step 4: Adopt in InvoicePipelineService and WebhookService**

Replace `Cake\Log\Log` calls with `StructuredLogger` in the most critical services. Start with `InvoicePipelineService` and `WebhookService`. Other services can be migrated incrementally.

- [ ] **Step 5: Run cs-check**

Run: `composer cs-check`

- [ ] **Step 6: Commit**

```bash
git add src/Service/StructuredLogger.php src/Middleware/CorrelationIdMiddleware.php src/Application.php src/Service/InvoicePipelineService.php src/Service/WebhookService.php
git commit -m "feat: add structured logging with correlation IDs (CRIT-08, W-06)"
```

---

### Task 10: Extract interfaces for key services (CRIT-02)

**Files:**
- Create: `src/Service/Interface/HistoryServiceInterface.php`
- Create: `src/Service/Interface/NotificationServiceInterface.php`
- Create: `src/Service/Interface/PipelineServiceInterface.php`
- Modify: `src/Service/InvoiceHistoryService.php` (implements interface)
- Modify: `src/Service/NoveltyHistoryService.php` (implements interface)
- Modify: `src/Service/NotificationService.php` (implements interface)
- Modify: `src/Service/InvoicePipelineService.php` (implements interface)

Start with the 3 most impactful interfaces that enable testability.

- [ ] **Step 1: Create HistoryServiceInterface**

```php
<?php
declare(strict_types=1);

namespace App\Service\Interface;

interface HistoryServiceInterface
{
    public function recordStatusChange(int $entityId, string $fromStatus, string $toStatus, int $userId): void;
}
```

- [ ] **Step 2: Create NotificationServiceInterface**

```php
<?php
declare(strict_types=1);

namespace App\Service\Interface;

use App\Model\Entity\Invoice;

interface NotificationServiceInterface
{
    public function sendStatusChangeNotification(Invoice $invoice, string $fromStatus, string $toStatus): array;
}
```

- [ ] **Step 3: Implement interfaces on existing services**

Add `implements HistoryServiceInterface` to `InvoiceHistoryService` and `NoveltyHistoryService`.
Add `implements NotificationServiceInterface` to `NotificationService`.

- [ ] **Step 4: Update type hints on dependents**

In `InvoicePipelineService`, change constructor type hints:

```php
public function __construct(
    ?HistoryServiceInterface $historyService = null,
    ?NotificationServiceInterface $notificationService = null,
    // ...
```

This allows injecting mock implementations for testing.

- [ ] **Step 5: Run cs-check**

Run: `composer cs-check`

- [ ] **Step 6: Commit**

```bash
git add src/Service/Interface/ src/Service/InvoiceHistoryService.php src/Service/NoveltyHistoryService.php src/Service/NotificationService.php src/Service/InvoicePipelineService.php
git commit -m "refactor: extract interfaces for history and notification services (CRIT-02)"
```

---

## Phase 3: Medium Priority (Backlog)

### Task 11: Eliminate LegalizationService / PettyCashService duplication (W-02)

**Files:**
- Create: `src/Service/GroupedInvoiceService.php`
- Modify: `src/Service/LegalizationService.php`
- Modify: `src/Service/PettyCashService.php`

~80% structural duplication between these two services. Extract shared logic into a base service or a shared helper.

- [ ] **Step 1: Create GroupedInvoiceService with shared logic**

Create `src/Service/GroupedInvoiceService.php` containing:
- `validateGrouping()` parametrized by document type, FK field name, and FK error label
- `addInvoices()` parametrized by FK field name
- `removeInvoice()` parametrized by FK field name, record table name
- `calculateAndSaveTotal()` parametrized by FK field name, record table name
- `getAvailableInvoices()` parametrized by document type, FK field name

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;

class GroupedInvoiceService
{
    private string $documentType;
    private string $fkField;
    private string $recordTableName;
    private string $fkLabel;
    private InvoiceHistoryService $historyService;

    public function __construct(
        string $documentType,
        string $fkField,
        string $recordTableName,
        string $fkLabel,
        ?InvoiceHistoryService $historyService = null,
    ) {
        $this->documentType = $documentType;
        $this->fkField = $fkField;
        $this->recordTableName = $recordTableName;
        $this->fkLabel = $fkLabel;
        $this->historyService = $historyService ?? new InvoiceHistoryService();
    }

    public function validateGrouping(array $invoiceIds): array
    {
        // Shared validation logic, using $this->documentType, $this->fkField, $this->fkLabel
    }

    public function addInvoices(object $record, array $invoiceIds): array
    {
        // Shared add logic using $this->fkField
    }

    // ... etc
}
```

- [ ] **Step 2: Simplify LegalizationService and PettyCashService**

Each service becomes a thin wrapper that creates a `GroupedInvoiceService` with the right config and delegates shared operations, keeping only the domain-specific transition validation logic.

- [ ] **Step 3: Run cs-check**

Run: `composer cs-check`

- [ ] **Step 4: Commit**

```bash
git add src/Service/GroupedInvoiceService.php src/Service/LegalizationService.php src/Service/PettyCashService.php
git commit -m "refactor: extract GroupedInvoiceService to eliminate duplication (W-02)"
```

---

### Task 12: Replace switch statements with Strategy (W-01)

**Files:**
- Create: `src/Service/Strategy/InvoiceApprovalStrategy.php`
- Create: `src/Service/Strategy/NoveltyApprovalStrategy.php`
- Create: `src/Service/Strategy/ApprovalStrategyInterface.php`
- Modify: `src/Service/ApprovalTokenService.php`

The `applyAction()` switch on entity type violates OCP.

- [ ] **Step 1: Create ApprovalStrategyInterface**

```php
<?php
declare(strict_types=1);

namespace App\Service\Strategy;

interface ApprovalStrategyInterface
{
    public function apply(int $entityId, string $action, ?string $observations, ?int $createdBy, ?string $approvalDate = null): bool;
    public function getEntity(int $entityId): ?object;
}
```

- [ ] **Step 2: Create InvoiceApprovalStrategy and NoveltyApprovalStrategy**

Move `applyInvoiceAction()` logic into `InvoiceApprovalStrategy::apply()`.
Move `applyNoveltyAction()` logic into `NoveltyApprovalStrategy::apply()`.
Move `getEntity()` logic per type into each strategy.

- [ ] **Step 3: Refactor ApprovalTokenService to use strategy map**

```php
private array $strategies;

public function __construct(/* ... */)
{
    $this->strategies = [
        'invoices' => new InvoiceApprovalStrategy($this->historyService, $this->notificationService),
        'employee_novelties' => new NoveltyApprovalStrategy($this->observationService),
    ];
}

private function applyAction(string $entityType, int $entityId, string $action, ?string $observations, ?int $createdBy, ?string $approvalDate): bool
{
    $strategy = $this->strategies[$entityType] ?? null;

    return $strategy ? $strategy->apply($entityId, $action, $observations, $createdBy, $approvalDate) : false;
}
```

- [ ] **Step 4: Run cs-check**

Run: `composer cs-check`

- [ ] **Step 5: Commit**

```bash
git add src/Service/Strategy/ src/Service/ApprovalTokenService.php
git commit -m "refactor: replace entity type switch with Strategy pattern (W-01)"
```

---

### Task 13: Add health check endpoint (W-05)

**Files:**
- Create: `src/Controller/HealthController.php`
- Modify: `config/routes.php`

- [ ] **Step 1: Create HealthController**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Datasource\ConnectionManager;

class HealthController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        // Skip authentication and authorization for health check
        $this->Authentication->allowUnauthenticated(['index']);
    }

    public function index()
    {
        $checks = [];

        // Database
        try {
            $connection = ConnectionManager::get('default');
            $connection->execute('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'fail';
        }

        $allOk = !in_array('fail', $checks);

        $this->response = $this->response
            ->withType('application/json')
            ->withStatus($allOk ? 200 : 503)
            ->withStringBody(json_encode([
                'status' => $allOk ? 'healthy' : 'unhealthy',
                'checks' => $checks,
            ]));

        return $this->response;
    }
}
```

- [ ] **Step 2: Add route**

In `config/routes.php`:
```php
$builder->connect('/health', ['controller' => 'Health', 'action' => 'index']);
```

- [ ] **Step 3: Exclude from RBAC**

In `AppController::beforeFilter()`, skip permission enforcement for the `Health` controller.

- [ ] **Step 4: Run cs-check**

Run: `composer cs-check`

- [ ] **Step 5: Commit**

```bash
git add src/Controller/HealthController.php config/routes.php src/Controller/AppController.php
git commit -m "feat: add /health endpoint (W-05)"
```

---

### Task 14: Fix TOCTOU race condition on token consumption (W-08)

**Files:**
- Modify: `src/Service/InvoiceApprovalService.php:141-196`

The `processResponse()` validates a token then consumes it in separate queries, allowing double-consumption under concurrent requests.

- [ ] **Step 1: Use SELECT ... FOR UPDATE in processResponse**

Wrap the token validation and consumption in a transaction with a locking read:

```php
public function processResponse(
    string $token,
    string $action,
    ?string $observations,
    ?string $ipAddress,
    ?string $userAgent,
): array {
    $connection = $this->invoiceApprovalsTable->getConnection();

    return $connection->transactional(function () use ($token, $action, $observations, $ipAddress, $userAgent) {
        // Lock the row to prevent concurrent consumption
        $approval = $this->invoiceApprovalsTable->find()
            ->where([
                'token' => $token,
                'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
                'token_expires_at >' => new DateTime(),
            ])
            ->contain(['Invoices' => ['Providers', 'InvoiceDocuments'], 'Users'])
            ->epilog('FOR UPDATE')
            ->first();

        if (!$approval) {
            return ['success' => false, 'allApproved' => false, 'rejected' => false, 'errors' => ['Token invalido o expirado']];
        }

        // ... rest of consumption logic unchanged ...
    });
}
```

- [ ] **Step 2: Run cs-check**

Run: `composer cs-check`

- [ ] **Step 3: Commit**

```bash
git add src/Service/InvoiceApprovalService.php
git commit -m "fix: prevent TOCTOU race on approval token consumption with FOR UPDATE (W-08)"
```

---

### Task 15: Add rate limiting to public approval endpoint (W-07)

**Files:**
- Create: `src/Middleware/RateLimitMiddleware.php`
- Modify: `src/Application.php` or `config/routes.php`

- [ ] **Step 1: Create RateLimitMiddleware**

```php
<?php
declare(strict_types=1);

namespace App\Middleware;

use Cake\Cache\Cache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RateLimitMiddleware implements MiddlewareInterface
{
    private int $maxRequests;
    private int $windowSeconds;

    public function __construct(int $maxRequests = 10, int $windowSeconds = 60)
    {
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $path = $request->getUri()->getPath();
        $key = 'rate_limit_' . md5($ip . $path);

        $current = (int)(Cache::read($key, '_cake_core_') ?: 0);

        if ($current >= $this->maxRequests) {
            $response = new \Cake\Http\Response();

            return $response
                ->withStatus(429)
                ->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Too many requests']));
        }

        Cache::write($key, $current + 1, '_cake_core_');

        return $handler->handle($request);
    }
}
```

- [ ] **Step 2: Apply to external approval routes**

In `config/routes.php`, wrap the `/approve/*` route scope with the middleware, or apply it in `ExternalApprovalsController::initialize()`:

```php
public function initialize(): void
{
    parent::initialize();
    // Rate limit: handled via middleware in routes
}
```

Or register in route scope in `config/routes.php`:

```php
$builder->scope('/approve', function ($builder) {
    $builder->registerMiddleware('rateLimit', new RateLimitMiddleware(10, 60));
    $builder->applyMiddleware('rateLimit');
    // existing routes...
});
```

- [ ] **Step 3: Run cs-check**

Run: `composer cs-check`

- [ ] **Step 4: Commit**

```bash
git add src/Middleware/RateLimitMiddleware.php config/routes.php
git commit -m "feat: add rate limiting to public approval endpoint (W-07)"
```

---

## Phase 4: Low Priority (Nice to Have)

### Task 16: Enrich entity domain methods

**Files:**
- Modify: `src/Model/Entity/Invoice.php`

Add behavior to the Invoice entity instead of keeping it as a pure data bag.

- [ ] **Step 1: Add domain methods to Invoice entity**

```php
public function canAdvanceTo(string $nextStatus): bool
{
    if ($this->isRejected()) {
        return false;
    }

    $transitions = InvoiceConstants::PIPELINE_STATUSES;
    $currentIndex = array_search($this->pipeline_status, $transitions);
    $nextIndex = array_search($nextStatus, $transitions);

    return $nextIndex === $currentIndex + 1;
}

public function isOverdue(): bool
{
    if ($this->pipeline_status === InvoiceConstants::STATUS_PAGADA) {
        return false;
    }

    return $this->due_date && $this->due_date->isPast();
}

public function isPaid(): bool
{
    return $this->pipeline_status === InvoiceConstants::STATUS_PAGADA;
}
```

- [ ] **Step 2: Run cs-check**

Run: `composer cs-check`

- [ ] **Step 3: Commit**

```bash
git add src/Model/Entity/Invoice.php
git commit -m "refactor: enrich Invoice entity with domain methods"
```

---

### Task 17: Consolidate approval token systems (W-03)

**Files:**
- Modify: `src/Service/ApprovalTokenService.php`
- Modify: `src/Service/InvoiceApprovalService.php`

The two services overlap in token generation and management. `InvoiceApprovalService` handles multi-approver with its own token system, while `ApprovalTokenService` handles single external approval. They should share token infrastructure.

- [ ] **Step 1: Analyze overlap**

Read both services carefully. The key difference:
- `ApprovalTokenService` uses the `approval_tokens` table (generic, multi-entity)
- `InvoiceApprovalService` uses the `invoice_approvals` table (invoice-specific, multi-approver)

They serve different purposes. The consolidation should:
- Keep `InvoiceApprovalService` for multi-approver workflows
- Keep `ApprovalTokenService` for generic single-entity approval
- Document the distinction clearly
- Ensure both use the circuit breaker for email sending

- [ ] **Step 2: Add clarifying comments and remove any actual duplication**

The main overlap is in token generation (`bin2hex(random_bytes(32))`). Extract a shared helper if desired, or document the intentional separation.

- [ ] **Step 3: Commit**

```bash
git commit -m "docs: clarify separation between ApprovalTokenService and InvoiceApprovalService (W-03)"
```

---

### Task 18: Split DashboardStatisticsService (W-04)

**Files:**
- Create: `src/Service/Dashboard/InvoiceStatisticsService.php`
- Create: `src/Service/Dashboard/EmployeeStatisticsService.php`
- Create: `src/Service/Dashboard/PettyCashStatisticsService.php`
- Modify: `src/Service/DashboardStatisticsService.php` (becomes facade)
- Modify: `src/Controller/DashboardController.php`

- [ ] **Step 1: Extract domain-specific statistics into separate services**

Read `DashboardStatisticsService.php` and group its methods by domain. Create one service per domain.

- [ ] **Step 2: Convert DashboardStatisticsService into a facade**

Keep the existing class as a facade that delegates to the domain-specific services. This preserves the controller API.

- [ ] **Step 3: Run cs-check**

Run: `composer cs-check`

- [ ] **Step 4: Commit**

```bash
git add src/Service/Dashboard/ src/Service/DashboardStatisticsService.php
git commit -m "refactor: split DashboardStatisticsService by domain (W-04)"
```

---

### Task 19: Add adapter interfaces for Mailer and PhpSpreadsheet (W-11, W-12)

**Files:**
- Create: `src/Service/Interface/MailerInterface.php`
- Create: `src/Service/Interface/SpreadsheetReaderInterface.php`
- Create: `src/Service/Adapter/CakeMailerAdapter.php`
- Create: `src/Service/Adapter/PhpSpreadsheetAdapter.php`
- Modify: `src/Service/NotificationService.php`
- Modify: `src/Service/ExcelImportService.php`

These adapters decouple the application from framework/library specifics and enable testing with mocks.

- [ ] **Step 1: Create MailerInterface**

```php
<?php
declare(strict_types=1);

namespace App\Service\Interface;

interface MailerInterface
{
    public function send(string $to, string $subject, string $template, array $viewVars, string $layout = 'default'): void;
}
```

- [ ] **Step 2: Create CakeMailerAdapter implementing MailerInterface**

Move the Mailer configuration and delivery logic from `NotificationService` into this adapter.

- [ ] **Step 3: Create SpreadsheetReaderInterface and PhpSpreadsheetAdapter**

```php
interface SpreadsheetReaderInterface
{
    public function load(string $filePath): array; // Returns rows
}
```

- [ ] **Step 4: Update NotificationService and ExcelImportService to use adapters**

Inject via constructor with nullable defaults.

- [ ] **Step 5: Run cs-check**

Run: `composer cs-check`

- [ ] **Step 6: Commit**

```bash
git add src/Service/Interface/ src/Service/Adapter/ src/Service/NotificationService.php src/Service/ExcelImportService.php
git commit -m "refactor: add adapter interfaces for Mailer and PhpSpreadsheet (W-11, W-12)"
```

---

## Summary

| Phase | Tasks | Issues Resolved |
|-------|-------|----------------|
| **1: Critical** | Tasks 1-5 | CRIT-01, CRIT-03, CRIT-04, CRIT-05, CRIT-09, CRIT-10 |
| **2: High** | Tasks 6-10 | CRIT-02, CRIT-06, CRIT-07, CRIT-08, W-06 |
| **3: Medium** | Tasks 11-15 | W-01, W-02, W-05, W-07, W-08 |
| **4: Low** | Tasks 16-19 | W-03, W-04, W-11, W-12, entity enrichment |

**Remaining warnings not covered** (informational, low risk):
- W-09: Table references Service constants — cosmetic, no behavioral impact
- W-10: History service recording duplication — partially addressed by HistoryServiceInterface (Task 10)

**Execution order within phases is sequential** — each task may depend on changes from the prior task in the same phase.

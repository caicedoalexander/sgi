# Multi-Approver Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Allow N approvers per invoice with individual tracking, simultaneous notifications, and unanimous approval required to advance.

**Architecture:** New `invoice_approvals` table stores per-approver state (token, status, response). New `InvoiceApprovalService` orchestrates assignment, token generation, notifications, and response processing. Existing pipeline validation switches from checking `approver_id` to checking all `invoice_approvals` records. External approval controller adapted to resolve tokens from the new table.

**Tech Stack:** CakePHP 5.3, PHP 8.2+, MySQL/MariaDB, Select2 (multi-select), existing email templates.

**Design Doc:** `docs/plans/2026-03-31-multi-approver-design.md`

---

## Task 1: Create Migration — `invoice_approvals` Table

**Files:**
- Create: `config/Migrations/20260331000001_CreateInvoiceApprovals.php`

**Step 1: Generate migration skeleton**

```bash
php bin/cake migrations create CreateInvoiceApprovals
```

**Step 2: Implement migration**

Use `BaseMigration` (NOT `AbstractMigration`). Create table with guard `$this->hasTable()`:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateInvoiceApprovals extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('invoice_approvals')) {
            $table = $this->table('invoice_approvals');
            $table
                ->addColumn('invoice_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('token', 'string', ['limit' => 64, 'null' => true, 'default' => null])
                ->addColumn('token_expires_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'Pendiente', 'null' => false])
                ->addColumn('responded_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('observations', 'text', ['null' => true, 'default' => null])
                ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true, 'default' => null])
                ->addColumn('user_agent', 'text', ['null' => true, 'default' => null])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['token'], ['unique' => true])
                ->addIndex(['invoice_id'])
                ->addIndex(['user_id'])
                ->addForeignKey('invoice_id', 'invoices', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('invoice_approvals')) {
            $this->table('invoice_approvals')->drop()->save();
        }
    }
}
```

> **Nota:** Verificar si `invoices.id` y `users.id` son signed o unsigned revisando migraciones existentes (`20260219000007_CreateInvoices.php`). Usar el mismo tipo en las FKs.

**Step 3: Run migration**

```bash
php bin/cake migrations migrate
```

Expected: table `invoice_approvals` created successfully.

**Step 4: Commit**

```bash
git add config/Migrations/*CreateInvoiceApprovals*
git commit -m "feat: create invoice_approvals migration for multi-approver support"
```

---

## Task 2: Entity & Table — InvoiceApproval Model

**Files:**
- Create: `src/Model/Entity/InvoiceApproval.php`
- Create: `src/Model/Table/InvoiceApprovalsTable.php`
- Modify: `src/Model/Table/InvoicesTable.php` (line ~54, add hasMany)
- Modify: `src/Model/Entity/Invoice.php` (add to _accessible if needed)

**Step 1: Create Entity**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class InvoiceApproval extends Entity
{
    protected array $_accessible = [
        'invoice_id' => true,
        'user_id' => true,
        'token' => true,
        'token_expires_at' => true,
        'status' => true,
        'responded_at' => true,
        'observations' => true,
        'ip_address' => true,
        'user_agent' => true,
    ];
}
```

**Step 2: Create Table**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class InvoiceApprovalsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('invoice_approvals');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Invoices', [
            'foreignKey' => 'invoice_id',
            'joinType' => 'INNER',
        ]);

        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('invoice_id')
            ->requirePresence('invoice_id', 'create')
            ->notEmptyString('invoice_id');

        $validator
            ->integer('user_id')
            ->requirePresence('user_id', 'create')
            ->notEmptyString('user_id');

        $validator
            ->scalar('status')
            ->maxLength('status', 20)
            ->notEmptyString('status');

        $validator
            ->scalar('token')
            ->maxLength('token', 64)
            ->allowEmptyString('token');

        return $validator;
    }
}
```

**Step 3: Add hasMany to InvoicesTable**

In `src/Model/Table/InvoicesTable.php`, after the existing `belongsTo('ApproverUsers')` block (around line 54), add:

```php
$this->hasMany('InvoiceApprovals', [
    'foreignKey' => 'invoice_id',
    'dependent' => true,
    'cascadeCallbacks' => true,
]);
```

**Step 4: Commit**

```bash
git add src/Model/Entity/InvoiceApproval.php src/Model/Table/InvoiceApprovalsTable.php src/Model/Table/InvoicesTable.php
git commit -m "feat: add InvoiceApproval entity, table, and hasMany relation"
```

---

## Task 3: Add Constants for Approval Statuses

**Files:**
- Modify: `src/Constants/InvoiceConstants.php` (around line 24)

**Step 1: Add individual approval status constants**

After the existing `APPROVAL_STATUSES` array (line 24), add:

```php
// Individual approver statuses (for invoice_approvals table)
public const APPROVER_STATUS_PENDING = 'Pendiente';
public const APPROVER_STATUS_APPROVED = 'Aprobada';
public const APPROVER_STATUS_REJECTED = 'Rechazada';

public const APPROVER_STATUSES = [
    self::APPROVER_STATUS_PENDING,
    self::APPROVER_STATUS_APPROVED,
    self::APPROVER_STATUS_REJECTED,
];
```

**Step 2: Commit**

```bash
git add src/Constants/InvoiceConstants.php
git commit -m "feat: add individual approver status constants"
```

---

## Task 4: Create InvoiceApprovalService

**Files:**
- Create: `src/Service/InvoiceApprovalService.php`

This is the core service. It handles: assigning approvers, generating tokens, processing responses, checking completion.

**Step 1: Create the service**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

class InvoiceApprovalService
{
    private $invoiceApprovalsTable;
    private $notificationService;

    public function __construct(
        ?NotificationService $notificationService = null
    ) {
        $this->invoiceApprovalsTable = TableRegistry::getTableLocator()->get('InvoiceApprovals');
        $this->notificationService = $notificationService ?? new NotificationService();
    }

    /**
     * Assign approvers to an invoice, generate tokens, and send notifications.
     *
     * @param Invoice $invoice The invoice entity
     * @param array $approverUserIds Array of user IDs to assign as approvers
     * @param string $baseUrl Base URL for approval links
     * @param int $createdByUserId The user who assigned the approvers
     * @return array ['success' => bool, 'errors' => array, 'approvals' => array]
     */
    public function assignApprovers(Invoice $invoice, array $approverUserIds, string $baseUrl, int $createdByUserId): array
    {
        $errors = [];
        $approvals = [];

        if (empty($approverUserIds)) {
            return ['success' => false, 'errors' => ['Debe seleccionar al menos un aprobador'], 'approvals' => []];
        }

        $expiresAt = new DateTime('+48 hours');

        foreach ($approverUserIds as $userId) {
            $token = bin2hex(random_bytes(32));

            $approval = $this->invoiceApprovalsTable->newEntity([
                'invoice_id' => $invoice->id,
                'user_id' => (int)$userId,
                'token' => $token,
                'token_expires_at' => $expiresAt,
                'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
            ]);

            if (!$this->invoiceApprovalsTable->save($approval)) {
                $errors[] = "Error al asignar aprobador ID {$userId}";
                continue;
            }

            $approvals[] = $approval;

            // Send notification email
            $approvalUrl = $baseUrl . '/approve/' . $token;
            try {
                $this->notificationService->sendApprovalLinkNotification($invoice, $approvalUrl, (int)$userId);
            } catch (\Exception $e) {
                // Log but don't block — approval record was created
            }
        }

        $success = empty($errors);
        return compact('success', 'errors', 'approvals');
    }

    /**
     * Get active (pending) approvals for an invoice.
     */
    public function getActiveApprovals(int $invoiceId): array
    {
        return $this->invoiceApprovalsTable->find()
            ->where([
                'invoice_id' => $invoiceId,
                'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
                'token_expires_at >' => new DateTime(),
            ])
            ->contain(['Users'])
            ->all()
            ->toArray();
    }

    /**
     * Get all approvals for the current approval round (most recent batch).
     */
    public function getCurrentApprovals(int $invoiceId): array
    {
        // Get the latest batch by finding the max created timestamp group
        $latestCreated = $this->invoiceApprovalsTable->find()
            ->where(['invoice_id' => $invoiceId])
            ->orderBy(['created' => 'DESC'])
            ->first();

        if (!$latestCreated) {
            return [];
        }

        // All approvals created within 1 minute of the latest = same batch
        $batchStart = (new DateTime($latestCreated->created->format('Y-m-d H:i:s')))->modify('-1 minute');

        return $this->invoiceApprovalsTable->find()
            ->where([
                'invoice_id' => $invoiceId,
                'created >=' => $batchStart,
            ])
            ->contain(['Users'])
            ->orderBy(['created' => 'ASC'])
            ->all()
            ->toArray();
    }

    /**
     * Validate and consume a token. Returns the approval record or null.
     */
    public function validateToken(string $token): ?\App\Model\Entity\InvoiceApproval
    {
        return $this->invoiceApprovalsTable->find()
            ->where([
                'token' => $token,
                'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
                'token_expires_at >' => new DateTime(),
            ])
            ->contain(['Invoices', 'Users'])
            ->first();
    }

    /**
     * Process an approver's response (approve or reject).
     *
     * @return array ['success' => bool, 'allApproved' => bool, 'rejected' => bool, 'errors' => array]
     */
    public function processResponse(
        string $token,
        string $action,
        ?string $observations,
        ?string $ipAddress,
        ?string $userAgent
    ): array {
        $approval = $this->validateToken($token);
        if (!$approval) {
            return ['success' => false, 'allApproved' => false, 'rejected' => false, 'errors' => ['Token inválido o expirado']];
        }

        $newStatus = ($action === 'approve')
            ? InvoiceConstants::APPROVER_STATUS_APPROVED
            : InvoiceConstants::APPROVER_STATUS_REJECTED;

        $approval->status = $newStatus;
        $approval->responded_at = new DateTime();
        $approval->observations = $observations;
        $approval->ip_address = $ipAddress;
        $approval->user_agent = $userAgent;
        $approval->token = null; // Invalidate token after use

        if (!$this->invoiceApprovalsTable->save($approval)) {
            return ['success' => false, 'allApproved' => false, 'rejected' => false, 'errors' => ['Error al guardar respuesta']];
        }

        $invoiceId = $approval->invoice_id;

        if ($action === 'reject') {
            // Invalidate all other pending tokens for this invoice
            $this->_invalidatePendingTokens($invoiceId, $approval->id);

            // Update invoice area_approval
            $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
            $invoice = $invoicesTable->get($invoiceId);
            $invoice->area_approval = InvoiceConstants::APPROVAL_REJECTED;
            $invoice->area_approval_date = new DateTime();
            $invoicesTable->save($invoice);

            return ['success' => true, 'allApproved' => false, 'rejected' => true, 'errors' => [], 'invoice_id' => $invoiceId];
        }

        // Check if all approvers in this batch have approved
        $allApproved = $this->areAllApproved($invoiceId);

        if ($allApproved) {
            $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
            $invoice = $invoicesTable->get($invoiceId);
            $invoice->area_approval = InvoiceConstants::APPROVAL_APPROVED;
            $invoice->area_approval_date = new DateTime();
            $invoicesTable->save($invoice);
        }

        return ['success' => true, 'allApproved' => $allApproved, 'rejected' => false, 'errors' => [], 'invoice_id' => $invoiceId];
    }

    /**
     * Check if all approvals in the current batch are approved.
     */
    public function areAllApproved(int $invoiceId): bool
    {
        $currentApprovals = $this->getCurrentApprovals($invoiceId);

        if (empty($currentApprovals)) {
            return false;
        }

        foreach ($currentApprovals as $approval) {
            if ($approval->status !== InvoiceConstants::APPROVER_STATUS_APPROVED) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if invoice has pending approvals (active tokens).
     */
    public function hasPendingApprovals(int $invoiceId): bool
    {
        return $this->invoiceApprovalsTable->find()
            ->where([
                'invoice_id' => $invoiceId,
                'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
                'token_expires_at >' => new DateTime(),
            ])
            ->count() > 0;
    }

    /**
     * Get approval summary for display (e.g., "2/3 aprobados").
     */
    public function getApprovalSummary(int $invoiceId): array
    {
        $approvals = $this->getCurrentApprovals($invoiceId);
        $total = count($approvals);
        $approved = 0;
        $rejected = 0;
        $pending = 0;

        foreach ($approvals as $a) {
            match ($a->status) {
                InvoiceConstants::APPROVER_STATUS_APPROVED => $approved++,
                InvoiceConstants::APPROVER_STATUS_REJECTED => $rejected++,
                default => $pending++,
            };
        }

        return compact('total', 'approved', 'rejected', 'pending');
    }

    /**
     * Invalidate all pending tokens for an invoice (except excludeId).
     */
    private function _invalidatePendingTokens(int $invoiceId, ?int $excludeId = null): void
    {
        $conditions = [
            'invoice_id' => $invoiceId,
            'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
        ];

        if ($excludeId) {
            $conditions['id !='] = $excludeId;
        }

        $this->invoiceApprovalsTable->updateAll(
            ['token' => null, 'token_expires_at' => null],
            $conditions
        );
    }
}
```

**Step 2: Commit**

```bash
git add src/Service/InvoiceApprovalService.php
git commit -m "feat: add InvoiceApprovalService for multi-approver logic"
```

---

## Task 5: Modify NotificationService — Support Sending by User ID

**Files:**
- Modify: `src/Service/NotificationService.php` (lines 86-134, 208-216)

Currently `sendApprovalLinkNotification()` reads `approver_id` from the invoice entity. We need to accept a user ID parameter instead.

**Step 1: Update method signature**

Change `sendApprovalLinkNotification` (line ~86) to accept an optional `$approverUserId` parameter:

```php
public function sendApprovalLinkNotification(Invoice $invoice, string $approvalUrl, ?int $approverUserId = null): void
```

**Step 2: Update approver lookup inside the method**

Where the method currently does `$this->getApproverRecipient($invoice->approver_id)` (around line 98), change to:

```php
$approverId = $approverUserId ?? $invoice->approver_id;
if (!$approverId) {
    return;
}
$recipients = $this->getApproverRecipient($approverId);
```

This is backward-compatible — existing callers without the parameter still work.

**Step 3: Commit**

```bash
git add src/Service/NotificationService.php
git commit -m "feat: allow NotificationService to send approval email by user ID"
```

---

## Task 6: Modify InvoicePipelineService — Multi-Approver Validation

**Files:**
- Modify: `src/Service/InvoicePipelineService.php` (lines 95-112, 287-398, 452-487)

**Step 1: Update TRANSITION_REQUIREMENTS for 'aprobacion'**

Replace the `approver_id` requirement (lines ~97-101) with a custom check. Change the `aprobacion` requirements array:

**Remove** this entry from the `aprobacion` requirements:
```php
[
    'field' => 'approver_id',
    'not_empty' => true,
    'label' => 'Debe seleccionar un Aprobador',
],
```

**Replace** the `area_approval` entry with:
```php
[
    'field' => 'area_approval',
    'value' => 'Aprobada',
    'label' => 'Todos los aprobadores deben haber aprobado',
],
```

The `area_approval` field is now set to 'Aprobada' by `InvoiceApprovalService` when all approve, so this check still works.

**Step 2: Add InvoiceApprovalService dependency**

Add to constructor (around line 15-25):

```php
private InvoiceApprovalService $approvalService;
```

In constructor:
```php
$this->approvalService = $approvalService ?? new InvoiceApprovalService();
```

Update constructor signature to accept it:
```php
public function __construct(
    // ... existing params ...
    ?InvoiceApprovalService $approvalService = null
)
```

**Step 3: Remove approval link sending from saveAndAdvance()**

In `saveAndAdvance()` (around lines 370-395), there's logic that sends an approval link when `approver_id` is first assigned. **Remove** this block — approval links are now sent by `InvoiceApprovalService::assignApprovers()` from the controller.

Look for the section that checks if approver_id was just set and calls `trySendApprovalLink()`. Remove or comment out that conditional block.

**Step 4: Remove `trySendApprovalLink()` method**

Remove the `trySendApprovalLink()` method (around lines 452-487) — no longer needed. The controller will use `InvoiceApprovalService` directly.

**Step 5: Commit**

```bash
git add src/Service/InvoicePipelineService.php
git commit -m "feat: update pipeline validation for multi-approver, remove single-approver link logic"
```

---

## Task 7: Modify ExternalApprovalsController — Use InvoiceApprovalService

**Files:**
- Modify: `src/Controller/ExternalApprovalsController.php` (lines 34-129)

**Step 1: Add service property and initialize**

Add at top of class:
```php
private \App\Service\InvoiceApprovalService $approvalService;

public function initialize(): void
{
    parent::initialize();
    // ... existing code ...
    $this->approvalService = new \App\Service\InvoiceApprovalService();
}
```

**Step 2: Update `review()` action**

Replace the token validation logic that uses `ApprovalTokenService` with:

```php
$approval = $this->approvalService->validateToken($token);
if (!$approval) {
    // Show expired/invalid page
    $this->set('error', 'El enlace de aprobación es inválido o ha expirado.');
    return $this->render('expired');
}

$invoice = $approval->invoice;
$this->set(compact('approval', 'invoice', 'token'));
```

Keep the existing identity check (if the approver must match the logged-in user) but adapt it to use `$approval->user_id`.

**Step 3: Update `process()` action**

Replace token consumption logic with:

```php
$action = $this->request->getData('action'); // 'approve' or 'reject'
$observations = $this->request->getData('observations');
$ipAddress = $this->request->getClientIp();
$userAgent = $this->request->getHeaderLine('User-Agent');

$result = $this->approvalService->processResponse($token, $action, $observations, $ipAddress, $userAgent);

if (!$result['success']) {
    $this->Flash->error($result['errors'][0] ?? 'Error al procesar respuesta');
    return $this->redirect(['action' => 'review', $token]);
}

if ($result['allApproved']) {
    // Auto-advance invoice through pipeline
    $pipelineService = new \App\Service\InvoicePipelineService();
    $invoicesTable = \Cake\ORM\TableRegistry::getTableLocator()->get('Invoices');
    $invoice = $invoicesTable->get($result['invoice_id']);

    if ($invoice->pipeline_status === 'aprobacion') {
        $pipelineService->advance($invoice, 'Admin');
    }
}

// Show success page
$this->set('action', $action);
$this->set('result', $result);
```

**Step 4: Commit**

```bash
git add src/Controller/ExternalApprovalsController.php
git commit -m "feat: adapt ExternalApprovalsController for multi-approver tokens"
```

---

## Task 8: Modify InvoicesController — Multi-Select & Assignment

**Files:**
- Modify: `src/Controller/InvoicesController.php` (lines 186-292, 324-346, 482-509)

**Step 1: Add InvoiceApprovalService to controller**

Add property and initialize in relevant methods or constructor.

**Step 2: Update `edit()` to handle multi-approver assignment**

After the existing `saveAndAdvance()` call succeeds, add logic to check if new approver IDs were submitted:

```php
$submittedApproverIds = $this->request->getData('approver_ids') ?? [];
// Compare with existing assigned approvers to detect new assignments
// If new approvers submitted AND invoice is in 'aprobacion' status:
if (!empty($submittedApproverIds) && $invoice->pipeline_status === 'aprobacion') {
    $approvalService = new \App\Service\InvoiceApprovalService();
    $baseUrl = $this->request->scheme() . '://' . $this->request->host();
    $approvalService->assignApprovers($invoice, $submittedApproverIds, $baseUrl, $loggedInUserId);
}
```

**Step 3: Pass approval data to template**

In the `edit()` method, before rendering, add:

```php
$approvalService = new \App\Service\InvoiceApprovalService();
$currentApprovals = $approvalService->getCurrentApprovals($invoice->id);
$hasPendingApprovals = $approvalService->hasPendingApprovals($invoice->id);
$this->set(compact('currentApprovals', 'hasPendingApprovals'));
```

**Step 4: Update `_getFormDropdowns()`**

The approvers dropdown (lines 484-497) stays the same — it provides the list for the multi-select. No change needed here.

**Step 5: Remove `generateApprovalLink()` action**

Remove the `generateApprovalLink()` method (lines 324-346) — no longer needed. Links are sent automatically on assignment.

Also remove its route in `config/routes.php` (line ~116-120).

**Step 6: Commit**

```bash
git add src/Controller/InvoicesController.php config/routes.php
git commit -m "feat: handle multi-approver assignment in InvoicesController"
```

---

## Task 9: Update Edit Template — Multi-Select + Approval Table

**Files:**
- Modify: `templates/Invoices/edit.php` (lines 499-535)

**Step 1: Replace single approver select with multi-select**

Replace the current approver_id field (lines 500-508) with:

```php
<div class="col-md-6">
    <label class="form-label">Aprobadores</label>
    <?php if (!$hasPendingApprovals && $canEdit('approver_id')): ?>
        <select name="approver_ids[]" id="approver-ids" class="form-select select2" multiple="multiple">
            <?php foreach ($approvers as $id => $name): ?>
                <option value="<?= $id ?>"><?= h($name) ?></option>
            <?php endforeach; ?>
        </select>
    <?php else: ?>
        <p class="form-control-plaintext text-muted">
            <?= $hasPendingApprovals ? 'Aprobaciones en curso — no se puede modificar' : 'No editable en este estado' ?>
        </p>
    <?php endif; ?>
</div>
```

**Step 2: Add approval status table below the select**

After the multi-select div, add:

```php
<?php if (!empty($currentApprovals)): ?>
<div class="col-12 mt-3">
    <label class="form-label">Estado de Aprobaciones</label>
    <table class="table table-sm table-bordered">
        <thead>
            <tr>
                <th>Aprobador</th>
                <th>Estado</th>
                <th>Fecha</th>
                <th>Observaciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($currentApprovals as $approval): ?>
            <tr>
                <td><?= h($approval->user->first_name ?? $approval->user->username) ?> <?= h($approval->user->last_name ?? '') ?></td>
                <td>
                    <?php
                    $badgeClass = match ($approval->status) {
                        'Aprobada' => 'bg-success',
                        'Rechazada' => 'bg-danger',
                        default => 'bg-secondary',
                    };
                    ?>
                    <span class="badge <?= $badgeClass ?>"><?= h($approval->status) ?></span>
                </td>
                <td><?= $approval->responded_at ? $approval->responded_at->format('Y-m-d H:i') : '—' ?></td>
                <td><?= $approval->observations ? h($approval->observations) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
```

**Step 3: Add rejection alert**

Before the approval table, if invoice is rejected show:

```php
<?php if (($invoice->area_approval ?? '') === 'Rechazada'): ?>
    <?php
    $rejector = null;
    foreach ($currentApprovals as $a) {
        if ($a->status === 'Rechazada') { $rejector = $a; break; }
    }
    ?>
    <div class="col-12">
        <div class="alert alert-warning mb-2">
            <i class="bi bi-exclamation-triangle"></i>
            Rechazada por <strong><?= h($rejector->user->first_name ?? $rejector->user->username ?? 'Aprobador') ?></strong>.
            <?= $rejector && $rejector->observations ? h($rejector->observations) : '' ?>
            Corrija y re-asigne aprobadores.
        </div>
    </div>
<?php endif; ?>
```

**Step 4: Commit**

```bash
git add templates/Invoices/edit.php
git commit -m "feat: multi-approver select and approval status table in edit template"
```

---

## Task 10: Update Index Template — Approval Summary Badge

**Files:**
- Modify: `templates/Invoices/index.php` (lines 224-241)

**Step 1: Pass approval summaries from controller**

In `InvoicesController::index()`, after fetching invoices, build a summary map:

```php
$approvalService = new \App\Service\InvoiceApprovalService();
$approvalSummaries = [];
foreach ($invoices as $inv) {
    if ($inv->pipeline_status === 'aprobacion') {
        $approvalSummaries[$inv->id] = $approvalService->getApprovalSummary($inv->id);
    }
}
$this->set('approvalSummaries', $approvalSummaries);
```

**Step 2: Update index template**

Where the "Aprobada" badge is shown (around lines 230-233), replace/extend with:

```php
<?php if (isset($approvalSummaries[$invoice->id]) && $approvalSummaries[$invoice->id]['total'] > 0):
    $s = $approvalSummaries[$invoice->id];
?>
    <?php if ($s['rejected'] > 0): ?>
        <span class="badge bg-danger">Rechazada</span>
    <?php elseif ($s['approved'] === $s['total']): ?>
        <span class="badge bg-success">Aprobada</span>
    <?php else: ?>
        <span class="badge bg-secondary"><?= $s['approved'] ?>/<?= $s['total'] ?> aprobados</span>
    <?php endif; ?>
<?php elseif ($invoice->area_approval === 'Aprobada'): ?>
    <span class="badge bg-success">Aprobada</span>
<?php endif; ?>
```

**Step 3: Commit**

```bash
git add templates/Invoices/index.php src/Controller/InvoicesController.php
git commit -m "feat: show multi-approver summary badge in invoice index"
```

---

## Task 11: Update EDITABLE_FIELDS — Remove approver_id, Add approver_ids

**Files:**
- Modify: `src/Service/InvoicePipelineService.php` (lines 66-73)

**Step 1: Remove `approver_id` from editable fields for REGISTRO_REVISION**

In `EDITABLE_FIELDS['aprobacion'][RoleConstants::REGISTRO_REVISION]` (around line 68), remove `'approver_id'` from the array. The multi-select `approver_ids[]` is handled separately by the controller, not by the pipeline field-filtering logic.

**Step 2: Commit**

```bash
git add src/Service/InvoicePipelineService.php
git commit -m "refactor: remove approver_id from pipeline editable fields"
```

---

## Task 12: Integration Testing & Cleanup

**Files:**
- Review all modified files for consistency

**Step 1: Manual smoke test checklist**

Run `php bin/cake server` and test:

1. [ ] Create a new invoice — starts in `aprobacion` status
2. [ ] Edit invoice as Registro/Revisión — multi-select for approvers appears
3. [ ] Select 2+ approvers and save — check DB for `invoice_approvals` records with tokens
4. [ ] Check email inbox — both approvers received approval link emails
5. [ ] Click approval link as first approver — approve → record shows 'Aprobada'
6. [ ] Verify invoice still in `aprobacion` (1 pending)
7. [ ] Click approval link as second approver — approve → `area_approval` = 'Aprobada'
8. [ ] With DIAN approved, verify auto-advance to `contabilidad`
9. [ ] Test rejection: create new invoice, assign 2 approvers, one rejects → invoice = 'Rechazada', other tokens invalidated
10. [ ] After rejection: edit invoice, re-assign approvers, verify new batch created
11. [ ] Check index page — summary badges show correctly

**Step 2: Verify legacy compatibility**

- Existing invoices with `approver_id` set but no `invoice_approvals` records should still display correctly (graceful fallback in index badge)

**Step 3: Final commit**

```bash
git add -A
git commit -m "feat: complete multi-approver support for invoice approval pipeline"
```

---

## Summary of Tasks

| # | Task | New Files | Modified Files |
|---|------|-----------|----------------|
| 1 | Migration | 1 | 0 |
| 2 | Entity & Table | 2 | 2 |
| 3 | Constants | 0 | 1 |
| 4 | InvoiceApprovalService | 1 | 0 |
| 5 | NotificationService update | 0 | 1 |
| 6 | InvoicePipelineService update | 0 | 1 |
| 7 | ExternalApprovalsController update | 0 | 1 |
| 8 | InvoicesController update | 0 | 2 |
| 9 | Edit template | 0 | 1 |
| 10 | Index template | 0 | 2 |
| 11 | Editable fields cleanup | 0 | 1 |
| 12 | Integration testing | 0 | 0 |
| **Total** | | **4 nuevos** | **~10 modificados** |

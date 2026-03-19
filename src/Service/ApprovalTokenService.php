<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\NoveltyConstants;
use App\Constants\RoleConstants;
use Cake\ORM\TableRegistry;
use DateTime;
use Exception;

class ApprovalTokenService
{
    private InvoiceHistoryService $historyService;
    private NotificationService $notificationService;

    public function __construct(
        ?InvoiceHistoryService $historyService = null,
        ?NotificationService $notificationService = null,
    ) {
        $this->historyService = $historyService ?? new InvoiceHistoryService();
        $this->notificationService = $notificationService ?? new NotificationService();
    }

    public function generateToken(string $entityType, int $entityId, int $createdBy, int $hoursValid = InvoiceConstants::APPROVAL_TOKEN_HOURS): string
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = new DateTime("+{$hoursValid} hours");

        $table = TableRegistry::getTableLocator()->get('ApprovalTokens');
        $entity = $table->newEntity([
            'token' => $token,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'created_by' => $createdBy,
            'expires_at' => $expiresAt,
        ]);

        $table->save($entity);

        return $token;
    }

    public function validateToken(string $token): ?object
    {
        $table = TableRegistry::getTableLocator()->get('ApprovalTokens');
        $record = $table->find()
            ->where(['token' => $token])
            ->first();

        if (!$record) {
            return null;
        }

        // Check if already used
        if ($record->used_at !== null) {
            return null;
        }

        // Check expiration
        if ($record->expires_at < new DateTime()) {
            return null;
        }

        return $record;
    }

    public function consumeToken(
        string $token,
        string $action,
        ?string $observations,
        ?string $ip,
        ?string $userAgent,
        ?string $approvalDate = null,
        ?int $approvedByUserId = null,
    ): bool {
        $table = TableRegistry::getTableLocator()->get('ApprovalTokens');
        $record = $table->find()
            ->where(['token' => $token])
            ->first();

        if (!$record || $record->used_at !== null) {
            return false;
        }

        $record->used_at = new DateTime();
        $record->action_taken = $action;
        $record->observations = $observations;
        $record->ip_address = $ip;
        $record->user_agent = $userAgent;
        $record->approved_by_user_id = $approvedByUserId;

        if (!$table->save($record)) {
            return false;
        }

        // Apply action to the entity
        return $this->applyAction($record->entity_type, $record->entity_id, $action, $observations, $approvedByUserId, $approvalDate);
    }

    private function applyAction(string $entityType, int $entityId, string $action, ?string $observations, ?int $createdBy, ?string $approvalDate): bool
    {
        switch ($entityType) {
            case 'invoices':
                return $this->applyInvoiceAction($entityId, $action, $observations, $createdBy, $approvalDate);
            case 'employee_novelties':
                return $this->applyNoveltyAction($entityId, $action);
            default:
                return false;
        }
    }

    private function applyInvoiceAction(int $invoiceId, string $action, ?string $observations, ?int $createdBy, ?string $approvalDate): bool
    {
        $table = TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $table->get($invoiceId, contain: ['Providers']);

        $userId = $createdBy ?? 0;
        $parsedDate = !empty($approvalDate) ? new DateTime($approvalDate) : new DateTime();

        if ($action === 'approve') {
            // Delegate to pipeline: save approval fields + auto-advance + history + notification
            $pipeline = new InvoicePipelineService($this->historyService, $this->notificationService);
            $result = $pipeline->saveAndAdvance(
                $invoice,
                [
                    'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
                    'area_approval_date' => $parsedDate,
                ],
                RoleConstants::ADMIN,
                $userId,
            );

            if ($result['saved'] && !empty($observations)) {
                $this->saveInvoiceObservation($invoiceId, $observations, $userId);
            }

            return $result['saved'];
        }

        if ($action === 'reject') {
            $invoice->area_approval = InvoiceConstants::APPROVAL_REJECTED;
            $invoice->area_approval_date = $parsedDate;

            if (!$table->save($invoice)) {
                return false;
            }

            $this->historyService->recordFieldChange($invoiceId, 'area_approval', InvoiceConstants::APPROVAL_PENDING, InvoiceConstants::APPROVAL_REJECTED, $userId);

            if (!empty($observations)) {
                $this->saveInvoiceObservation($invoiceId, $observations, $userId);
            }

            return true;
        }

        return true;
    }

    private function saveInvoiceObservation(int $invoiceId, string $message, int $userId): void
    {
        $observationsTable = TableRegistry::getTableLocator()->get('InvoiceObservations');
        $observation = $observationsTable->newEntity([
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'message' => $message,
        ]);
        $observationsTable->save($observation);
    }

    private function applyNoveltyAction(int $noveltyId, string $action): bool
    {
        $table = TableRegistry::getTableLocator()->get('EmployeeNovelties');
        $novelty = $table->get($noveltyId);

        if ($action === 'approve') {
            $novelty->status = NoveltyConstants::STATUS_APPROVED;
            $novelty->approved_at = new DateTime();
        } elseif ($action === 'reject') {
            $novelty->status = NoveltyConstants::STATUS_REJECTED;
            $novelty->approved_at = new DateTime();
        }

        return (bool)$table->save($novelty);
    }

    public function getEntity(string $entityType, int $entityId): ?object
    {
        $tableMap = [
            'invoices' => 'Invoices',
            'employee_novelties' => 'EmployeeNovelties',
        ];

        $tableName = $tableMap[$entityType] ?? null;
        if (!$tableName) {
            return null;
        }

        $table = TableRegistry::getTableLocator()->get($tableName);

        try {
            $contain = [];
            if ($entityType === 'invoices') {
                $contain = ['Providers', 'InvoiceDocuments'];
            } elseif ($entityType === 'employee_novelties') {
                $contain = ['Employees', 'NoveltyTypes'];
            }

            return $table->get($entityId, contain: $contain);
        } catch (Exception $e) {
            return null;
        }
    }
}

<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\Model\Entity\InvoiceApproval;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Exception;

class InvoiceApprovalService
{
    private $invoiceApprovalsTable;
    private $notificationService;

    public function __construct(
        ?NotificationService $notificationService = null,
    ) {
        $this->invoiceApprovalsTable = TableRegistry::getTableLocator()->get('InvoiceApprovals');
        $this->notificationService = $notificationService ?? new NotificationService();
    }

    /**
     * Assign approvers to an invoice, generate tokens, and send notifications.
     *
     * @param \App\Model\Entity\Invoice $invoice The invoice entity
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
            } catch (Exception $e) {
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
                'InvoiceApprovals.invoice_id' => $invoiceId,
                'InvoiceApprovals.created >=' => $batchStart,
            ])
            ->contain(['Users'])
            ->orderBy(['InvoiceApprovals.created' => 'ASC'])
            ->all()
            ->toArray();
    }

    /**
     * Validate and consume a token. Returns the approval record or null.
     */
    public function validateToken(string $token): ?InvoiceApproval
    {
        return $this->invoiceApprovalsTable->find()
            ->where([
                'token' => $token,
                'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
                'token_expires_at >' => new DateTime(),
            ])
            ->contain(['Invoices' => ['Providers', 'InvoiceDocuments'], 'Users'])
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
        ?string $userAgent,
    ): array {
        $approval = $this->validateToken($token);
        if (!$approval) {
            return ['success' => false, 'allApproved' => false, 'rejected' => false, 'errors' => ['Token inválido o expirado']];
        }

        $newStatus = $action === 'approve'
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
            $conditions,
        );
    }
}

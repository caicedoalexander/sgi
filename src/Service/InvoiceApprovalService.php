<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\Model\Entity\InvoiceApproval;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Exception;

/**
 * Multi-approver invoice approval workflow (invoice_approvals table).
 *
 * Manages per-approver tokens with batch tracking, quorum detection
 * (all must approve), and rejection cascading. Uses SELECT FOR UPDATE
 * to prevent TOCTOU race conditions on token consumption.
 *
 * For generic single-entity external approvals, see ApprovalTokenService
 * which uses the approval_tokens table.
 */
class InvoiceApprovalService
{
    private $invoiceApprovalsTable;

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
        $this->invoiceApprovalsTable = TableRegistry::getTableLocator()->get('InvoiceApprovals');
    }

    /**
     * Assign approvers to an invoice, generate tokens, and send notifications.
     *
     * @param \App\Model\Entity\Invoice $invoice The invoice entity
     * @param array $approverUserIds Array of user IDs to assign as approvers
     * @param string $baseUrl Base URL for approval links
     * @param int $createdByUserId The user who assigned the approvers
     * @return ServiceResult on success: data = ['approvals' => InvoiceApproval[]]
     */
    public function assignApprovers(Invoice $invoice, array $approverUserIds, string $baseUrl, int $createdByUserId): ServiceResult
    {
        $errors = [];
        $approvals = [];

        if (empty($approverUserIds)) {
            return ServiceResult::fail(['Debe seleccionar al menos un aprobador']);
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
                $this->notificationService->sendApprovalLinkNotification(
                    $invoice,
                    $approvalUrl,
                    (int)$userId,
                    $createdByUserId,
                );
            } catch (Exception $e) {
                $errors[] = sprintf(
                    'Aprobador asignado, pero el correo a usuario ID %d falló: %s. Puede reintentar desde el panel de notificaciones de la factura.',
                    (int)$userId,
                    $e->getMessage(),
                );
            }
        }

        if (!empty($errors)) {
            return ServiceResult::fail($errors);
        }

        return ServiceResult::ok(['approvals' => $approvals]);
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
     * Get all active approvals for the current round (not superseded).
     */
    public function getCurrentApprovals(int $invoiceId): array
    {
        return $this->invoiceApprovalsTable->find()
            ->where([
                'InvoiceApprovals.invoice_id' => $invoiceId,
                'InvoiceApprovals.status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE,
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
     * @return ServiceResult on success: data = ['allApproved' => bool, 'rejected' => bool, 'invoice_id' => int]
     */
    public function processResponse(
        string $token,
        string $action,
        ?string $observations,
        ?string $ipAddress,
        ?string $userAgent,
    ): ServiceResult {
        $connection = $this->invoiceApprovalsTable->getConnection();

        return $connection->transactional(function () use ($token, $action, $observations, $ipAddress, $userAgent) {
            // Lock the row to prevent concurrent consumption (FOR UPDATE)
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
                return ServiceResult::fail(['Token inválido o expirado']);
            }

            $newStatus = $action === 'approve'
                ? InvoiceConstants::APPROVER_STATUS_APPROVED
                : InvoiceConstants::APPROVER_STATUS_REJECTED;

            $approval->status = $newStatus;
            $approval->responded_at = new DateTime();
            $approval->observations = $observations;
            $approval->ip_address = $ipAddress;
            $approval->user_agent = $userAgent;
            $approval->token = null;

            if (!$this->invoiceApprovalsTable->save($approval)) {
                return ServiceResult::fail(['Error al guardar respuesta']);
            }

            $invoiceId = $approval->invoice_id;

            if ($action === 'reject') {
                $this->_invalidatePendingTokens($invoiceId, $approval->id);

                $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
                $invoice = $invoicesTable->get($invoiceId);
                $invoice->area_approval = InvoiceConstants::APPROVAL_REJECTED;
                $invoice->area_approval_date = new DateTime();
                $invoicesTable->save($invoice);

                return ServiceResult::ok([
                    'allApproved' => false,
                    'rejected' => true,
                    'invoice_id' => $invoiceId,
                ]);
            }

            $allApproved = $this->areAllApproved($invoiceId);

            if ($allApproved) {
                $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
                $invoice = $invoicesTable->get($invoiceId);
                $invoice->area_approval = InvoiceConstants::APPROVAL_APPROVED;
                $invoice->area_approval_date = new DateTime();
                $invoicesTable->save($invoice);
            }

            return ServiceResult::ok([
                'allApproved' => $allApproved,
                'rejected' => false,
                'invoice_id' => $invoiceId,
            ]);
        });
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
     * Check if invoice has any active approvals (pending/approved/rejected,
     * i.e. not superseded).
     */
    public function hasAnyActiveApprovals(int $invoiceId): bool
    {
        return $this->invoiceApprovalsTable->find()
            ->where([
                'invoice_id' => $invoiceId,
                'status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE,
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
     * Sends approval links without touching existing active approvals.
     * Fails if there are pending approvals already (caller should use modifyApprovers).
     *
     * @return ServiceResult on success: data = ['approvals' => InvoiceApproval[]]
     */
    public function sendApprovalLinks(Invoice $invoice, array $approverUserIds, string $baseUrl, int $createdByUserId): ServiceResult
    {
        if ($invoice->pipeline_status !== InvoiceConstants::STATUS_APROBACION) {
            return ServiceResult::fail(['Solo se pueden enviar enlaces mientras la factura esté en Aprobación.']);
        }
        if ($this->hasAnyActiveApprovals($invoice->id)) {
            return ServiceResult::fail(['Ya existen aprobaciones para esta factura; use Modificar aprobadores.']);
        }

        return $this->assignApprovers($invoice, $approverUserIds, $baseUrl, $createdByUserId);
    }

    /**
     * Replaces the current approver set: invalidates pending tokens, records the
     * reason in invoice history, and creates new approvals.
     *
     * @return ServiceResult on success: data = ['approvals' => InvoiceApproval[]]
     */
    public function modifyApprovers(
        Invoice $invoice,
        array $newApproverIds,
        string $reason,
        string $baseUrl,
        int $userId,
    ): ServiceResult {
        if ($invoice->pipeline_status !== InvoiceConstants::STATUS_APROBACION) {
            return ServiceResult::fail(['No se pueden modificar aprobadores fuera del estado Aprobación.']);
        }
        if (trim($reason) === '') {
            return ServiceResult::fail(['El motivo es obligatorio.']);
        }
        if (empty($newApproverIds)) {
            return ServiceResult::fail(['Debe seleccionar al menos un aprobador.']);
        }

        $connection = $this->invoiceApprovalsTable->getConnection();

        return $connection->transactional(function () use ($invoice, $newApproverIds, $reason, $baseUrl, $userId) {
            $previous = $this->getCurrentApprovals($invoice->id);
            $previousNames = array_map(
                fn($a) => $a->user->full_name ?? $a->user->username ?? 'Usuario #' . $a->user_id,
                $previous,
            );

            // Marca todas las aprobaciones vigentes (pendientes, aprobadas, rechazadas)
            // como Reemplazadas para que no cuenten en el nuevo batch.
            $this->invoiceApprovalsTable->updateAll(
                [
                    'status' => InvoiceConstants::APPROVER_STATUS_SUPERSEDED,
                    'token' => null,
                    'token_expires_at' => null,
                ],
                [
                    'invoice_id' => $invoice->id,
                    'status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE,
                ],
            );

            // Los votos previos quedan invalidados: resetea area_approval.
            $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
            $invoicesTable->updateAll(
                ['area_approval' => InvoiceConstants::APPROVAL_PENDING, 'area_approval_date' => null],
                ['id' => $invoice->id],
            );

            $usersTable = TableRegistry::getTableLocator()->get('Users');
            $newUsers = $usersTable->find()
                ->where(['id IN' => array_map('intval', $newApproverIds)])
                ->all()
                ->toArray();
            $newNames = array_map(
                fn($u) => $u->full_name ?? $u->username ?? 'Usuario #' . $u->id,
                $newUsers,
            );

            $historiesTable = TableRegistry::getTableLocator()->get('InvoiceHistories');
            $historiesTable->save($historiesTable->newEntity([
                'invoice_id' => $invoice->id,
                'user_id' => $userId,
                'field_changed' => 'approvers_modified',
                'old_value' => implode(', ', $previousNames) ?: '—',
                'new_value' => (implode(', ', $newNames) ?: '—') . ' (Motivo: ' . $reason . ')',
            ]));

            return $this->assignApprovers($invoice, $newApproverIds, $baseUrl, $userId);
        });
    }

    /**
     * Resets the approval flow on a rejected invoice: clears approvals and
     * sets area_approval back to pending.
     */
    public function resetFlow(Invoice $invoice, int $userId): ServiceResult
    {
        if ($invoice->area_approval !== InvoiceConstants::APPROVAL_REJECTED) {
            return ServiceResult::fail('La factura no está rechazada; no se puede reiniciar el flujo.');
        }

        $connection = $this->invoiceApprovalsTable->getConnection();

        $connection->transactional(function () use ($invoice, $userId): void {
            $this->invoiceApprovalsTable->deleteAll(['invoice_id' => $invoice->id]);

            $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
            $invoice->area_approval = InvoiceConstants::APPROVAL_PENDING;
            $invoice->area_approval_date = null;
            $invoicesTable->save($invoice);

            $historiesTable = TableRegistry::getTableLocator()->get('InvoiceHistories');
            $historiesTable->save($historiesTable->newEntity([
                'invoice_id' => $invoice->id,
                'user_id' => $userId,
                'field_changed' => 'area_approval',
                'old_value' => InvoiceConstants::APPROVAL_REJECTED,
                'new_value' => InvoiceConstants::APPROVAL_PENDING,
            ]));
        });

        return ServiceResult::ok('Flujo de aprobación reiniciado.');
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

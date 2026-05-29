<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\ApprovalConstants;
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

    private InvoiceHistoryService $historyService;

    public function __construct(
        private readonly NotificationService $notificationService,
        ?InvoiceHistoryService $historyService = null,
    ) {
        $this->invoiceApprovalsTable = TableRegistry::getTableLocator()->get('InvoiceApprovals');
        $this->historyService = $historyService ?? new InvoiceHistoryService();
    }

    /**
     * Assign approvers to an invoice, generate tokens, and send notifications.
     *
     * @param \App\Model\Entity\Invoice $invoice The invoice entity
     * @param array $approverUserIds Array of user IDs to assign as approvers
     * @param string $baseUrl Base URL for approval links
     * @param int $createdByUserId The user who assigned the approvers
     * @return \App\Service\ServiceResult on success: data = ['approvals' => InvoiceApproval[]]
     */
    public function assignApprovers(Invoice $invoice, array $approverUserIds, string $baseUrl, int $createdByUserId): ServiceResult
    {
        if (empty($approverUserIds)) {
            return ServiceResult::fail(['Debe seleccionar al menos un aprobador']);
        }

        $persistResult = $this->_persistApprovers($invoice, $approverUserIds, $baseUrl);
        if (!$persistResult['success']) {
            return ServiceResult::fail($persistResult['errors']);
        }

        $this->_recordInitialAssignment($invoice, $approverUserIds, $createdByUserId);

        $emailErrors = $this->_sendApprovalEmails($invoice, $persistResult['pending'], $createdByUserId);

        if (!empty($emailErrors)) {
            return ServiceResult::fail($emailErrors);
        }

        return ServiceResult::ok(['approvals' => $persistResult['approvals']]);
    }

    /**
     * Persiste filas de invoice_approvals y devuelve la lista de pendientes
     * de notificación (cada item tiene userId + approvalUrl). Solo escribe DB —
     * no envía correos. Seguro de invocar dentro de una transacción.
     *
     * @return array{success: bool, approvals: array, pending: array, errors: array}
     */
    private function _persistApprovers(Invoice $invoice, array $approverUserIds, string $baseUrl): array
    {
        $errors = [];
        $approvals = [];
        $pending = [];
        $expiresAt = new DateTime('+' . InvoiceConstants::APPROVAL_TOKEN_HOURS . ' hours');

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
            $pending[] = [
                'userId' => (int)$userId,
                'approvalUrl' => $baseUrl . '/approve/' . $token,
            ];
        }

        return [
            'success' => empty($errors),
            'approvals' => $approvals,
            'pending' => $pending,
            'errors' => $errors,
        ];
    }

    /**
     * Envía las notificaciones por correo. Debe ejecutarse FUERA de cualquier
     * transacción de DB para evitar que un fallo SMTP o un save() de email_logs
     * envenene una transacción externa con savepoints anidados.
     *
     * @param array<int, array{userId:int, approvalUrl:string}> $pending
     * @return array<string> Errores recolectados (vacío si todo OK).
     */
    private function _sendApprovalEmails(Invoice $invoice, array $pending, int $createdByUserId): array
    {
        $errors = [];

        foreach ($pending as $item) {
            try {
                $this->notificationService->sendApprovalLinkNotification(
                    $invoice,
                    $item['approvalUrl'],
                    $item['userId'],
                    $createdByUserId,
                );
            } catch (Exception $e) {
                $errors[] = sprintf(
                    'Aprobador asignado, pero el correo a usuario ID %d falló: %s. Puede reintentar desde el panel de notificaciones de la factura.',
                    $item['userId'],
                    $e->getMessage(),
                );
            }
        }

        return $errors;
    }

    /**
     * Registra en el historial la asignación inicial de aprobadores.
     * Usa el mismo campo 'approvers_modified' que modifyApprovers para que la
     * vista de historial lo muestre igual; old_value es null al ser la primera
     * asignación.
     *
     * @param array<int|string> $approverUserIds
     */
    private function _recordInitialAssignment(Invoice $invoice, array $approverUserIds, int $userId): void
    {
        $usersTable = TableRegistry::getTableLocator()->get('Users');
        $users = $usersTable->find()
            ->where(['id IN' => array_map('intval', $approverUserIds)])
            ->all()
            ->toArray();
        $names = array_map(
            fn($u) => $u->full_name ?? $u->username ?? 'Usuario #' . $u->id,
            $users,
        );

        $this->historyService->recordFieldChange(
            (int)$invoice->id,
            'approvers_modified',
            null,
            implode(', ', $names) ?: '—',
            $userId,
        );
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
            ->contain(['Users' => ['Roles']])
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
     * @return \App\Service\ServiceResult on success: data = ['allApproved' => bool, 'rejected' => bool, 'invoice_id' => int]
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

            $newStatus = $action === ApprovalConstants::ACTION_APPROVE
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

            // Registrar respuesta individual en el historial (una entrada por aprobador)
            $responseLabel = $action === ApprovalConstants::ACTION_APPROVE
                ? InvoiceConstants::APPROVER_STATUS_APPROVED
                : InvoiceConstants::APPROVER_STATUS_REJECTED;
            $this->historyService->recordFieldChange(
                $invoiceId,
                'approver_response',
                null,
                $responseLabel,
                (int)$approval->user_id,
            );

            if ($action === ApprovalConstants::ACTION_REJECT) {
                $this->_invalidatePendingTokens($invoiceId, $approval->id);

                $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
                $invoice = $invoicesTable->get($invoiceId);
                $invoice->area_approval = InvoiceConstants::APPROVAL_REJECTED;
                $invoice->area_approval_date = new DateTime();
                $invoicesTable->save($invoice);

                $this->historyService->recordFieldChange(
                    $invoiceId,
                    'area_approval',
                    InvoiceConstants::APPROVAL_PENDING,
                    InvoiceConstants::APPROVAL_REJECTED,
                    (int)$approval->user_id,
                );

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

                $this->historyService->recordFieldChange(
                    $invoiceId,
                    'area_approval',
                    InvoiceConstants::APPROVAL_PENDING,
                    InvoiceConstants::APPROVAL_APPROVED,
                    (int)$approval->user_id,
                );
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
     * Devuelve los summaries de aprobación para múltiples facturas en una sola query.
     *
     * @param array<int> $invoiceIds
     * @return array<int, array> indexado por invoice_id
     */
    public function getApprovalSummariesBatch(array $invoiceIds): array
    {
        if (empty($invoiceIds)) {
            return [];
        }

        $rows = $this->invoiceApprovalsTable->find()
            ->where([
                'InvoiceApprovals.invoice_id IN' => $invoiceIds,
                'InvoiceApprovals.status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE,
            ])
            ->contain(['Users'])
            ->orderBy(['InvoiceApprovals.created' => 'ASC'])
            ->all()
            ->toArray();

        $byInvoice = [];
        foreach ($rows as $row) {
            $byInvoice[$row->invoice_id][] = $row;
        }

        $summaries = [];
        foreach ($invoiceIds as $id) {
            $summaries[$id] = $this->_summaryFromApprovals($byInvoice[$id] ?? []);
        }

        return $summaries;
    }

    /**
     * Construye el summary desde un array de approvals ya cargado.
     */
    private function _summaryFromApprovals(array $approvals): array
    {
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
     * @return \App\Service\ServiceResult on success: data = ['approvals' => InvoiceApproval[]]
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
     * @return \App\Service\ServiceResult on success: data = ['approvals' => InvoiceApproval[]]
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

        // DB writes inside transaction; emails sent after commit (side-effects fuera).
        $persisted = $connection->transactional(function () use ($invoice, $newApproverIds, $reason, $baseUrl, $userId) {
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

            return $this->_persistApprovers($invoice, $newApproverIds, $baseUrl);
        });

        if (!$persisted['success']) {
            return ServiceResult::fail($persisted['errors']);
        }

        // Envío SMTP fuera de la transacción: un fallo aquí ya no envenena el commit.
        $emailErrors = $this->_sendApprovalEmails($invoice, $persisted['pending'], $userId);

        if (!empty($emailErrors)) {
            return ServiceResult::fail($emailErrors);
        }

        return ServiceResult::ok(['approvals' => $persisted['approvals']]);
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

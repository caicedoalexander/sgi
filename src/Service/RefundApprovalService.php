<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\RefundConstants;
use App\Service\GroupApproval\GroupApprovalService;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

/**
 * Aprobación de área en lote para Reintegros (tabla refund_approvals).
 */
final class RefundApprovalService extends GroupApprovalService
{
    /**
     * @param \App\Service\NotificationService $notificationService Sends the approval-link emails.
     * @param \App\Service\RefundHistoryService|null $refundHistory Refund-specific audit trail.
     */
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly ?RefundHistoryService $refundHistory = null,
    ) {
        parent::__construct();
    }

    /**
     * CakePHP table name backing the refund approvals.
     *
     * @return string
     */
    protected function tableName(): string
    {
        return 'RefundApprovals';
    }

    /**
     * FK column linking an approval row to its refund.
     *
     * @return string
     */
    protected function fkField(): string
    {
        return 'refund_id';
    }

    /**
     * Send the approval link email to a single approver.
     *
     * @param object $entity Refund entity under approval.
     * @param string $url Signed approval URL for the approver.
     * @param int $userId Approver user ID.
     * @param int $createdBy User ID that assigned the approver.
     * @return void
     */
    protected function notifyApprover(object $entity, string $url, int $userId, int $createdBy): void
    {
        $this->notificationService->sendRefundApprovalLinkNotification($entity, $url, $userId, $createdBy);
    }

    /**
     * Apply the domain effect once every approver has approved: mark the refund's
     * child invoices as area-approved.
     *
     * @param int $entityId Refund ID.
     * @param int $approverUserId User ID of the last approver.
     * @return void
     */
    protected function onAllApproved(int $entityId, int $approverUserId): void
    {
        TableRegistry::getTableLocator()->get('Invoices')->updateAll(
            ['area_approval' => InvoiceConstants::APPROVAL_APPROVED, 'area_approval_date' => new DateTime()],
            ['refund_id' => $entityId],
        );
    }

    /**
     * Apply the domain effect on rejection: regress the refund from Aprobación
     * back to Agrupación, log the observation and record the status change.
     *
     * @param int $entityId Refund ID.
     * @param int $approverUserId User ID of the rejecting approver.
     * @param string|null $observations Optional rejection reason.
     * @return void
     */
    protected function onRejected(int $entityId, int $approverUserId, ?string $observations): void
    {
        $refunds = TableRegistry::getTableLocator()->get('Refunds');
        $refund = $refunds->get($entityId);
        if ($refund->status !== RefundConstants::STATUS_APROBACION) {
            return;
        }
        $from = $refund->status;
        $refund->status = RefundConstants::STATUS_AGRUPACION;
        $refunds->saveOrFail($refund);

        $reason = trim((string)$observations) !== '' ? $observations : 'Rechazado por el aprobador de área.';

        $observationsTable = TableRegistry::getTableLocator()->get('RefundObservations');
        $observationsTable->saveOrFail($observationsTable->newEntity([
            'refund_id' => $entityId,
            'user_id' => $approverUserId,
            'type' => RefundConstants::OBSERVATION_TYPE_REGRESSION,
            'message' => $reason,
            'metadata' => ['from_status' => $from, 'to_status' => RefundConstants::STATUS_AGRUPACION],
        ]));

        ($this->refundHistory ?? new RefundHistoryService())
            ->recordStatusChange($entityId, $from, RefundConstants::STATUS_AGRUPACION, $approverUserId);
    }
}

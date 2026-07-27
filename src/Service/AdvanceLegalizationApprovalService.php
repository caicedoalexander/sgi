<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\GroupApproval\GroupApprovalService;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

/**
 * Aprobación de área en lote para Legalización de Anticipos
 * (tabla advance_legalization_approvals). Reutiliza la mecánica multi-aprobador
 * de GroupApprovalService; sólo aporta los efectos de dominio.
 */
final class AdvanceLegalizationApprovalService extends GroupApprovalService
{
    /**
     * @param \App\Service\NotificationService $notificationService Sends the approval-link emails.
     * @param \App\Service\AdvanceLegalizationHistoryService|null $legHistory Legalization audit trail.
     */
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly ?AdvanceLegalizationHistoryService $legHistory = null,
    ) {
        parent::__construct();
    }

    /**
     * CakePHP table name backing the legalization approvals.
     *
     * @return string
     */
    protected function tableName(): string
    {
        return 'AdvanceLegalizationApprovals';
    }

    /**
     * FK column linking an approval row to its legalization.
     *
     * @return string
     */
    protected function fkField(): string
    {
        return 'advance_legalization_id';
    }

    /**
     * Send the approval link email to a single approver.
     *
     * @param object $entity Advance legalization entity under approval.
     * @param string $url Signed approval URL for the approver.
     * @param int $userId Approver user ID.
     * @param int $createdBy User ID that assigned the approver.
     * @return void
     */
    protected function notifyApprover(object $entity, string $url, int $userId, int $createdBy): void
    {
        $this->notificationService->sendAdvanceLegalizationApprovalLinkNotification($entity, $url, $userId, $createdBy);
    }

    /**
     * Todos aprobaron: marca area_approval=Aprobada en las facturas hijas
     * (por advance_id = advance_invoice_id del leg). El movimiento a
     * invoice-contabilidad lo hace el verbo de consolidación (avance manual).
     */
    protected function onAllApproved(int $entityId, int $approverUserId): void
    {
        $leg = TableRegistry::getTableLocator()->get('AdvanceLegalizations')->get($entityId);
        TableRegistry::getTableLocator()->get('Invoices')->updateAll(
            ['area_approval' => InvoiceConstants::APPROVAL_APPROVED, 'area_approval_date' => new DateTime()],
            [
                'advance_id' => $leg->advance_invoice_id,
                'document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
            ],
        );
    }

    /**
     * Un aprobador rechazó: regresa el leg aprobacion→validacion y audita.
     * Los links pendientes ya los invalida la base (processResponse).
     */
    protected function onRejected(int $entityId, int $approverUserId, ?string $observations): void
    {
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg = $legTable->get($entityId);
        if ($leg->status !== AdvanceConstants::STATUS_APROBACION) {
            return;
        }
        $from = $leg->status;
        $leg->status = AdvanceConstants::STATUS_VALIDACION;
        $legTable->saveOrFail($leg);

        ($this->legHistory ?? new AdvanceLegalizationHistoryService())
            ->recordStatusChange($entityId, $from, AdvanceConstants::STATUS_VALIDACION, $approverUserId);
    }
}

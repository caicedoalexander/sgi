<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Service\Dto\GroupReadinessReport;
use Cake\ORM\TableRegistry;

/**
 * Consultas ligeras sobre advance_legalization_approvals (quórum) e invoices
 * hijas (DIAN + soporte, vía GroupReadinessQuery) para el AprobacionState puro
 * del pipeline de legalización.
 * Espejo de RefundApprovalGuard. NO final: PHPUnit mockea el guard.
 */
class AdvanceLegalizationApprovalGuard
{
    /**
     * Count approval rows still in an active state for a legalization.
     *
     * @param int $legalizationId Advance legalization ID.
     * @return int
     */
    public function activeApproverCount(int $legalizationId): int
    {
        return TableRegistry::getTableLocator()->get('AdvanceLegalizationApprovals')->find()
            ->where([
                'advance_legalization_id' => $legalizationId,
                'status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE,
            ])
            ->count();
    }

    /**
     * Count approval rows already in the 'Aprobada' state for a legalization.
     *
     * @param int $legalizationId Advance legalization ID.
     * @return int
     */
    public function approvedCount(int $legalizationId): int
    {
        return TableRegistry::getTableLocator()->get('AdvanceLegalizationApprovals')->find()
            ->where([
                'advance_legalization_id' => $legalizationId,
                'status' => InvoiceConstants::APPROVER_STATUS_APPROVED,
            ])
            ->count();
    }

    /** True si hay ≥1 aprobador activo y todos están en 'Aprobada'. */
    public function allApproved(int $legalizationId): bool
    {
        $active = $this->activeApproverCount($legalizationId);

        return $active > 0 && $active === $this->approvedCount($legalizationId);
    }

    /**
     * Requisitos pendientes (DIAN + soporte) de las hijas vinculadas al anticipo.
     * Recibe el id del Invoice del anticipo (advance_invoice_id).
     */
    public function childRequirements(int $advanceInvoiceId): GroupReadinessReport
    {
        return GroupReadinessQuery::report([
            'advance_id' => $advanceInvoiceId,
            'document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
        ]);
    }
}

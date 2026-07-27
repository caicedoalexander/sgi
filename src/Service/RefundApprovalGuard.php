<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Service\Dto\GroupReadinessReport;
use Cake\ORM\TableRegistry;

/**
 * Consultas ligeras sobre refund_approvals para los States puros del pipeline
 * de reintegros (espejo de AdvanceLegalizationGuard).
 */
class RefundApprovalGuard
{
    /** Cantidad de aprobadores activos (no rechazados) asignados al reintegro. */
    public function activeApproverCount(int $refundId): int
    {
        return TableRegistry::getTableLocator()->get('RefundApprovals')->find()
            ->where(['refund_id' => $refundId, 'status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE])
            ->count();
    }

    /** Cantidad de aprobadores que ya aprobaron el reintegro. */
    public function approvedCount(int $refundId): int
    {
        return TableRegistry::getTableLocator()->get('RefundApprovals')->find()
            ->where(['refund_id' => $refundId, 'status' => InvoiceConstants::APPROVER_STATUS_APPROVED])
            ->count();
    }

    /** True si hay ≥1 aprobador activo y todos están en 'Aprobada'. */
    public function allApproved(int $refundId): bool
    {
        $active = $this->activeApproverCount($refundId);

        return $active > 0 && $active === $this->approvedCount($refundId);
    }

    /** Requisitos pendientes (DIAN + soporte) de las facturas hijas. */
    public function childRequirements(int $refundId): GroupReadinessReport
    {
        return GroupReadinessQuery::report(['refund_id' => $refundId]);
    }
}

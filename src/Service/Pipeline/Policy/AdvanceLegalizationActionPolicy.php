<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Policy;

use App\Constants\AdvanceConstants;
use App\Constants\RoleConstants;
use App\Model\Entity\AdvanceLegalization;

/**
 * Decide whether a given role is allowed to execute a mutating action on an
 * advance legalization in its current pipeline state.
 *
 * Authorization matrix (from advances audit 2026-05-05, finding CR-003):
 *
 * - Contabilidad/Admin: linkInvoices, unlinkInvoice, uploadRelationDocument,
 *   moveToRevision, markSigned, returnToValidacion, markExact,
 *   registerShortage, registerSurplus.
 * - Tesorería/Admin: confirmShortage, registerRefund.
 *
 * Each predicate combines role membership with the legalization status that
 * the action expects. The service layer still enforces state guards on its
 * own; this policy is the gatekeeper for HTTP entry points.
 */
final class AdvanceLegalizationActionPolicy
{
    /**
     * Contabilidad/Admin can bulk-link invoices while the leg is in Validación.
     */
    public function canLinkInvoices(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isAccountingOrAdmin($roleName)
            && $leg->status === AdvanceConstants::STATUS_VALIDACION;
    }

    /**
     * Contabilidad/Admin can detach a linked invoice while the leg is in Validación.
     */
    public function canUnlinkInvoice(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isAccountingOrAdmin($roleName)
            && $leg->status === AdvanceConstants::STATUS_VALIDACION;
    }

    /**
     * Contabilidad/Admin can upload the relation-of-invoices PDF in Validación
     * or Revisión y Firmas (re-upload after signature rejection).
     */
    public function canUploadRelationDocument(AdvanceLegalization $leg, string $roleName): bool
    {
        $allowedStatuses = [
            AdvanceConstants::STATUS_VALIDACION,
            AdvanceConstants::STATUS_REVISION_FIRMAS,
        ];

        return $this->_isAccountingOrAdmin($roleName)
            && in_array($leg->status, $allowedStatuses, true);
    }

    /**
     * Contabilidad/Admin can advance from Validación to Revisión y Firmas.
     */
    public function canMoveToRevision(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isAccountingOrAdmin($roleName)
            && $leg->status === AdvanceConstants::STATUS_VALIDACION;
    }

    /**
     * Contabilidad/Admin can mark the relation document as signed in Revisión y Firmas.
     */
    public function canMarkSigned(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isAccountingOrAdmin($roleName)
            && $leg->status === AdvanceConstants::STATUS_REVISION_FIRMAS;
    }

    /**
     * Contabilidad/Admin can reject the signature and bounce back to Validación.
     */
    public function canReturnToValidacion(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isAccountingOrAdmin($roleName)
            && $leg->status === AdvanceConstants::STATUS_REVISION_FIRMAS;
    }

    /**
     * Contabilidad/Admin can close as caso exacto in Contabilidad.
     */
    public function canMarkExact(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isAccountingOrAdmin($roleName)
            && $leg->status === AdvanceConstants::STATUS_CONTABILIDAD;
    }

    /**
     * Contabilidad/Admin can declare a shortage in Contabilidad.
     */
    public function canRegisterShortage(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isAccountingOrAdmin($roleName)
            && $leg->status === AdvanceConstants::STATUS_CONTABILIDAD;
    }

    /**
     * Contabilidad/Admin can declare a surplus in Contabilidad.
     */
    public function canRegisterSurplus(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isAccountingOrAdmin($roleName)
            && $leg->status === AdvanceConstants::STATUS_CONTABILIDAD;
    }

    /**
     * Tesorería/Admin can confirm the beneficiary's shortage deposit in Tesorería.
     */
    public function canConfirmShortage(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isTreasuryOrAdmin($roleName)
            && $leg->status === AdvanceConstants::STATUS_TESORERIA
            && $leg->case_type === AdvanceConstants::CASE_FALTANTE;
    }

    /**
     * Tesorería/Admin can register the refund payment to the beneficiary in Tesorería.
     */
    public function canRegisterRefund(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isTreasuryOrAdmin($roleName)
            && $leg->status === AdvanceConstants::STATUS_TESORERIA
            && $leg->case_type === AdvanceConstants::CASE_SOBRANTE;
    }

    /**
     * Whether the role is Contabilidad or Administrador.
     */
    private function _isAccountingOrAdmin(string $roleName): bool
    {
        return in_array(
            $roleName,
            [RoleConstants::CONTABILIDAD, RoleConstants::ADMIN],
            true,
        );
    }

    /**
     * Whether the role is Tesorería or Administrador.
     */
    private function _isTreasuryOrAdmin(string $roleName): bool
    {
        return in_array(
            $roleName,
            [RoleConstants::TESORERIA, RoleConstants::ADMIN],
            true,
        );
    }
}

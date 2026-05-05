<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Policy;

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
 * Audit MA-010 — la regla de **estado** vive en los predicates `canXxx()` de
 * `AdvanceLegalization`. Este policy compone solo la dimensión de **rol**:
 * delega el chequeo de estado a la entidad, evitando duplicación de la
 * matriz de transiciones.
 */
final class AdvanceLegalizationActionPolicy
{
    /** Contabilidad/Admin puede vincular facturas (estado-only delegado a la entidad). */
    public function canLinkInvoices(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isAccountingOrAdmin($roleName) && $leg->canLinkInvoices();
    }

    /** Contabilidad/Admin puede desvincular una factura. */
    public function canUnlinkInvoice(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isAccountingOrAdmin($roleName) && $leg->canUnlinkInvoice();
    }

    /** Contabilidad/Admin puede (re)subir la relación de facturas en Validación o Revisión. */
    public function canUploadRelationDocument(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isAccountingOrAdmin($roleName) && $leg->canUploadRelationDocument();
    }

    /** Contabilidad/Admin puede avanzar a Revisión y Firmas. */
    public function canMoveToRevision(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isAccountingOrAdmin($roleName) && $leg->canMoveToRevision();
    }

    /** Contabilidad/Admin puede marcar la firma como completa. */
    public function canMarkSigned(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isAccountingOrAdmin($roleName) && $leg->canMarkSigned();
    }

    /** Contabilidad/Admin puede devolver a Validación con motivo. */
    public function canReturnToValidacion(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isAccountingOrAdmin($roleName) && $leg->canReturnToValidacion();
    }

    /** Contabilidad/Admin puede declarar caso exacto. */
    public function canMarkExact(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isAccountingOrAdmin($roleName) && $leg->canMarkExact();
    }

    /** Contabilidad/Admin puede declarar faltante. */
    public function canRegisterShortage(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isAccountingOrAdmin($roleName) && $leg->canRegisterShortage();
    }

    /** Contabilidad/Admin puede declarar sobrante. */
    public function canRegisterSurplus(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isAccountingOrAdmin($roleName) && $leg->canRegisterSurplus();
    }

    /** Tesorería/Admin puede confirmar consignación de faltante. */
    public function canConfirmShortage(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isTreasuryOrAdmin($roleName) && $leg->canConfirmShortage();
    }

    /** Tesorería/Admin puede registrar el pago de reintegro al beneficiario. */
    public function canRegisterRefund(AdvanceLegalization $leg, string $roleName): bool
    {
        return $this->_isTreasuryOrAdmin($roleName) && $leg->canRegisterRefund();
    }

    /** @return bool true si el rol es Contabilidad o Administrador. */
    private function _isAccountingOrAdmin(string $roleName): bool
    {
        return in_array(
            $roleName,
            [RoleConstants::CONTABILIDAD, RoleConstants::ADMIN],
            true,
        );
    }

    /** @return bool true si el rol es Tesorería o Administrador. */
    private function _isTreasuryOrAdmin(string $roleName): bool
    {
        return in_array(
            $roleName,
            [RoleConstants::TESORERIA, RoleConstants::ADMIN],
            true,
        );
    }
}

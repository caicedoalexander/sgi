<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\AdvanceLegalization;
use App\ValueObject\UserContext;

/**
 * Decide whether a given role is allowed to execute a mutating action on an
 * advance legalization in its current pipeline state.
 *
 * Audit MA-010 — la regla de **estado** vive en los predicates `canXxx()` de
 * `AdvanceLegalization`. Este policy compone solo la dimensión de **rol×paso**,
 * delegando esa decisión a `AuthorizationFacade` (matriz configurable
 * desde /roles/edit). El chequeo de estado sigue delegado a la entidad.
 */
final class AdvanceLegalizationActionPolicy
{
    public function __construct(
        private AuthorizationFacade $auth,
    ) {
    }

    public function canLinkInvoices(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canLinkInvoices();
    }

    public function canUnlinkInvoice(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canUnlinkInvoice();
    }

    public function canUploadRelationDocument(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canUploadRelationDocument();
    }

    public function canMoveToRevision(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canMoveToRevision();
    }

    public function canMarkSigned(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canMarkSigned();
    }

    public function canReturnToValidacion(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canReturnToValidacion();
    }

    public function canMarkExact(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canMarkExact();
    }

    public function canRegisterShortage(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canRegisterShortage();
    }

    public function canRegisterSurplus(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canRegisterSurplus();
    }

    public function canConfirmShortage(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canConfirmShortage();
    }

    public function canRegisterRefund(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canRegisterRefund();
    }

    public function canAuthorizeRefundPayment(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canAuthorizeRefundPayment();
    }

    public function canConfirmRefundPayment(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canConfirmRefundPayment();
    }

    private function _canOperate(int $roleId, string $step): bool
    {
        return $this->auth->canOperate(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_LEGALIZATIONS,
            $step,
        );
    }
}

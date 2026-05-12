<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\PipelineStepConstants;
use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\ValueObject\UserContext;

/**
 * Audit PA-011 — espejo de `AdvanceLegalizationActionPolicy` para reintegros.
 * Reemplaza el wrapper privado `RefundsController::_canOperateRefundStep` y los
 * `authFacade->canOperate` inline del controller.
 */
final class RefundActionPolicy
{
    /**
     * @param \App\Authorization\AuthorizationFacade $auth Authorization facade.
     */
    public function __construct(
        private AuthorizationFacade $auth,
    ) {
    }

    /**
     * @param int $roleId Role ID.
     * @param string $step Pipeline step.
     * @return bool
     */
    public function canOperateStep(int $roleId, string $step): bool
    {
        return $this->auth->canOperate(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_REFUNDS,
            $step,
        );
    }

    /**
     * @param \App\Model\Entity\Refund $refund Reintegro.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canOperateCurrentStep(Refund $refund, int $roleId): bool
    {
        if ($refund->isPagada()) {
            return false;
        }

        return $this->canOperateStep($roleId, (string)$refund->status);
    }

    /**
     * @param \App\Model\Entity\Refund $refund Reintegro.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canRegisterPayment(Refund $refund, int $roleId): bool
    {
        if ($refund->isPagada()) {
            return false;
        }

        return $this->canOperateStep($roleId, RefundConstants::STATUS_TESORERIA);
    }

    /**
     * @param \App\Model\Entity\Refund $refund Reintegro.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canAuthorizePayment(Refund $refund, int $roleId): bool
    {
        if ($refund->isPagada()) {
            return false;
        }

        return $this->canOperateStep($roleId, RefundConstants::STATUS_AUTORIZACION_PAGO);
    }

    /**
     * @param \App\Model\Entity\Refund $refund Reintegro.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canConfirmPayment(Refund $refund, int $roleId): bool
    {
        if ($refund->isPagada()) {
            return false;
        }

        return $this->canOperateStep($roleId, RefundConstants::STATUS_VERIFICACION_PAGO);
    }
}

<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\PaymentSchedulingConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\PaymentScheduling;
use App\ValueObject\UserContext;

/**
 * Audit PA-011 — espejo de `AdvanceLegalizationActionPolicy` para programación
 * de pagos.
 */
final class PaymentSchedulingActionPolicy
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
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            $step,
        );
    }

    /**
     * @param \App\Model\Entity\PaymentScheduling $scheduling Programación.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canReject(PaymentScheduling $scheduling, int $roleId): bool
    {
        return $scheduling->canReject()
            && $this->canOperateStep($roleId, PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO);
    }

    /**
     * @param \App\Model\Entity\PaymentScheduling $scheduling Programación.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canConfirmPayment(PaymentScheduling $scheduling, int $roleId): bool
    {
        return $scheduling->canConfirmPayment()
            && $this->canOperateStep($roleId, PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO);
    }
}

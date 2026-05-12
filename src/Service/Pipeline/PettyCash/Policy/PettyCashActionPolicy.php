<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\PettyCashConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\PettyCashRecord;
use App\ValueObject\UserContext;

/**
 * Audit PA-011 — espejo de `AdvanceLegalizationActionPolicy` para caja menor.
 * Reemplaza los `authFacade->canOperate` inline del ViewModel del controller.
 */
final class PettyCashActionPolicy
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
            PipelineStepConstants::PIPELINE_PETTY_CASH,
            $step,
        );
    }

    /**
     * @param \App\Model\Entity\PettyCashRecord $record Registro.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canRegisterPayment(PettyCashRecord $record, int $roleId): bool
    {
        if ($record->isPagada()) {
            return false;
        }

        return $this->canOperateStep($roleId, PettyCashConstants::STATUS_TESORERIA);
    }

    /**
     * @param \App\Model\Entity\PettyCashRecord $record Registro.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canAuthorizePayment(PettyCashRecord $record, int $roleId): bool
    {
        if ($record->isPagada()) {
            return false;
        }

        return $this->canOperateStep($roleId, PettyCashConstants::STATUS_AUTORIZACION_PAGO);
    }

    /**
     * @param \App\Model\Entity\PettyCashRecord $record Registro.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canConfirmPayment(PettyCashRecord $record, int $roleId): bool
    {
        if ($record->isPagada()) {
            return false;
        }

        return $this->canOperateStep($roleId, PettyCashConstants::STATUS_VERIFICACION_PAGO);
    }
}

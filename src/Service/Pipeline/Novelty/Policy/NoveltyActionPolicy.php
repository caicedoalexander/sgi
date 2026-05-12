<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\NoveltyConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\ValueObject\UserContext;

/**
 * Audit PA-011 — espejo de `AdvanceLegalizationActionPolicy` para novedades.
 * El controller `EmployeeNoveltiesController` no realiza chequeos de
 * `authFacade->canOperate` directos hoy (la lógica de gating vive en
 * `NoveltyService`), pero esta policy queda registrada para uniformidad y como
 * punto de extensión cuando se introduzcan acciones futuras en el pipeline.
 */
final class NoveltyActionPolicy
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
            PipelineStepConstants::PIPELINE_NOVELTIES,
            $step,
        );
    }

    /**
     * @param \App\Model\Entity\EmployeeNovelty $novelty Novedad.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canOperateCurrentStep(EmployeeNovelty $novelty, int $roleId): bool
    {
        if ($novelty->isRejected()) {
            return false;
        }

        return $this->canOperateStep($roleId, (string)$novelty->pipeline_status);
    }

    /**
     * @param \App\Model\Entity\NoveltyLiquidationDoc $doc Documento de liquidación.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canRegisterPayment(NoveltyLiquidationDoc $doc, int $roleId): bool
    {
        return $doc->canRegisterPayment()
            && $this->canOperateStep($roleId, NoveltyConstants::STATUS_TESORERIA);
    }

    /**
     * @param \App\Model\Entity\NoveltyLiquidationDoc $doc Documento de liquidación.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canAuthorizePayment(NoveltyLiquidationDoc $doc, int $roleId): bool
    {
        return $doc->canAuthorizePayment()
            && $this->canOperateStep($roleId, NoveltyConstants::STATUS_AUTORIZACION_PAGO);
    }

    /**
     * @param \App\Model\Entity\NoveltyLiquidationDoc $doc Documento de liquidación.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canRejectPayment(NoveltyLiquidationDoc $doc, int $roleId): bool
    {
        return $doc->canRejectPayment()
            && $this->canOperateStep($roleId, NoveltyConstants::STATUS_AUTORIZACION_PAGO);
    }

    /**
     * @param \App\Model\Entity\NoveltyLiquidationDoc $doc Documento de liquidación.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canConfirmPayment(NoveltyLiquidationDoc $doc, int $roleId): bool
    {
        return $doc->canConfirmPayment()
            && $this->canOperateStep($roleId, NoveltyConstants::STATUS_VERIFICACION_PAGO);
    }
}

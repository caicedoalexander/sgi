<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\Domain\Novelty\PipelineStatus;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\NoveltyLiquidationGuard;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;

final class RevisionFirmasState implements NoveltyPipelineState
{
    private NoveltyLiquidationGuard $guard;

    /**
     * @param \App\Service\NoveltyLiquidationGuard|null $guard Liquidation advance guard.
     */
    public function __construct(?NoveltyLiquidationGuard $guard = null)
    {
        $this->guard = $guard ?? new NoveltyLiquidationGuard();
    }

    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::REVISION_FIRMAS;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::GDP;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::CONTABILIDAD;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        return ['Esta etapa solo avanza desde el documento de liquidación grupal.'];
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        $errors = [];

        if (!$this->guard->requiredSignaturesComplete((int)$doc->id)) {
            $errors[] = 'Todas las firmas requeridas (Contador y Coordinador) deben estar presentes para avanzar.';
        }

        // Cuando el tipo NO requiere revisión de firma del empleado, "Pasa para Pago"
        // se exige ya en esta etapa (no hay etapa GDP posterior que lo valide).
        if ($this->guard->firstMemberRequiresEmployeeSignatureReview((int)$doc->id) === false) {
            if ($doc->passes_for_payment === null) {
                $errors[] = 'Debe indicar si "Pasa para Pago".';
            }
        }

        return $errors;
    }
}

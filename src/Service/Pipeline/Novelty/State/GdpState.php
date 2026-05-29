<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\Domain\Novelty\PipelineStatus;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\NoveltyLiquidationGuard;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;

final class GdpState implements NoveltyPipelineState
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
        return PipelineStatus::GDP;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::TESORERIA;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::REVISION_FIRMAS;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        return ['Esta etapa solo avanza desde el documento de liquidación grupal.'];
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        $errors = [];

        if ($doc->passes_for_payment === null) {
            $errors[] = 'Debe indicar si "Pasa para Pago".';
        }

        if ($this->guard->workerSignaturePending((int)$doc->id)) {
            $errors[] = 'La firma del trabajador es requerida para avanzar.';
        }

        return $errors;
    }
}

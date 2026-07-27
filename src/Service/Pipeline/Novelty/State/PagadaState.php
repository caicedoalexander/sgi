<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\Domain\Novelty\PipelineStatus;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;

final class PagadaState implements NoveltyPipelineState
{
    /**
     * Estado terminal `pagada` de este State.
     *
     * @return \App\Constants\Domain\Novelty\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::PAGADA;
    }

    /**
     * Estado siguiente base del pipeline; delega en el enum. Null porque `pagada` es terminal.
     *
     * @return \App\Constants\Domain\Novelty\PipelineStatus|null
     */
    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    /**
     * Estado anterior del pipeline; delega en el enum. Null si es el primero.
     *
     * @return \App\Constants\Domain\Novelty\PipelineStatus|null
     */
    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    /**
     * La novedad ya está en el estado final; no admite avance.
     *
     * @param \App\Model\Entity\EmployeeNovelty $novelty Novedad individual.
     * @return array<string>
     */
    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        return ['La novedad ya está en el estado final.'];
    }

    /**
     * El documento de liquidación ya está en el estado final; no admite avance.
     *
     * @param \App\Model\Entity\NoveltyLiquidationDoc $doc Documento de liquidación grupal.
     * @return array<string>
     */
    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        return ['El documento ya está en el estado final.'];
    }
}

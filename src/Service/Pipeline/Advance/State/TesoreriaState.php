<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance\State;

use App\Constants\Domain\Advance\PipelineStatus;
use App\Model\Entity\AdvanceLegalization;
use App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState;

final class TesoreriaState implements AdvanceLegalizationPipelineState
{
    /**
     * Estado canónico tipado de este State.
     *
     * @return \App\Constants\Domain\Advance\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::TESORERIA;
    }

    /**
     * Estado siguiente natural en el avance lineal; null si es terminal o bifurcante.
     *
     * @return \App\Constants\Domain\Advance\PipelineStatus|null
     */
    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    /**
     * Estado anterior; null si es el primero o si la regresión está bloqueada.
     *
     * @return \App\Constants\Domain\Advance\PipelineStatus|null
     */
    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    /**
     * Sin requisitos de campo en este paso.
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización a validar.
     * @return array<string>
     */
    public function validateAdvance(AdvanceLegalization $leg): array
    {
        return [];
    }
}

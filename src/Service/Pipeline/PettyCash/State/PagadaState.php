<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\State;

use App\Constants\Domain\PettyCash\PipelineStatus;
use App\Model\Entity\PettyCashRecord;
use App\Service\Pipeline\PettyCash\PettyCashPipelineState;

final class PagadaState implements PettyCashPipelineState
{
    /**
     * Estado terminal `pagada` de este State.
     *
     * @return \App\Constants\Domain\PettyCash\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::PAGADA;
    }

    /**
     * Estado siguiente natural del pipeline; delega en el enum. Null porque `pagada` es terminal.
     *
     * @return \App\Constants\Domain\PettyCash\PipelineStatus|null
     */
    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    /**
     * Estado anterior del pipeline; delega en el enum. Null si es el primero o la regresión está bloqueada.
     *
     * @return \App\Constants\Domain\PettyCash\PipelineStatus|null
     */
    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    /**
     * Estado terminal `pagada`; no expone requisitos de avance.
     *
     * @param \App\Model\Entity\PettyCashRecord $record Registro de caja menor.
     * @return array<string>
     */
    public function validateAdvance(PettyCashRecord $record): array
    {
        return [];
    }
}

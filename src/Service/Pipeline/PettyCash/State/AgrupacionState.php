<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\State;

use App\Constants\Domain\PettyCash\PipelineStatus;
use App\Model\Entity\PettyCashRecord;
use App\Service\Pipeline\PettyCash\Guard\PettyCashGuard;
use App\Service\Pipeline\PettyCash\PettyCashPipelineState;

final class AgrupacionState implements PettyCashPipelineState
{
    private PettyCashGuard $guard;

    /**
     * @param \App\Service\Pipeline\PettyCash\Guard\PettyCashGuard|null $guard IO sobre las facturas hijas (stubbeable en tests).
     */
    public function __construct(?PettyCashGuard $guard = null)
    {
        $this->guard = $guard ?? new PettyCashGuard();
    }

    /**
     * Estado canónico `agrupacion` de este State.
     *
     * @return \App\Constants\Domain\PettyCash\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::AGRUPACION;
    }

    /**
     * Estado siguiente natural del pipeline; delega en el enum. Null si es terminal.
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
     * Requisitos de las facturas hijas agrupadas (DIAN + soporte) para avanzar; delega en el guard.
     *
     * @param \App\Model\Entity\PettyCashRecord $record Registro de caja menor.
     * @return array<string>
     */
    public function validateAdvance(PettyCashRecord $record): array
    {
        return $this->guard->childRequirements((int)$record->id)->toMessages();
    }
}

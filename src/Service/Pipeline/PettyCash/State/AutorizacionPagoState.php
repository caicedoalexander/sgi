<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\State;

use App\Constants\Domain\PettyCash\PipelineStatus;
use App\Model\Entity\PettyCashRecord;
use App\Service\Pipeline\PettyCash\PettyCashPipelineState;

final class AutorizacionPagoState implements PettyCashPipelineState
{
    /**
     * Estado canónico `autorizacion_pago` de este State.
     *
     * @return \App\Constants\Domain\PettyCash\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::AUTORIZACION_PAGO;
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
     * El avance desde Autorización de Pago se gestiona vía authorizePayment, no por advance: sin requisitos de campos.
     *
     * @param \App\Model\Entity\PettyCashRecord $record Registro de caja menor.
     * @return array<string>
     */
    public function validateAdvance(PettyCashRecord $record): array
    {
        // El avance desde Aut. Pago se gestiona vía authorizePayment, no
        // por advance. Sin requirements de campos a este nivel.
        return [];
    }
}

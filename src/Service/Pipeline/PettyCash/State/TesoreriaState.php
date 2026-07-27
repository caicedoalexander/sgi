<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\State;

use App\Constants\Domain\PettyCash\PipelineStatus;
use App\Model\Entity\PettyCashRecord;
use App\Service\Pipeline\PettyCash\PettyCashPipelineState;

final class TesoreriaState implements PettyCashPipelineState
{
    /**
     * Estado canónico `tesoreria` de este State.
     *
     * @return \App\Constants\Domain\PettyCash\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::TESORERIA;
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
     * El avance desde Tesorería requiere registrar un pago (bloqueado por el coordinador); sin requisitos de campos.
     *
     * @param \App\Model\Entity\PettyCashRecord $record Registro de caja menor.
     * @return array<string>
     */
    public function validateAdvance(PettyCashRecord $record): array
    {
        // El avance desde Tesorería NO se hace por advance directo;
        // requiere registrar un pago vía registerPayment. El coordinador
        // bloquea esa transición. Sin requirements de campos a este nivel.
        return [];
    }
}

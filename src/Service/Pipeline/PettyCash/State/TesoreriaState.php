<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\State;

use App\Constants\Domain\PettyCash\PipelineStatus;
use App\Model\Entity\PettyCashRecord;
use App\Service\Pipeline\PettyCash\PettyCashPipelineState;

final class TesoreriaState implements PettyCashPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::TESORERIA;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    public function validateAdvance(PettyCashRecord $record): array
    {
        // El avance desde Tesorería NO se hace por advance directo;
        // requiere registrar un pago vía registerPayment. El coordinador
        // bloquea esa transición. Sin requirements de campos a este nivel.
        return [];
    }
}

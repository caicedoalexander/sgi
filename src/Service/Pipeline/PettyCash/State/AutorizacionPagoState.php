<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\State;

use App\Constants\Domain\PettyCash\PipelineStatus;
use App\Model\Entity\PettyCashRecord;
use App\Service\Pipeline\PettyCash\PettyCashPipelineState;

final class AutorizacionPagoState implements PettyCashPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::AUTORIZACION_PAGO;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::VERIFICACION_PAGO;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::TESORERIA;
    }

    public function validateAdvance(PettyCashRecord $record): array
    {
        // El avance desde Aut. Pago se gestiona vía authorizePayment, no
        // por advanceStatus. Sin requirements de campos a este nivel.
        return [];
    }
}

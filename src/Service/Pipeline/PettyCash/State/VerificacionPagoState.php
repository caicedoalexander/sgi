<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\State;

use App\Constants\Domain\PettyCash\PipelineStatus;
use App\Model\Entity\PettyCashRecord;
use App\Service\Pipeline\PettyCash\PettyCashPipelineState;

final class VerificacionPagoState implements PettyCashPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::VERIFICACION_PAGO;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::PAGADA;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::AUTORIZACION_PAGO;
    }

    public function validateAdvance(PettyCashRecord $record): array
    {
        return ['La confirmación de pago se gestiona desde la sección de pagos.'];
    }
}

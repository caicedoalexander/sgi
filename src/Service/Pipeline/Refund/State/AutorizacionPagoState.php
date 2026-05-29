<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;

final class AutorizacionPagoState implements RefundPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::AUTORIZACION_PAGO;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::VERIFICACION_PAGO;
    }

    /**
     * BACKWARD_TRANSITIONS mapea autorizacion_pago -> tesoreria, pero la regresión
     * real está bloqueada de fábrica (canRegress() retorna false porque
     * el avance autorizacion_pago->pagada tampoco pasa por el coordinator).
     * Aquí declaramos previous = tesoreria por consistencia con BACKWARD_TRANSITIONS.
     */
    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::TESORERIA;
    }

    /**
     * @inheritDoc
     */
    public function validateAdvance(Refund $record): array
    {
        return ['La autorización de pago se gestiona desde la sección de pagos.'];
    }
}

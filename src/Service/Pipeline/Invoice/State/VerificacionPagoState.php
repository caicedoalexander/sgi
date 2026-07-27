<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\State;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Service\Pipeline\Invoice\InvoicePipelineState;

final class VerificacionPagoState implements InvoicePipelineState
{
    /**
     * Estado canónico tipado de este State.
     *
     * @return \App\Constants\Domain\Invoice\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::VERIFICACION_PAGO;
    }

    /**
     * Estado siguiente natural; null si es terminal.
     *
     * @return \App\Constants\Domain\Invoice\PipelineStatus|null
     */
    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    /**
     * Estado anterior; null si es el primero.
     *
     * @return \App\Constants\Domain\Invoice\PipelineStatus|null
     */
    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    /**
     * `_payment_executed` es un pseudo-requisito (no existe en la entidad Invoice),
     * usado solo para que el `TransitionValidator` siempre rechace el avance
     * automático desde este estado. La transición real
     * `verificacion_pago → pagada` se hace exclusivamente vía
     * `InvoicePaymentService::confirmPayment()`, invocado por la acción
     * `InvoicePaymentsController::confirmPayment` (botón "Pasar a Pagada").
     */
    public function validateAdvance(object $invoice): array
    {
        return ['_payment_executed' => 'La confirmación de pago se gestiona desde la sección de pagos.'];
    }
}

<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\State;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Service\InvoicePaymentService;
use App\Service\Pipeline\Invoice\InvoicePipelineState;

final class TesoreriaState implements InvoicePipelineState
{
    /**
     * @param \App\Service\InvoicePaymentService $paymentService Servicio de pagos de factura.
     */
    public function __construct(
        private readonly InvoicePaymentService $paymentService,
    ) {
    }

    /**
     * Estado canónico tipado de este State.
     *
     * @return \App\Constants\Domain\Invoice\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::TESORERIA;
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
     * Exige al menos un pago registrado pendiente de autorización antes de avanzar
     * a autorización de pago.
     *
     * @param object $invoice Factura a validar.
     * @return array<string, string>
     */
    public function validateAdvance(object $invoice): array
    {
        if (!$this->paymentService->hasPendingAuthorization((int)$invoice->id)) {
            return ['_has_pending_payment' => 'Debe registrar al menos un pago para avanzar a autorización'];
        }

        return [];
    }
}

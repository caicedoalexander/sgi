<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\State;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Service\InvoicePaymentService;
use App\Service\Pipeline\Invoice\InvoicePipelineState;

final class AutorizacionPagoState implements InvoicePipelineState
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
        return PipelineStatus::AUTORIZACION_PAGO;
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
     * Bloquea el avance a verificación de pago mientras exista algún pago
     * pendiente de autorizar por el Contador.
     *
     * @param object $invoice Factura a validar.
     * @return array<string, string>
     */
    public function validateAdvance(object $invoice): array
    {
        if ($this->paymentService->hasPendingAuthorization((int)$invoice->id)) {
            return ['_payment_authorized' => 'El pago pendiente debe ser autorizado por el Contador'];
        }

        return [];
    }
}

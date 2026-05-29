<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\State;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Service\InvoicePaymentService;
use App\Service\Pipeline\Invoice\InvoicePipelineState;

final class AutorizacionPagoState implements InvoicePipelineState
{
    public function __construct(
        private readonly InvoicePaymentService $paymentService,
    ) {
    }

    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::AUTORIZACION_PAGO;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    public function validateAdvance(object $invoice): array
    {
        if ($this->paymentService->hasPendingAuthorization((int)$invoice->id)) {
            return ['El pago pendiente debe ser autorizado por el Contador'];
        }

        return [];
    }

    public function getTransitionRules(): array
    {
        return [
            ['field' => '_payment_authorized', 'label' => 'El pago pendiente debe ser autorizado por el Contador'],
        ];
    }
}

<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\State;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Service\InvoicePaymentService;
use App\Service\Pipeline\Invoice\InvoicePipelineState;

final class TesoreriaState implements InvoicePipelineState
{
    public function __construct(
        private readonly InvoicePaymentService $paymentService,
    ) {
    }

    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::TESORERIA;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::AUTORIZACION_PAGO;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::CONTABILIDAD;
    }

    public function validateAdvance(object $invoice): array
    {
        if (!$this->paymentService->hasPendingAuthorization((int)$invoice->id)) {
            return ['Debe registrar al menos un pago para avanzar a autorización'];
        }

        return [];
    }

    public function getTransitionRules(): array
    {
        return [
            ['field' => '_has_pending_payment', 'label' => 'Debe registrar al menos un pago para avanzar a autorización'],
        ];
    }
}

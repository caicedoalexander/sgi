<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\State;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\RoleConstants;
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
        return PipelineStatus::VERIFICACION_PAGO;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::TESORERIA;
    }

    public function getRoleVisibility(): array
    {
        return [RoleConstants::TESORERIA, RoleConstants::CONTADOR, RoleConstants::ADMIN];
    }

    public function getAdvanceRoleVisibility(): array
    {
        return [
            RoleConstants::TESORERIA,
            RoleConstants::CONTADOR,
            RoleConstants::AUXILIAR_PERSONAL,
            RoleConstants::ASISTENTE_PERSONAL,
            RoleConstants::COORDINADOR_ADMIN,
            RoleConstants::ADMIN,
        ];
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

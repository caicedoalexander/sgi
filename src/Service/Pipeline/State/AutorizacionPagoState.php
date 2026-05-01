<?php
declare(strict_types=1);

namespace App\Service\Pipeline\State;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\InvoicePaymentService;
use App\Service\Pipeline\InvoicePipelineState;

final class AutorizacionPagoState implements InvoicePipelineState
{
    public function __construct(
        private readonly InvoicePaymentService $paymentService,
    ) {
    }

    public function getName(): string
    {
        return InvoiceConstants::STATUS_AUTORIZACION_PAGO;
    }

    public function getNext(): ?string
    {
        return InvoiceConstants::STATUS_PAGADA;
    }

    public function getPrevious(): ?string
    {
        return InvoiceConstants::STATUS_TESORERIA;
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

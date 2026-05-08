<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\State;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\RoleConstants;
use App\Service\Pipeline\Invoice\InvoicePipelineState;

final class VerificacionPagoState implements InvoicePipelineState
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

    public function getRoleVisibility(): array
    {
        return [RoleConstants::TESORERIA, RoleConstants::ADMIN];
    }

    public function getAdvanceRoleVisibility(): array
    {
        return [RoleConstants::TESORERIA, RoleConstants::ADMIN];
    }

    public function validateAdvance(object $invoice): array
    {
        return ['La confirmación de pago se gestiona desde la sección de pagos.'];
    }

    /**
     * `_payment_executed` es un pseudo-field (no existe en la entidad Invoice),
     * usado solo para que el `TransitionValidator` siempre rechace el avance
     * automático desde este estado. La transición real
     * `verificacion_pago → pagada` se hace exclusivamente vía
     * `InvoicePaymentService::confirmPayment()`, invocado por la acción
     * `InvoicePaymentsController::confirmPayment` (botón "Pasar a Pagada").
     */
    public function getTransitionRules(): array
    {
        return [
            ['field' => '_payment_executed', 'label' => 'Tesorería debe confirmar que el pago se ejecutó'],
        ];
    }
}

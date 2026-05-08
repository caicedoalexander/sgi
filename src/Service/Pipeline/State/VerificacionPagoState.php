<?php
declare(strict_types=1);

namespace App\Service\Pipeline\State;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\RoleConstants;
use App\Service\Pipeline\InvoicePipelineState;

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

    public function getTransitionRules(): array
    {
        return [
            ['field' => '_payment_executed', 'label' => 'Tesorería debe confirmar que el pago se ejecutó'],
        ];
    }
}

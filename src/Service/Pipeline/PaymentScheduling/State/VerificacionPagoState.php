<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling\State;

use App\Constants\Domain\PaymentScheduling\PipelineStatus;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState;

final class VerificacionPagoState implements PaymentSchedulingPipelineState
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

    public function validateAdvance(PaymentScheduling $scheduling): array
    {
        return ['La confirmación de pago se gestiona desde la sección de pagos.'];
    }
}

<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling\State;

use App\Constants\Domain\PaymentScheduling\PipelineStatus;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState;

final class VerificacionPagoState implements PaymentSchedulingPipelineState
{
    /**
     * Estado canónico `verificacion_pago` de este State.
     *
     * @return \App\Constants\Domain\PaymentScheduling\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::VERIFICACION_PAGO;
    }

    /**
     * Estado siguiente del pipeline; delega en el enum. Null si es terminal.
     *
     * @return \App\Constants\Domain\PaymentScheduling\PipelineStatus|null
     */
    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    /**
     * Estado anterior del pipeline; delega en el enum. Null si es el primero o la regresión está bloqueada.
     *
     * @return \App\Constants\Domain\PaymentScheduling\PipelineStatus|null
     */
    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    /**
     * La confirmación de pago se gestiona desde la sección de pagos, no por advance directo.
     *
     * @param \App\Model\Entity\PaymentScheduling $scheduling Programación de pagos.
     * @return array<string>
     */
    public function validateAdvance(PaymentScheduling $scheduling): array
    {
        return ['La confirmación de pago se gestiona desde la sección de pagos.'];
    }
}

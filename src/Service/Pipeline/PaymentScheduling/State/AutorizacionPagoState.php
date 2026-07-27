<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling\State;

use App\Constants\Domain\PaymentScheduling\PipelineStatus;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState;

final class AutorizacionPagoState implements PaymentSchedulingPipelineState
{
    /**
     * Estado canónico `autorizacion_pago` de este State.
     *
     * @return \App\Constants\Domain\PaymentScheduling\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::AUTORIZACION_PAGO;
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
     * Sin requisitos de campos para avanzar desde Autorización de Pago a este nivel.
     *
     * @param \App\Model\Entity\PaymentScheduling $scheduling Programación de pagos.
     * @return array<string>
     */
    public function validateAdvance(PaymentScheduling $scheduling): array
    {
        return [];
    }
}

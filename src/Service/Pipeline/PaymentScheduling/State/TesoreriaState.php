<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling\State;

use App\Constants\Domain\PaymentScheduling\PipelineStatus;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState;

final class TesoreriaState implements PaymentSchedulingPipelineState
{
    /**
     * Estado canónico `tesoreria` de este State.
     *
     * @return \App\Constants\Domain\PaymentScheduling\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::TESORERIA;
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
     * Sin requisitos de campos para avanzar desde Tesorería a este nivel.
     *
     * @param \App\Model\Entity\PaymentScheduling $scheduling Programación de pagos.
     * @return array<string>
     */
    public function validateAdvance(PaymentScheduling $scheduling): array
    {
        return [];
    }
}

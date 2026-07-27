<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling\State;

use App\Constants\Domain\PaymentScheduling\PipelineStatus;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState;

final class PagadaState implements PaymentSchedulingPipelineState
{
    /**
     * Estado terminal `pagada` de la programación de pagos.
     *
     * @return \App\Constants\Domain\PaymentScheduling\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::PAGADA;
    }

    /**
     * Estado siguiente según el enum; null porque `pagada` es terminal.
     *
     * @return \App\Constants\Domain\PaymentScheduling\PipelineStatus|null
     */
    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    /**
     * Estado anterior según el enum.
     *
     * @return \App\Constants\Domain\PaymentScheduling\PipelineStatus|null
     */
    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    /**
     * Sin requisitos de avance: `pagada` es el estado final, no avanza.
     *
     * @param \App\Model\Entity\PaymentScheduling $scheduling Programación de pagos.
     * @return array<string>
     */
    public function validateAdvance(PaymentScheduling $scheduling): array
    {
        return [];
    }
}

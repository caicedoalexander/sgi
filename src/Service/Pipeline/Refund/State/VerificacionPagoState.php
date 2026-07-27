<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;

final class VerificacionPagoState implements RefundPipelineState
{
    /**
     * Estado canónico `verificacion_pago` de este State.
     *
     * @return \App\Constants\Domain\Refund\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::VERIFICACION_PAGO;
    }

    /**
     * Estado siguiente del pipeline; delega en el enum. Null si es terminal.
     *
     * @return \App\Constants\Domain\Refund\PipelineStatus|null
     */
    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    /**
     * Estado anterior del pipeline; delega en el enum. Null si es el primero o la regresión está bloqueada.
     *
     * @return \App\Constants\Domain\Refund\PipelineStatus|null
     */
    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    /**
     * La confirmación de pago se gestiona desde la sección de pagos, no por advance directo.
     *
     * @param \App\Model\Entity\Refund $record Reintegro.
     * @return array<string>
     */
    public function validateAdvance(Refund $record): array
    {
        return ['La confirmación de pago se gestiona desde la sección de pagos.'];
    }
}

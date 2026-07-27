<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;

final class AutorizacionPagoState implements RefundPipelineState
{
    /**
     * Estado canónico `autorizacion_pago` de este State.
     *
     * @return \App\Constants\Domain\Refund\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::AUTORIZACION_PAGO;
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
     * El paso previo es tesoreria, pero la regresión real está bloqueada de
     * fábrica (canRegress() retorna false porque el avance
     * autorizacion_pago->pagada tampoco pasa por el coordinator).
     */
    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    /**
     * @inheritDoc
     */
    public function validateAdvance(Refund $record): array
    {
        return ['La autorización de pago se gestiona desde la sección de pagos.'];
    }
}

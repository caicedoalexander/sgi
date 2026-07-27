<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;

final class ContabilidadState implements RefundPipelineState
{
    /**
     * Estado canónico `contabilidad` de este State.
     *
     * @return \App\Constants\Domain\Refund\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::CONTABILIDAD;
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
     * @inheritDoc
     */
    public function validateAdvance(Refund $record): array
    {
        $errors = [];

        if (empty($record->accrued)) {
            $errors[] = 'El registro debe estar marcado como Causado.';
        }
        if (empty($record->ready_for_payment)) {
            $errors[] = 'Debe seleccionar "Lista para Pago".';
        }

        return $errors;
    }
}

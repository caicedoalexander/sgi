<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\State;

use App\Constants\Domain\PettyCash\PipelineStatus;
use App\Model\Entity\PettyCashRecord;
use App\Service\Pipeline\PettyCash\PettyCashPipelineState;

final class ContabilidadState implements PettyCashPipelineState
{
    /**
     * Estado canónico `contabilidad` de este State.
     *
     * @return \App\Constants\Domain\PettyCash\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::CONTABILIDAD;
    }

    /**
     * Estado siguiente natural del pipeline; delega en el enum. Null si es terminal.
     *
     * @return \App\Constants\Domain\PettyCash\PipelineStatus|null
     */
    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    /**
     * Estado anterior del pipeline; delega en el enum. Null si es el primero o la regresión está bloqueada.
     *
     * @return \App\Constants\Domain\PettyCash\PipelineStatus|null
     */
    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    /**
     * Requisitos de Contabilidad para avanzar: el registro debe estar Causado y marcado como "Lista para Pago".
     *
     * @param \App\Model\Entity\PettyCashRecord $record Registro de caja menor.
     * @return array<string>
     */
    public function validateAdvance(PettyCashRecord $record): array
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

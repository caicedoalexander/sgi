<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;

final class ContabilidadState implements RefundPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::CONTABILIDAD;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

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

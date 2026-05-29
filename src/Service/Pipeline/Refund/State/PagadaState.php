<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;

final class PagadaState implements RefundPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::PAGADA;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return null;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        // Pagada es terminal en este módulo: revertir implica deshacer pagos
        // materializados. Mantener `null` por seguridad.
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateAdvance(Refund $record): array
    {
        return ['Este registro ya está en su estado final.'];
    }
}

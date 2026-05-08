<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\State;

use App\Constants\Domain\PettyCash\PipelineStatus;
use App\Model\Entity\PettyCashRecord;
use App\Service\Pipeline\PettyCash\PettyCashPipelineState;

final class PagadaState implements PettyCashPipelineState
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
        // Pagada es terminal: regresar implicaría revertir invoice_payments
        // ya materializados y, posiblemente, una legalización ya iniciada.
        // Fuera del alcance del flujo estándar.
        return null;
    }

    public function validateAdvance(PettyCashRecord $record): array
    {
        return [];
    }
}

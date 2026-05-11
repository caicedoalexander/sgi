<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\State;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Service\Pipeline\Invoice\InvoicePipelineState;

final class PagadaState implements InvoicePipelineState
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
        // Pagada es terminal: revertir implicaría deshacer pagos ya
        // materializados y, en anticipos, una legalización ya iniciada.
        return null;
    }

    public function validateAdvance(object $invoice): array
    {
        return [];
    }

    public function getTransitionRules(): array
    {
        return [];
    }
}

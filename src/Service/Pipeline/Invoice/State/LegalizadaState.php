<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\State;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Service\Pipeline\Invoice\InvoicePipelineState;

final class LegalizadaState implements InvoicePipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::LEGALIZADA;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return null;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
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

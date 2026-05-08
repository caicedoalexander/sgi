<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling\State;

use App\Constants\Domain\PaymentScheduling\PipelineStatus;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState;

final class PagadaState implements PaymentSchedulingPipelineState
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
        // Pagada es terminal: revertir implicaría deshacer InvoicePayments
        // ya materializados sobre las facturas hijas.
        return null;
    }

    public function validateAdvance(PaymentScheduling $scheduling): array
    {
        return [];
    }
}

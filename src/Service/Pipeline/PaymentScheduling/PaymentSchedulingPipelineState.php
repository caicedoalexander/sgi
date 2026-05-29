<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling;

use App\Constants\Domain\PaymentScheduling\PipelineStatus;
use App\Model\Entity\PaymentScheduling;

/**
 * Polymorphic representation of one PaymentScheduling pipeline state.
 *
 * Each State knows its natural transitions (next/previous) and the field
 * requirements specific to advancing. Cross-state checks (role authorization,
 * payment application) are composed by the coordinator (PaymentSchedulingPipelineService).
 */
interface PaymentSchedulingPipelineState
{
    /** Estado canónico tipado. */
    public function getStatus(): PipelineStatus;

    /** Estado siguiente; null si terminal. */
    public function getNextStatus(): ?PipelineStatus;

    /** Estado anterior; null si es el primero o regresión bloqueada. */
    public function getPreviousStatus(): ?PipelineStatus;

    /**
     * Errors preventing advance from this state. Does not include cross-cutting
     * invariants like "must have at least one item" — those live in the coordinator.
     *
     * @return array<string>
     */
    public function validateAdvance(PaymentScheduling $scheduling): array;
}

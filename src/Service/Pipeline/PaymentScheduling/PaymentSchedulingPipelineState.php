<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling;

use App\Model\Entity\PaymentScheduling;

/**
 * Polymorphic representation of one PaymentScheduling pipeline state.
 *
 * Each State knows its natural transitions (next/previous) and the field
 * requirements specific to advancing. Cross-state checks (role authorization,
 * payment application) are composed by the coordinator (PaymentSchedulingService).
 */
interface PaymentSchedulingPipelineState
{
    /** Canonical name (e.g. 'borrador'). */
    public function getName(): string;

    /** Next state's name; null if terminal. */
    public function getNext(): ?string;

    /** Previous state's name; null if first or regression blocked. */
    public function getPrevious(): ?string;

    /**
     * Errors preventing advance from this state. Does not include cross-cutting
     * invariants like "must have at least one item" — those live in the coordinator.
     *
     * @return array<string>
     */
    public function validateAdvance(PaymentScheduling $scheduling): array;
}

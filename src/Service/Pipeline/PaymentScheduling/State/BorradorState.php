<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling\State;

use App\Constants\Domain\PaymentScheduling\PipelineStatus;
use App\Model\Entity\PaymentScheduling;
use App\Service\PaymentSchedulingGuard;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState;

final class BorradorState implements PaymentSchedulingPipelineState
{
    private PaymentSchedulingGuard $guard;

    /**
     * @param \App\Service\PaymentSchedulingGuard|null $guard Payment scheduling advance guard.
     */
    public function __construct(?PaymentSchedulingGuard $guard = null)
    {
        $this->guard = $guard ?? new PaymentSchedulingGuard();
    }

    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::BORRADOR;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::TESORERIA;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return null;
    }

    public function validateAdvance(PaymentScheduling $scheduling): array
    {
        if (!$this->guard->hasLinkedItems((int)$scheduling->id)) {
            return ['Debe vincular al menos una factura'];
        }

        return [];
    }
}

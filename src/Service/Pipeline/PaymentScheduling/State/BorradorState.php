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

    /**
     * Estado canónico `borrador` de este State.
     *
     * @return \App\Constants\Domain\PaymentScheduling\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::BORRADOR;
    }

    /**
     * Estado siguiente del pipeline; delega en el enum. Null si es terminal.
     *
     * @return \App\Constants\Domain\PaymentScheduling\PipelineStatus|null
     */
    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    /**
     * Estado anterior del pipeline; delega en el enum. Null si es el primero o la regresión está bloqueada.
     *
     * @return \App\Constants\Domain\PaymentScheduling\PipelineStatus|null
     */
    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    /**
     * Requisito para avanzar desde Borrador: debe tener al menos una factura vinculada; delega en el guard.
     *
     * @param \App\Model\Entity\PaymentScheduling $scheduling Programación de pagos.
     * @return array<string>
     */
    public function validateAdvance(PaymentScheduling $scheduling): array
    {
        if (!$this->guard->hasLinkedItems((int)$scheduling->id)) {
            return ['Debe vincular al menos una factura'];
        }

        return [];
    }
}

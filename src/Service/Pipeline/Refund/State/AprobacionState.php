<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;
use App\Service\RefundApprovalGuard;

final class AprobacionState implements RefundPipelineState
{
    private RefundApprovalGuard $guard;

    /**
     * @param \App\Service\RefundApprovalGuard|null $guard IO sobre aprobación de área y facturas hijas (stubbeable en tests).
     */
    public function __construct(?RefundApprovalGuard $guard = null)
    {
        $this->guard = $guard ?? new RefundApprovalGuard();
    }

    /**
     * Estado canónico `aprobacion` de este State.
     *
     * @return \App\Constants\Domain\Refund\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::APROBACION;
    }

    /**
     * Estado siguiente del pipeline; delega en el enum. Null si es terminal.
     *
     * @return \App\Constants\Domain\Refund\PipelineStatus|null
     */
    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    /**
     * Estado anterior del pipeline; delega en el enum. Null si es el primero o la regresión está bloqueada.
     *
     * @return \App\Constants\Domain\Refund\PipelineStatus|null
     */
    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    /**
     * Requisitos para avanzar desde Aprobación: aprobación de área del grupo completa y requisitos
     * (DIAN + soporte) de las facturas hijas; delega en el guard.
     *
     * @param \App\Model\Entity\Refund $record Reintegro.
     * @return array<string>
     */
    public function validateAdvance(Refund $record): array
    {
        $errors = [];
        if (!$this->guard->allApproved((int)$record->id)) {
            $errors[] = 'La aprobación de área del grupo está pendiente: todos los aprobadores deben aprobar.';
        }
        foreach ($this->guard->childRequirements((int)$record->id)->toMessages() as $msg) {
            $errors[] = $msg;
        }

        return $errors;
    }
}

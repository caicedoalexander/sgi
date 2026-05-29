<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;

final class TesoreriaState implements RefundPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::TESORERIA;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    /**
     * El avance tesoreria->aut_pago lo gestiona RefundPaymentService::registerPayment
     * (no pasa por advance). Si alguien intenta avanzar desde el coordinator,
     * devolvemos un mensaje claro.
     */
    public function validateAdvance(Refund $record): array
    {
        return ['Debe registrar un pago para avanzar desde Tesorería.'];
    }
}

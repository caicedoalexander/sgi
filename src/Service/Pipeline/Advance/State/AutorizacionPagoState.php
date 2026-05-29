<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance\State;

use App\Constants\Domain\Advance\PipelineStatus;
use App\Model\Entity\AdvanceLegalization;
use App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState;

final class AutorizacionPagoState implements AdvanceLegalizationPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::AUTORIZACION_PAGO;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    public function validateAdvance(AdvanceLegalization $leg): array
    {
        // El avance lo dispara InvoicePaymentService::authorizePayment →
        // closeOnRefundAuthorized. Sin requirements adicionales acá.
        return [];
    }
}

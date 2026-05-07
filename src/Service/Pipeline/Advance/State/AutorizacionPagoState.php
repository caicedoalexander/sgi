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
        return PipelineStatus::LEGALIZADA;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        // Si el pago de reintegro es rechazado, vuelve a Tesorería para
        // registrar uno nuevo.
        return PipelineStatus::TESORERIA;
    }

    public function validateAdvance(AdvanceLegalization $leg): array
    {
        // El avance lo dispara InvoicePaymentService::authorizePayment →
        // closeOnRefundAuthorized. Sin requirements adicionales acá.
        return [];
    }
}

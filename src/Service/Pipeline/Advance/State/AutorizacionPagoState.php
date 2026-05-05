<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance\State;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState;

final class AutorizacionPagoState implements AdvanceLegalizationPipelineState
{
    public function getName(): string
    {
        return AdvanceConstants::STATUS_AUTORIZACION_PAGO;
    }

    public function getNext(): ?string
    {
        return AdvanceConstants::STATUS_LEGALIZADA;
    }

    public function getPrevious(): ?string
    {
        // Si el pago de reintegro es rechazado, vuelve a Tesorería para
        // registrar uno nuevo.
        return AdvanceConstants::STATUS_TESORERIA;
    }

    public function validateAdvance(AdvanceLegalization $leg): array
    {
        // El avance lo dispara InvoicePaymentService::authorizePayment →
        // closeOnRefundAuthorized. Sin requirements adicionales acá.
        return [];
    }
}

<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\State;

use App\Constants\PettyCashConstants;
use App\Model\Entity\PettyCashRecord;
use App\Service\Pipeline\PettyCash\PettyCashPipelineState;

final class AutorizacionPagoState implements PettyCashPipelineState
{
    public function getName(): string
    {
        return PettyCashConstants::STATUS_AUTORIZACION_PAGO;
    }

    public function getNext(): ?string
    {
        return PettyCashConstants::STATUS_PAGADA;
    }

    public function getPrevious(): ?string
    {
        return PettyCashConstants::STATUS_TESORERIA;
    }

    public function validateAdvance(PettyCashRecord $record): array
    {
        // El avance desde Aut. Pago se gestiona vía authorizePayment, no
        // por advanceStatus. Sin requirements de campos a este nivel.
        return [];
    }
}

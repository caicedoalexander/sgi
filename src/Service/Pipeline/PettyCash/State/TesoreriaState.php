<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\State;

use App\Constants\PettyCashConstants;
use App\Model\Entity\PettyCashRecord;
use App\Service\Pipeline\PettyCash\PettyCashPipelineState;

final class TesoreriaState implements PettyCashPipelineState
{
    public function getName(): string
    {
        return PettyCashConstants::STATUS_TESORERIA;
    }

    public function getNext(): ?string
    {
        return PettyCashConstants::STATUS_AUTORIZACION_PAGO;
    }

    public function getPrevious(): ?string
    {
        return PettyCashConstants::STATUS_CONTABILIDAD;
    }

    public function validateAdvance(PettyCashRecord $record): array
    {
        // El avance desde Tesorería NO se hace por advanceStatus directo;
        // requiere registrar un pago vía registerPayment. El coordinador
        // bloquea esa transición. Sin requirements de campos a este nivel.
        return [];
    }
}

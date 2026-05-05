<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\State;

use App\Constants\PettyCashConstants;
use App\Model\Entity\PettyCashRecord;
use App\Service\Pipeline\PettyCash\PettyCashPipelineState;

final class PagadoState implements PettyCashPipelineState
{
    public function getName(): string
    {
        return PettyCashConstants::STATUS_PAGADO;
    }

    public function getNext(): ?string
    {
        return null;
    }

    public function getPrevious(): ?string
    {
        // Pagado es terminal: regresar implicaría revertir invoice_payments
        // ya materializados, fuera del alcance del flujo estándar.
        return null;
    }

    public function validateAdvance(PettyCashRecord $record): array
    {
        return [];
    }
}

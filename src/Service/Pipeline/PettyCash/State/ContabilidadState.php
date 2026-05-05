<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\State;

use App\Constants\PettyCashConstants;
use App\Model\Entity\PettyCashRecord;
use App\Service\Pipeline\PettyCash\PettyCashPipelineState;

final class ContabilidadState implements PettyCashPipelineState
{
    public function getName(): string
    {
        return PettyCashConstants::STATUS_CONTABILIDAD;
    }

    public function getNext(): ?string
    {
        return PettyCashConstants::STATUS_TESORERIA;
    }

    public function getPrevious(): ?string
    {
        return PettyCashConstants::STATUS_AGRUPACION;
    }

    public function validateAdvance(PettyCashRecord $record): array
    {
        $errors = [];

        if (empty($record->accrued)) {
            $errors[] = 'El registro debe estar marcado como Causado.';
        }
        if (empty($record->ready_for_payment)) {
            $errors[] = 'Debe seleccionar "Lista para Pago".';
        }

        return $errors;
    }
}

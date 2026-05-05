<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\State;

use App\Constants\PettyCashConstants;
use App\Model\Entity\PettyCashRecord;
use App\Service\Pipeline\PettyCash\PettyCashPipelineState;

final class AgrupacionState implements PettyCashPipelineState
{
    public function getName(): string
    {
        return PettyCashConstants::STATUS_AGRUPACION;
    }

    public function getNext(): ?string
    {
        return PettyCashConstants::STATUS_CONTABILIDAD;
    }

    public function getPrevious(): ?string
    {
        return null;
    }

    public function validateAdvance(PettyCashRecord $record): array
    {
        return [];
    }
}

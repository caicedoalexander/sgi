<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance\State;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState;

final class LegalizadaState implements AdvanceLegalizationPipelineState
{
    public function getName(): string
    {
        return AdvanceConstants::STATUS_LEGALIZADA;
    }

    public function getNext(): ?string
    {
        return null;
    }

    public function getPrevious(): ?string
    {
        return null;
    }

    public function validateAdvance(AdvanceLegalization $leg): array
    {
        return [];
    }
}

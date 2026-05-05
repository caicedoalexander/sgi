<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance\State;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState;

final class ContabilidadState implements AdvanceLegalizationPipelineState
{
    public function getName(): string
    {
        return AdvanceConstants::STATUS_CONTABILIDAD;
    }

    public function getNext(): ?string
    {
        // Contabilidad bifurca por acción discreta (markExact / registerShortage
        // / registerSurplus). No hay un "next" lineal.
        return null;
    }

    public function getPrevious(): ?string
    {
        return AdvanceConstants::STATUS_REVISION_FIRMAS;
    }

    public function validateAdvance(AdvanceLegalization $leg): array
    {
        return [];
    }
}

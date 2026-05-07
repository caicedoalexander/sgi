<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance\State;

use App\Constants\Domain\Advance\PipelineStatus;
use App\Model\Entity\AdvanceLegalization;
use App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState;

final class ContabilidadState implements AdvanceLegalizationPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::CONTABILIDAD;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        // Contabilidad bifurca por acción discreta (markExact / registerShortage
        // / registerSurplus). No hay un "next" lineal.
        return null;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::REVISION_FIRMAS;
    }

    public function validateAdvance(AdvanceLegalization $leg): array
    {
        return [];
    }
}

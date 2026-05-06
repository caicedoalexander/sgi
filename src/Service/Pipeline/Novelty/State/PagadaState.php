<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;

final class PagadaState implements NoveltyPipelineState
{
    public function getName(): string
    {
        return NoveltyConstants::STATUS_PAGADA;
    }

    public function getNext(): ?string
    {
        return null;
    }

    public function getPrevious(): ?string
    {
        return NoveltyConstants::STATUS_AUTORIZACION_PAGO;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        return ['La novedad ya está en el estado final.'];
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        return ['El documento ya está en el estado final.'];
    }
}

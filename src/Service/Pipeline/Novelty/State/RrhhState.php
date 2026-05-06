<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;

final class RrhhState implements NoveltyPipelineState
{
    public function getName(): string
    {
        return NoveltyConstants::STATUS_RRHH;
    }

    public function getNext(): ?string
    {
        return NoveltyConstants::STATUS_CONTABILIDAD;
    }

    public function getPrevious(): ?string
    {
        return NoveltyConstants::STATUS_APROBACION;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        if ($novelty->passes_payroll === null) {
            return ['Debe indicar si "Pasa a Nómina".'];
        }

        return [];
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        return ['Esta etapa no aplica a documentos de liquidación.'];
    }
}

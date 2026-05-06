<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;

final class AprobacionState implements NoveltyPipelineState
{
    public function getName(): string
    {
        return NoveltyConstants::STATUS_APROBACION;
    }

    public function getNext(): ?string
    {
        return NoveltyConstants::STATUS_RRHH;
    }

    public function getPrevious(): ?string
    {
        return null;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        $errors = [];

        if (empty($novelty->approver_id)) {
            $errors[] = 'Debe asignar un aprobador.';
        }
        if (!empty($novelty->area_approval) && $novelty->area_approval === NoveltyConstants::APPROVAL_REJECTED) {
            $errors[] = 'La novedad fue rechazada por el aprobador. Edite y reenvíe para aprobación.';
        }

        return $errors;
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        return ['Esta etapa no aplica a documentos de liquidación.'];
    }
}

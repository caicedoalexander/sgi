<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\Domain\Novelty\PipelineStatus;
use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;

final class AprobacionState implements NoveltyPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::APROBACION;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
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

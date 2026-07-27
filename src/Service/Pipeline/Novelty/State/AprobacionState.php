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
    /**
     * Estado canónico `aprobacion` de este State.
     *
     * @return \App\Constants\Domain\Novelty\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::APROBACION;
    }

    /**
     * Estado siguiente base del pipeline (sin saltos condicionales); delega en el enum. Null si es terminal.
     *
     * @return \App\Constants\Domain\Novelty\PipelineStatus|null
     */
    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    /**
     * Estado anterior del pipeline; delega en el enum. Null si es el primero.
     *
     * @return \App\Constants\Domain\Novelty\PipelineStatus|null
     */
    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    /**
     * Requisitos para avanzar una novedad individual desde Aprobación: debe tener
     * aprobador asignado y no haber sido rechazada por el área.
     *
     * @param \App\Model\Entity\EmployeeNovelty $novelty Novedad individual.
     * @return array<string>
     */
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

    /**
     * Aprobación no aplica al modo grupal (documento de liquidación); retorna el error correspondiente.
     *
     * @param \App\Model\Entity\NoveltyLiquidationDoc $doc Documento de liquidación grupal.
     * @return array<string>
     */
    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        return ['Esta etapa no aplica a documentos de liquidación.'];
    }
}

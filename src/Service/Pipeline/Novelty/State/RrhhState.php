<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\Domain\Novelty\PipelineStatus;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;

final class RrhhState implements NoveltyPipelineState
{
    /**
     * Estado canónico `rrhh` de este State.
     *
     * @return \App\Constants\Domain\Novelty\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::RRHH;
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
     * Para avanzar en RRHH la novedad individual debe tener definido "Pasa a Nómina".
     *
     * @param \App\Model\Entity\EmployeeNovelty $novelty Novedad individual.
     * @return array<string>
     */
    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        if ($novelty->passes_payroll === null) {
            return ['Debe indicar si "Pasa a Nómina".'];
        }

        return [];
    }

    /**
     * RRHH no aplica al modo grupal (documento de liquidación); retorna el error correspondiente.
     *
     * @param \App\Model\Entity\NoveltyLiquidationDoc $doc Documento de liquidación grupal.
     * @return array<string>
     */
    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        return ['Esta etapa no aplica a documentos de liquidación.'];
    }
}

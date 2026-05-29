<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\Domain\Novelty\PipelineStatus;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\NoveltyLiquidationGuard;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;

final class ContabilidadState implements NoveltyPipelineState
{
    private NoveltyLiquidationGuard $guard;

    /**
     * @param \App\Service\NoveltyLiquidationGuard|null $guard Liquidation advance guard.
     */
    public function __construct(?NoveltyLiquidationGuard $guard = null)
    {
        $this->guard = $guard ?? new NoveltyLiquidationGuard();
    }

    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::CONTABILIDAD;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::REVISION_FIRMAS;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::RRHH;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        if (empty($novelty->liquidation_doc_id)) {
            return ['La novedad debe estar asignada a un documento de liquidación.'];
        }

        return [];
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        if (!$this->guard->hasLiquidationDocument((int)$doc->id)) {
            return ['Debe subir el documento de liquidación antes de avanzar.'];
        }

        return [];
    }
}

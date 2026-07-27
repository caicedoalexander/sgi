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

    /**
     * Estado canónico `contabilidad` de este State.
     *
     * @return \App\Constants\Domain\Novelty\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::CONTABILIDAD;
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
     * Para avanzar en Contabilidad la novedad individual debe estar asignada a un documento de liquidación.
     *
     * @param \App\Model\Entity\EmployeeNovelty $novelty Novedad individual.
     * @return array<string>
     */
    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        if (empty($novelty->liquidation_doc_id)) {
            return ['La novedad debe estar asignada a un documento de liquidación.'];
        }

        return [];
    }

    /**
     * En Contabilidad el documento de liquidación debe estar subido antes de avanzar (verificado por el guard).
     *
     * @param \App\Model\Entity\NoveltyLiquidationDoc $doc Documento de liquidación grupal.
     * @return array<string>
     */
    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        if (!$this->guard->hasLiquidationDocument((int)$doc->id)) {
            return ['Debe subir el documento de liquidación antes de avanzar.'];
        }

        return [];
    }
}

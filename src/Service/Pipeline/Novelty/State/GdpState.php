<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\Domain\Novelty\PipelineStatus;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\NoveltyLiquidationGuard;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;

final class GdpState implements NoveltyPipelineState
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
     * Estado canónico `gdp` de este State.
     *
     * @return \App\Constants\Domain\Novelty\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::GDP;
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
     * GDP solo avanza desde el documento de liquidación grupal, no como novedad individual.
     *
     * @param \App\Model\Entity\EmployeeNovelty $novelty Novedad individual.
     * @return array<string>
     */
    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        return ['Esta etapa solo avanza desde el documento de liquidación grupal.'];
    }

    /**
     * Requisitos de GDP: debe estar definido "Pasa para Pago" y la firma del trabajador no puede estar pendiente.
     *
     * @param \App\Model\Entity\NoveltyLiquidationDoc $doc Documento de liquidación grupal.
     * @return array<string>
     */
    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        $errors = [];

        if ($doc->passes_for_payment === null) {
            $errors[] = 'Debe indicar si "Pasa para Pago".';
        }

        if ($this->guard->workerSignaturePending((int)$doc->id)) {
            $errors[] = 'La firma del trabajador es requerida para avanzar.';
        }

        return $errors;
    }
}

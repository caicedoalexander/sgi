<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\Domain\Novelty\PipelineStatus;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\NoveltyLiquidationGuard;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;

final class RevisionFirmasState implements NoveltyPipelineState
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
     * Estado canónico `revision_firmas` de este State.
     *
     * @return \App\Constants\Domain\Novelty\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::REVISION_FIRMAS;
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
     * Revisión de Firmas solo avanza desde el documento de liquidación grupal, no como novedad individual.
     *
     * @param \App\Model\Entity\EmployeeNovelty $novelty Novedad individual.
     * @return array<string>
     */
    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        return ['Esta etapa solo avanza desde el documento de liquidación grupal.'];
    }

    /**
     * Requisitos de Revisión de Firmas: firmas requeridas (Contador y Coordinador) completas y,
     * cuando el tipo no exige revisión de firma del empleado, "Pasa para Pago" definido.
     *
     * @param \App\Model\Entity\NoveltyLiquidationDoc $doc Documento de liquidación grupal.
     * @return array<string>
     */
    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        $errors = [];

        if (!$this->guard->requiredSignaturesComplete((int)$doc->id)) {
            $errors[] = 'Todas las firmas requeridas (Contador y Coordinador) deben estar presentes para avanzar.';
        }

        // Cuando el tipo NO requiere revisión de firma del empleado, "Pasa para Pago"
        // se exige ya en esta etapa (no hay etapa GDP posterior que lo valide).
        if ($this->guard->firstMemberRequiresEmployeeSignatureReview((int)$doc->id) === false) {
            if ($doc->passes_for_payment === null) {
                $errors[] = 'Debe indicar si "Pasa para Pago".';
            }
        }

        return $errors;
    }
}

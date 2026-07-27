<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance\State;

use App\Constants\Domain\Advance\PipelineStatus;
use App\Model\Entity\AdvanceLegalization;
use App\Service\AdvanceLegalizationGuard;
use App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState;

final class ValidacionState implements AdvanceLegalizationPipelineState
{
    private AdvanceLegalizationGuard $guard;

    /**
     * @param \App\Service\AdvanceLegalizationGuard|null $guard Advance legalization advance guard.
     */
    public function __construct(?AdvanceLegalizationGuard $guard = null)
    {
        $this->guard = $guard ?? new AdvanceLegalizationGuard();
    }

    /**
     * Estado canónico tipado de este State.
     *
     * @return \App\Constants\Domain\Advance\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::VALIDACION;
    }

    /**
     * Estado siguiente natural en el avance lineal; null si es terminal o bifurcante.
     *
     * @return \App\Constants\Domain\Advance\PipelineStatus|null
     */
    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    /**
     * Estado anterior; null si es el primero o si la regresión está bloqueada.
     *
     * @return \App\Constants\Domain\Advance\PipelineStatus|null
     */
    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    /**
     * Requisitos del paso Validación: al menos una factura vinculada y la relación
     * de facturas (PDF) adjunta.
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización a validar.
     * @return array<string>
     */
    public function validateAdvance(AdvanceLegalization $leg): array
    {
        $errors = [];
        $linked = $this->guard->linkedLegalizationInvoices((int)$leg->advance_invoice_id);

        if (count($linked) === 0) {
            $errors[] = 'Vincule al menos una factura antes de avanzar.';
        }

        if (!$this->guard->hasPendingRelationDocument((int)$leg->id)) {
            $errors[] = 'Debe adjuntar la relación de facturas (PDF).';
        }

        return $errors;
    }
}

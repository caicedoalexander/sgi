<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance\State;

use App\Constants\Domain\Advance\PipelineStatus;
use App\Model\Entity\AdvanceLegalization;
use App\Service\AdvanceLegalizationApprovalGuard;
use App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState;

/**
 * Estado `aprobacion` de la legalización: puerta de la aprobación de área en
 * lote. El avance a `revision_firmas` exige quórum (todos los aprobadores del
 * grupo en 'Aprobada') y los requisitos documentales de cada factura hija
 * (DIAN + soporte, según la policy de su doctype). El movimiento de
 * las hijas a invoice-contabilidad lo hace el verbo del coordinador
 * (AdvanceLegalizationService::moveToRevisionFirmas), no este State puro.
 */
final class AprobacionState implements AdvanceLegalizationPipelineState
{
    private AdvanceLegalizationApprovalGuard $guard;

    /**
     * @param \App\Service\AdvanceLegalizationApprovalGuard|null $guard Guard de la aprobación de área en lote; stubbeable en tests.
     */
    public function __construct(?AdvanceLegalizationApprovalGuard $guard = null)
    {
        $this->guard = $guard ?? new AdvanceLegalizationApprovalGuard();
    }

    /**
     * Estado canónico tipado de este State.
     *
     * @return \App\Constants\Domain\Advance\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::APROBACION;
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
     * Requisitos del paso Aprobación: quórum de aprobadores del grupo (todos en
     * 'Aprobada') y los requisitos documentales (DIAN + soporte) de cada factura
     * hija según la policy de su doctype.
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización a validar.
     * @return array<string>
     */
    public function validateAdvance(AdvanceLegalization $leg): array
    {
        $errors = [];
        if (!$this->guard->allApproved((int)$leg->id)) {
            $errors[] = 'La aprobación de área del grupo está pendiente: todos los aprobadores deben aprobar.';
        }
        foreach ($this->guard->childRequirements((int)$leg->advance_invoice_id)->toMessages() as $msg) {
            $errors[] = $msg;
        }

        return $errors;
    }
}

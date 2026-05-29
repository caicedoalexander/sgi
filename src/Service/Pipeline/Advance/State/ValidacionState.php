<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance\State;

use App\Constants\Domain\Advance\PipelineStatus;
use App\Constants\InvoiceConstants;
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

    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::VALIDACION;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::REVISION_FIRMAS;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return null;
    }

    public function validateAdvance(AdvanceLegalization $leg): array
    {
        $errors = [];
        $linked = $this->guard->linkedLegalizationInvoices((int)$leg->advance_invoice_id);

        if (count($linked) === 0) {
            $errors[] = 'Vincule al menos una factura antes de avanzar.';
        }

        // MA-006 — toda factura vinculada debe estar en CONTABILIDAD para que
        // LinkedInvoiceLegalizer pueda promoverla al cierre.
        foreach ($linked as $li) {
            if ($li->pipeline_status !== InvoiceConstants::STATUS_CONTABILIDAD) {
                $errors[] = 'Todas las facturas vinculadas deben estar en Contabilidad. '
                    . 'Falta: factura ' . ($li->invoice_number ?: '#' . $li->id);
                break;
            }
        }

        if (!$this->guard->hasPendingRelationDocument((int)$leg->id)) {
            $errors[] = 'Debe adjuntar la relación de facturas (PDF).';
        }

        return $errors;
    }
}

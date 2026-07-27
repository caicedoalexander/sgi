<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\State;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Service\Pipeline\Invoice\DocumentTypePolicyFactory;
use App\Service\Pipeline\Invoice\Guard\InvoiceGuard;
use App\Service\Pipeline\Invoice\InvoicePipelineState;

final class AprobacionState implements InvoicePipelineState
{
    private InvoiceGuard $guard;

    /**
     * @param \App\Service\Pipeline\Invoice\Guard\InvoiceGuard|null $guard IO sobre invoice_documents (stubbeable en tests).
     */
    public function __construct(?InvoiceGuard $guard = null)
    {
        $this->guard = $guard ?? new InvoiceGuard();
    }

    /**
     * Estado canónico tipado de este State.
     *
     * @return \App\Constants\Domain\Invoice\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::APROBACION;
    }

    /**
     * Estado siguiente natural; null si es terminal.
     *
     * @return \App\Constants\Domain\Invoice\PipelineStatus|null
     */
    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    /**
     * Estado anterior; null si es el primero.
     *
     * @return \App\Constants\Domain\Invoice\PipelineStatus|null
     */
    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    /**
     * Requisitos para avanzar de aprobación a contabilidad: todos los aprobadores
     * de área deben haber aprobado, la validación DIAN debe estar "Aprobada" (si
     * el doctype la exige) y debe existir al menos un soporte cargado (si el
     * doctype lo exige).
     *
     * @param object $invoice Factura a validar.
     * @return array<string, string>
     */
    public function validateAdvance(object $invoice): array
    {
        $documentType = $invoice->document_type ?? null;

        $errors = [];
        if (($invoice->area_approval ?? null) !== InvoiceConstants::APPROVAL_APPROVED) {
            $errors['area_approval'] = 'Todos los aprobadores deben haber aprobado';
        }
        if (
            DocumentTypePolicyFactory::requiresDianFor($documentType)
            && ($invoice->dian_validation ?? null) !== InvoiceConstants::DIAN_APPROVED
        ) {
            $errors['dian_validation'] = 'Validación DIAN debe ser "Aprobada"';
        }
        if (
            DocumentTypePolicyFactory::requiresSupportFor($documentType)
            && !empty($invoice->id)
            && !$this->guard->hasAnyDocument((int)$invoice->id)
        ) {
            $errors['support_document'] = 'Debe cargar al menos un soporte de la factura';
        }

        return $errors;
    }
}

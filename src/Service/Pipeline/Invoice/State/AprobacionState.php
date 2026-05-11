<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\State;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Service\Pipeline\Invoice\InvoicePipelineState;

final class AprobacionState implements InvoicePipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::APROBACION;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::CONTABILIDAD;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return null;
    }

    public function validateAdvance(object $invoice): array
    {
        $errors = [];
        if (($invoice->area_approval ?? null) !== InvoiceConstants::APPROVAL_APPROVED) {
            $errors[] = 'Todos los aprobadores deben haber aprobado';
        }
        if (($invoice->dian_validation ?? null) !== InvoiceConstants::DIAN_APPROVED) {
            $errors[] = 'Validación DIAN debe ser "Aprobada"';
        }

        return $errors;
    }

    public function getTransitionRules(): array
    {
        return [
            ['field' => 'area_approval',   'label' => 'Todos los aprobadores deben haber aprobado'],
            ['field' => 'dian_validation', 'label' => 'Validación DIAN debe ser "Aprobada"'],
        ];
    }
}

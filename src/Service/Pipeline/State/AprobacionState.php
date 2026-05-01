<?php
declare(strict_types=1);

namespace App\Service\Pipeline\State;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\Pipeline\InvoicePipelineState;

final class AprobacionState implements InvoicePipelineState
{
    public function getName(): string
    {
        return InvoiceConstants::STATUS_APROBACION;
    }

    public function getNext(): ?string
    {
        return InvoiceConstants::STATUS_CONTABILIDAD;
    }

    public function getPrevious(): ?string
    {
        return null;
    }

    public function getRoleVisibility(): array
    {
        return [RoleConstants::REGISTRO_REVISION, RoleConstants::ADMIN];
    }

    public function getAdvanceRoleVisibility(): array
    {
        return [
            RoleConstants::REGISTRO_REVISION,
            RoleConstants::AUXILIAR_PERSONAL,
            RoleConstants::ASISTENTE_PERSONAL,
            RoleConstants::COORDINADOR_ADMIN,
            RoleConstants::ADMIN,
        ];
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

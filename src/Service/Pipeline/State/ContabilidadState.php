<?php
declare(strict_types=1);

namespace App\Service\Pipeline\State;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\RoleConstants;
use App\Service\Pipeline\InvoicePipelineState;

final class ContabilidadState implements InvoicePipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::CONTABILIDAD;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::TESORERIA;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::APROBACION;
    }

    public function getRoleVisibility(): array
    {
        return [RoleConstants::CONTABILIDAD, RoleConstants::ADMIN];
    }

    public function getAdvanceRoleVisibility(): array
    {
        return [
            RoleConstants::CONTABILIDAD,
            RoleConstants::AUXILIAR_PERSONAL,
            RoleConstants::ASISTENTE_PERSONAL,
            RoleConstants::COORDINADOR_ADMIN,
            RoleConstants::ADMIN,
        ];
    }

    public function validateAdvance(object $invoice): array
    {
        $errors = [];
        if (!(bool)($invoice->accrued ?? false)) {
            $errors[] = 'La factura debe estar marcada como Causada';
        }
        $accrualDate = $invoice->accrual_date ?? null;
        if ($accrualDate === null || $accrualDate === '' || $accrualDate === false) {
            $errors[] = 'Fecha de Causación es requerida';
        }
        $readyForPayment = $invoice->ready_for_payment ?? null;
        if ($readyForPayment === null || $readyForPayment === '' || $readyForPayment === false) {
            $errors[] = 'Campo "Lista para Pago" es requerido';
        }

        return $errors;
    }

    public function getTransitionRules(): array
    {
        return [
            ['field' => 'accrued',           'label' => 'La factura debe estar marcada como Causada'],
            ['field' => 'accrual_date',      'label' => 'Fecha de Causación es requerida'],
            ['field' => 'ready_for_payment', 'label' => 'Campo "Lista para Pago" es requerido'],
        ];
    }
}

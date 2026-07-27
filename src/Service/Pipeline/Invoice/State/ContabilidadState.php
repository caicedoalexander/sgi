<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\State;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Service\Pipeline\Invoice\InvoicePipelineState;

final class ContabilidadState implements InvoicePipelineState
{
    /**
     * Estado canónico tipado de este State.
     *
     * @return \App\Constants\Domain\Invoice\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::CONTABILIDAD;
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
     * Requisitos para avanzar de contabilidad a tesorería: la factura debe estar
     * causada, con fecha de causación y marcada como "Lista para Pago".
     *
     * @param object $invoice Factura a validar.
     * @return array<string, string>
     */
    public function validateAdvance(object $invoice): array
    {
        $errors = [];
        if (!(bool)($invoice->accrued ?? false)) {
            $errors['accrued'] = 'La factura debe estar marcada como Causada';
        }
        $accrualDate = $invoice->accrual_date ?? null;
        if ($accrualDate === null || $accrualDate === '' || $accrualDate === false) {
            $errors['accrual_date'] = 'Fecha de Causación es requerida';
        }
        $readyForPayment = $invoice->ready_for_payment ?? null;
        if ($readyForPayment === null || $readyForPayment === '' || $readyForPayment === false) {
            $errors['ready_for_payment'] = 'Campo "Lista para Pago" es requerido';
        }

        return $errors;
    }
}

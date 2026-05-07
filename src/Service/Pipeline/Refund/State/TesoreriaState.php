<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;

final class TesoreriaState implements RefundPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::TESORERIA;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::AUTORIZACION_PAGO;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::CONTABILIDAD;
    }

    /**
     * El avance tesoreria->aut_pago lo gestiona RefundPaymentService::registerPayment
     * (no pasa por advanceStatus). Si alguien intenta avanzar desde el coordinator,
     * devolvemos un mensaje claro.
     */
    public function validateAdvance(Refund $record): array
    {
        return ['Debe registrar un pago para avanzar desde Tesorería.'];
    }

    /**
     * Bloqueo único de regresión en Refunds: tesoreria->contabilidad cuando ya
     * existe un pago bulk registrado en columnas. Anular o reasignar el pago
     * primero.
     */
    public function getRegressionLockMessage(Refund $record): ?string
    {
        if (!empty($record->payment_amount)) {
            return 'No se puede regresar a Contabilidad: existe un pago pendiente registrado.'
                . ' Anule o reasigne el pago primero.';
        }

        return null;
    }
}

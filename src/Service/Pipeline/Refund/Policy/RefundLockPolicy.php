<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\Policy;

use App\Constants\RefundConstants;
use App\Model\Entity\Refund;

/**
 * Policy que encapsula el único bloqueo de regresión de Reintegros:
 * tesoreria → contabilidad cuando ya existe un pago bulk registrado en columnas
 * (anular o reasignar el pago primero).
 *
 * Espejo de InvoiceLockPolicy. Antes esta regla vivía dentro de TesoreriaState,
 * violando la pureza del State (el canon mantiene los locks fuera de los States).
 */
final class RefundLockPolicy
{
    /**
     * Retorna el motivo que impide regresar el reintegro, o null si la regresión
     * está permitida.
     */
    public function getRegressionLockMessage(Refund $record): ?string
    {
        if (
            $record->status === RefundConstants::STATUS_TESORERIA
            && !empty($record->payment_amount)
        ) {
            return 'No se puede regresar a Contabilidad: existe un pago pendiente registrado.'
                . ' Anule o reasigne el pago primero.';
        }

        return null;
    }
}

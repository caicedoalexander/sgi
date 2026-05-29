<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\PettyCashConstants;
use App\Model\Entity\PettyCashRecord;

/**
 * Policy que encapsula el único bloqueo de regresión de Caja Menor:
 * tesoreria → contabilidad cuando ya existe un pago registrado (anular o
 * reasignar el pago primero).
 *
 * Espejo de InvoiceLockPolicy / RefundLockPolicy. Antes esta regla vivía inline
 * en PettyCashPipelineService; extraerla unifica el patrón con sus módulos hermanos.
 */
final class PettyCashLockPolicy
{
    /**
     * Retorna el motivo que impide regresar el registro, o null si la regresión
     * está permitida.
     */
    public function getRegressionLockMessage(PettyCashRecord $record): ?string
    {
        if (
            $record->status === PettyCashConstants::STATUS_TESORERIA
            && !empty($record->payment_amount)
        ) {
            return 'No se puede regresar a Contabilidad: existe un pago pendiente registrado.'
                . ' Anule o reasigne el pago primero.';
        }

        return null;
    }
}

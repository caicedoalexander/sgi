<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\PaymentSchedulingConstants;
use Cake\ORM\TableRegistry;

/**
 * Consultas de soporte para los bloqueos de edición/regresión del pipeline de
 * facturas.
 *
 * Encapsula el acceso a BD (pagos vinculados a una programación ya pagada) que
 * antes vivía inline dentro de InvoiceLockPolicy, manteniendo la Policy pura
 * (espejo de PaymentSchedulingGuard / AdvanceLegalizationGuard).
 */
class InvoiceLockGuard
{
    /**
     * True si la factura tiene al menos un pago vinculado a una programación de
     * pago ya en estado "pagada".
     */
    public function hasPaidSchedulingLink(int $invoiceId): bool
    {
        return TableRegistry::getTableLocator()->get('InvoicePayments')
            ->find()
            ->matching('PaymentSchedulings', function ($q) {
                return $q->where([
                    'PaymentSchedulings.pipeline_status' => PaymentSchedulingConstants::STATUS_PAGADA,
                ]);
            })
            ->where(['InvoicePayments.invoice_id' => $invoiceId])
            ->count() > 0;
    }
}

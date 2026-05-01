<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\PaymentSchedulingConstants;
use Cake\ORM\TableRegistry;

/**
 * Policy que encapsula los bloqueos de edición y regresión de una factura
 * derivados de petty cash y de programaciones de pago ya pagadas.
 *
 * Las reglas dependientes del document_type (Anticipo con legalización iniciada)
 * NO viven aquí — las aporta DocumentTypePolicy::getRegressionLockReason().
 */
final class InvoiceLockPolicy
{
    /**
     * Returns true if the invoice is linked to a Petty Cash record.
     */
    public function isLockedByPettyCash(object $invoice): bool
    {
        return !empty($invoice->petty_cash_record_id ?? null);
    }

    /**
     * Returns true if the invoice has any payment linked to a payment
     * scheduling already in "pagada" state.
     */
    public function isLockedByPaidScheduling(int $invoiceId): bool
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        return $paymentsTable->find()
            ->matching('PaymentSchedulings', function ($q) {
                return $q->where([
                    'PaymentSchedulings.pipeline_status' => PaymentSchedulingConstants::STATUS_PAGADA,
                ]);
            })
            ->where(['InvoicePayments.invoice_id' => $invoiceId])
            ->count() > 0;
    }

    /**
     * Returns a human-readable reason if the invoice is locked for editing,
     * or null otherwise. Lock priority: petty cash → scheduling.
     */
    public function getEditLockMessage(object $invoice): ?string
    {
        if ($this->isLockedByPettyCash($invoice)) {
            return 'Factura bloqueada: pertenece al registro de Caja Menor.';
        }
        if (!empty($invoice->id) && $this->isLockedByPaidScheduling((int)$invoice->id)) {
            return 'Factura bloqueada: tiene pagos de una programación ya pagada.';
        }

        return null;
    }

    /**
     * Returns a human-readable reason if the invoice cannot be regressed
     * by reglas no-doctype (rejection, petty cash, paid scheduling).
     * El bloqueo por Anticipo con legalización iniciada lo aporta DocumentTypePolicy.
     */
    public function getRegressionLockMessage(object $invoice): ?string
    {
        if (($invoice->area_approval ?? null) === InvoiceConstants::APPROVAL_REJECTED) {
            return "Factura rechazada. Use 'Reiniciar flujo' para reactivarla.";
        }
        if ($this->isLockedByPettyCash($invoice)) {
            return 'Factura bloqueada: pertenece a un registro de Caja Menor.';
        }
        if (!empty($invoice->id) && $this->isLockedByPaidScheduling((int)$invoice->id)) {
            return 'Factura bloqueada: tiene pagos en una programación ya pagada.';
        }

        return null;
    }
}

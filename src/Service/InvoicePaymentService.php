<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use Cake\ORM\TableRegistry;

class InvoicePaymentService
{
    /**
     * Recalcula payment_status y full_payment_date de una factura
     * basándose en los pagos autorizados.
     *
     * Retorna true si la factura fue guardada correctamente.
     */
    public function recalculatePaymentStatus(int $invoiceId): bool
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        $invoice = $invoicesTable->get($invoiceId);

        $authorizedPayments = $paymentsTable->find()
            ->where([
                'invoice_id' => $invoiceId,
                'authorized' => true,
            ])
            ->order(['payment_date' => 'ASC'])
            ->all();

        $totalPaid = 0.0;
        $lastPaymentDate = null;
        foreach ($authorizedPayments as $payment) {
            $totalPaid += (float)$payment->amount;
            $lastPaymentDate = $payment->payment_date;
        }

        if ($totalPaid >= (float)$invoice->amount && $totalPaid > 0) {
            $invoice->payment_status = InvoiceConstants::PAYMENT_FULL;
            $invoice->full_payment_date = $lastPaymentDate;
        } elseif ($totalPaid > 0) {
            $invoice->payment_status = InvoiceConstants::PAYMENT_PARTIAL;
            $invoice->full_payment_date = null;
        } else {
            $invoice->payment_status = null;
            $invoice->full_payment_date = null;
        }

        return (bool)$invoicesTable->save($invoice);
    }

    /**
     * Obtiene el saldo pendiente de pago de una factura.
     */
    public function getPendingBalance(int $invoiceId): float
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        $invoice = $invoicesTable->get($invoiceId);

        $totalPaid = (float)$paymentsTable->find()
            ->where([
                'invoice_id' => $invoiceId,
                'authorized' => true,
            ])
            ->sumOf('amount');

        return max(0, (float)$invoice->amount - $totalPaid);
    }

    /**
     * Verifica si hay un pago pendiente de autorización para la factura.
     */
    public function hasPendingAuthorization(int $invoiceId): bool
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        return $paymentsTable->exists([
            'invoice_id' => $invoiceId,
            'authorized' => false,
            'payment_scheduling_id IS' => null, // Solo pagos individuales
        ]);
    }

    /**
     * Autoriza un pago individual y recalcula el estado de la factura.
     * Retorna ['success' => bool, 'paymentStatus' => string|null]
     */
    public function authorizePayment(int $paymentId, int $authorizedBy): array
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $payment = $paymentsTable->get($paymentId);

        $payment->authorized = true;
        $payment->authorized_by = $authorizedBy;
        $payment->authorized_date = date('Y-m-d');

        if (!$paymentsTable->save($payment)) {
            return ['success' => false, 'paymentStatus' => null];
        }

        $this->recalculatePaymentStatus($payment->invoice_id);

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoicesTable->get($payment->invoice_id);

        return [
            'success' => true,
            'paymentStatus' => $invoice->payment_status,
        ];
    }
}

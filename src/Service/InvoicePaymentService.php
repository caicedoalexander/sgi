<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use Cake\ORM\TableRegistry;

class InvoicePaymentService
{
    private InvoiceHistoryService $historyService;

    public function __construct(?InvoiceHistoryService $historyService = null)
    {
        $this->historyService = $historyService ?? new InvoiceHistoryService();
    }

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
            ->all()
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
     * Autoriza un pago individual, recalcula estado, y maneja transiciones de pipeline.
     * Registra historial para los cambios de estado.
     */
    public function authorizePayment(int $paymentId, int $authorizedBy): array
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $payment = $paymentsTable->get($paymentId);

        $payment->authorized = true;
        $payment->authorized_by = $authorizedBy;
        $payment->authorized_date = date('Y-m-d');

        if (!$paymentsTable->save($payment)) {
            return ['success' => false, 'paymentStatus' => null, 'newPipelineStatus' => null];
        }

        $this->recalculatePaymentStatus($payment->invoice_id);

        $invoice = $invoicesTable->get($payment->invoice_id);
        $previousStatus = $invoice->pipeline_status;
        $newPipelineStatus = null;

        if ($invoice->payment_status === InvoiceConstants::PAYMENT_FULL) {
            $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
            $newPipelineStatus = InvoiceConstants::STATUS_PAGADA;
        } else {
            $invoice->pipeline_status = InvoiceConstants::STATUS_TESORERIA;
            $newPipelineStatus = InvoiceConstants::STATUS_TESORERIA;
        }

        $invoicesTable->save($invoice);

        $this->historyService->recordStatusChange(
            $invoice->id,
            $previousStatus,
            $newPipelineStatus,
            $authorizedBy,
        );

        return [
            'success' => true,
            'paymentStatus' => $invoice->payment_status,
            'newPipelineStatus' => $newPipelineStatus,
        ];
    }

    /**
     * Registra un nuevo pago y avanza la factura a autorizacion_pago.
     * Registra historial para el cambio de estado del pipeline.
     */
    public function registerPayment(int $invoiceId, array $paymentData, int $createdBy): ServiceResult
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        $invoice = $invoicesTable->get($invoiceId);
        $currentStatus = $invoice->pipeline_status;

        $payment = $paymentsTable->newEntity([
            'invoice_id' => $invoiceId,
            'banking_entity_id' => $paymentData['banking_entity_id'] ?? null,
            'amount' => $paymentData['amount'] ?? null,
            'payment_date' => $paymentData['payment_date'] ?? null,
            'created_by' => $createdBy,
        ]);

        if (!$paymentsTable->save($payment)) {
            $errors = [];
            foreach ($payment->getErrors() as $field => $fieldErrors) {
                foreach ($fieldErrors as $msg) {
                    $errors[] = "$field: $msg";
                }
            }

            return ServiceResult::fail('No se pudo registrar el pago.' . (!empty($errors) ? ' ' . implode(', ', $errors) : ''));
        }

        $invoice->pipeline_status = InvoiceConstants::STATUS_AUTORIZACION_PAGO;
        $invoicesTable->save($invoice);

        $this->historyService->recordStatusChange(
            $invoiceId,
            $currentStatus,
            InvoiceConstants::STATUS_AUTORIZACION_PAGO,
            $createdBy,
        );

        return ServiceResult::ok('Pago registrado. La factura pasó a Autorización de Pago.');
    }

    /**
     * Rechaza (elimina) un pago pendiente y devuelve la factura a tesorería.
     * Registra historial para el cambio de estado.
     */
    public function rejectPayment(int $paymentId, int $rejectedBy): ServiceResult
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $payment = $paymentsTable->get($paymentId);

        if ($payment->authorized) {
            return ServiceResult::fail('No se puede rechazar un pago ya autorizado.');
        }

        $invoiceId = $payment->invoice_id;
        $invoice = $invoicesTable->get($invoiceId);
        $previousStatus = $invoice->pipeline_status;

        if (!$paymentsTable->delete($payment)) {
            return ServiceResult::fail('No se pudo rechazar el pago.');
        }

        $invoice->pipeline_status = InvoiceConstants::STATUS_TESORERIA;
        $invoicesTable->save($invoice);

        $this->historyService->recordStatusChange(
            $invoiceId,
            $previousStatus,
            InvoiceConstants::STATUS_TESORERIA,
            $rejectedBy,
        );

        return ServiceResult::ok('Pago rechazado. Factura devuelta a Tesorería.');
    }
}

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
                'status' => InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
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
                'status' => InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
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
            'status' => InvoiceConstants::PAYMENT_RECORD_PENDING,
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
        $payment->status = InvoiceConstants::PAYMENT_RECORD_AUTHORIZED;
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
     * Registra un nuevo pago. Si $advanceAfter es true, avanza la factura a
     * autorizacion_pago en la misma transacción; si false, la factura se
     * mantiene en su estado actual (útil para registrar varios pagos parciales).
     */
    public function registerPayment(
        int $invoiceId,
        array $paymentData,
        int $createdBy,
        bool $advanceAfter = true
    ): ServiceResult {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        $invoice = $invoicesTable->get($invoiceId);
        $currentStatus = $invoice->pipeline_status;

        $connection = $paymentsTable->getConnection();

        return $connection->transactional(function () use (
            $paymentsTable,
            $invoicesTable,
            $invoice,
            $invoiceId,
            $paymentData,
            $createdBy,
            $currentStatus,
            $advanceAfter
        ) {
            $payment = $paymentsTable->newEntity([
                'invoice_id' => $invoiceId,
                'banking_entity_id' => $paymentData['banking_entity_id'] ?? null,
                'amount' => $paymentData['amount'] ?? null,
                'payment_date' => $paymentData['payment_date'] ?? null,
                'status' => InvoiceConstants::PAYMENT_RECORD_PENDING,
                'authorized' => false,
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

            if (!$advanceAfter) {
                return ServiceResult::ok('Pago registrado. La factura permanece en el estado actual.');
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
        });
    }

    /**
     * Rechaza un pago pendiente marcando status=rejected con motivo,
     * y devuelve la factura a tesorería. No elimina el registro.
     */
    public function rejectPayment(int $paymentId, int $rejectedBy, string $reason): ServiceResult
    {
        if (trim($reason) === '') {
            return ServiceResult::fail('El motivo de rechazo es obligatorio.');
        }

        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $payment = $paymentsTable->get($paymentId);

        if ($payment->status === InvoiceConstants::PAYMENT_RECORD_AUTHORIZED) {
            return ServiceResult::fail('No se puede rechazar un pago ya autorizado.');
        }

        $invoiceId = $payment->invoice_id;
        $invoice = $invoicesTable->get($invoiceId);
        $previousStatus = $invoice->pipeline_status;

        $payment->status = InvoiceConstants::PAYMENT_RECORD_REJECTED;
        $payment->rejection_reason = $reason;

        if (!$paymentsTable->save($payment)) {
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

    /**
     * Edita un pago pendiente. Requiere motivo obligatorio, que queda
     * registrado como observación de la factura. Los cambios por campo
     * se asientan en invoice_histories.
     */
    public function editPayment(int $paymentId, array $data, string $reason, int $userId): ServiceResult
    {
        if (trim($reason) === '') {
            return ServiceResult::fail('La observación es obligatoria.');
        }

        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $payment = $paymentsTable->get($paymentId);

        if ($payment->status === InvoiceConstants::PAYMENT_RECORD_AUTHORIZED) {
            return ServiceResult::fail('No se puede editar un pago autorizado.');
        }
        if ($payment->status === InvoiceConstants::PAYMENT_RECORD_REJECTED) {
            return ServiceResult::fail('No se puede editar un pago rechazado.');
        }

        $allowed = array_intersect_key($data, array_flip(['banking_entity_id', 'amount', 'payment_date']));
        if (empty($allowed)) {
            return ServiceResult::fail('No se recibieron datos a modificar.');
        }

        $connection = $paymentsTable->getConnection();

        return $connection->transactional(function () use (
            $paymentsTable,
            $payment,
            $allowed,
            $reason,
            $userId
        ) {
            $changes = [];
            foreach ($allowed as $field => $newValue) {
                $oldValue = $payment->get($field);
                $oldNorm = $oldValue instanceof \DateTimeInterface ? $oldValue->format('Y-m-d') : $oldValue;
                $newNorm = $newValue;
                if ($field === 'payment_date' && is_string($newNorm) && $newNorm !== '') {
                    $newNorm = date('Y-m-d', strtotime($newNorm));
                }
                if ((string)$oldNorm !== (string)$newNorm) {
                    $changes[$field] = ['old' => $oldNorm, 'new' => $newNorm];
                }
            }

            if (empty($changes)) {
                return ServiceResult::fail('No hay cambios por aplicar.');
            }

            $paymentsTable->patchEntity($payment, $allowed, [
                'fields' => ['banking_entity_id', 'amount', 'payment_date'],
            ]);

            if (!$paymentsTable->save($payment)) {
                return ServiceResult::fail('No se pudo guardar el pago.');
            }

            $historiesTable = TableRegistry::getTableLocator()->get('InvoiceHistories');
            foreach ($changes as $field => $vals) {
                $historiesTable->save($historiesTable->newEntity([
                    'invoice_id' => $payment->invoice_id,
                    'user_id' => $userId,
                    'field_changed' => 'payment.' . $field,
                    'old_value' => $vals['old'] !== null ? (string)$vals['old'] : null,
                    'new_value' => $vals['new'] !== null ? (string)$vals['new'] : null,
                ]));
            }

            $observationsTable = TableRegistry::getTableLocator()->get('InvoiceObservations');
            $observationsTable->save($observationsTable->newEntity([
                'invoice_id' => $payment->invoice_id,
                'user_id' => $userId,
                'message' => 'Edición de pago #' . $payment->id . ': ' . $reason,
            ]));

            return ServiceResult::ok('Pago actualizado.');
        });
    }

}

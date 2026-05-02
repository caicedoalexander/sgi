<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Event\InvoicePaidEvent;
use App\Event\InvoiceRefundAuthorizedEvent;
use App\Event\InvoiceRefundRejectedEvent;
use App\Service\Pipeline\DocumentTypePolicyFactory;
use Cake\Event\Event;
use Cake\Event\EventManagerInterface;
use Cake\ORM\TableRegistry;
use Cake\Utility\Text;
use DateTimeInterface;
use PDOException;

class InvoicePaymentService
{
    public function __construct(
        private readonly InvoiceHistoryService $historyService,
        private readonly DocumentTypePolicyFactory $docTypePolicies,
        private readonly EventManagerInterface $events,
    ) {
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
     * Registra historial para los cambios de estado. Todo el flujo (pago, recálculo,
     * actualización de pipeline, historial y eventos de dominio) ocurre dentro de una
     * sola transacción para evitar inconsistencias parciales.
     *
     * Plan 5: las llamadas directas a AdvanceLegalizationService se reemplazan por
     * dispatch de InvoiceRefundAuthorizedEvent (cuando is_refund) y InvoicePaidEvent
     * (cuando la factura quedó en pagada). Si un subscriber falla, lanza
     * ListenerFailedException → rollback de toda la operación.
     */
    public function authorizePayment(int $paymentId, int $authorizedBy): array
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $connection = $paymentsTable->getConnection();

        $result = $connection->transactional(function () use (
            $paymentsTable,
            $invoicesTable,
            $paymentId,
            $authorizedBy,
        ) {
            $payment = $paymentsTable->get($paymentId);

            $payment->authorized = true;
            $payment->status = InvoiceConstants::PAYMENT_RECORD_AUTHORIZED;
            $payment->authorized_by = $authorizedBy;
            $payment->authorized_date = date('Y-m-d');

            if (!$paymentsTable->save($payment)) {
                return false; // → rollback
            }

            $this->recalculatePaymentStatus($payment->invoice_id);

            $invoice = $invoicesTable->get($payment->invoice_id);
            $previousStatus = $invoice->pipeline_status;

            $newPipelineStatus = $invoice->payment_status === InvoiceConstants::PAYMENT_FULL
                ? InvoiceConstants::STATUS_PAGADA
                : InvoiceConstants::STATUS_TESORERIA;

            $invoice->pipeline_status = $newPipelineStatus;
            $invoicesTable->save($invoice);

            $this->historyService->recordStatusChange(
                $invoice->id,
                $previousStatus,
                $newPipelineStatus,
                $authorizedBy,
            );

            if ((bool)($payment->is_refund ?? false)) {
                $this->events->dispatch(new Event(
                    'Invoice.refundAuthorized',
                    null,
                    ['payload' => new InvoiceRefundAuthorizedEvent($payment, $authorizedBy)],
                ));
            }

            if ($invoice->pipeline_status === InvoiceConstants::STATUS_PAGADA) {
                $this->events->dispatch(new Event(
                    'Invoice.paid',
                    null,
                    ['payload' => new InvoicePaidEvent($invoice, $authorizedBy)],
                ));
            }

            return [
                'success' => true,
                'paymentStatus' => $invoice->payment_status,
                'newPipelineStatus' => $newPipelineStatus,
            ];
        });

        return $result === false
            ? ['success' => false, 'paymentStatus' => null, 'newPipelineStatus' => null]
            : $result;
    }

    /**
     * Registra un nuevo pago y avanza la factura a autorizacion_pago en la
     * misma transacción.
     */
    public function registerPayment(
        int $invoiceId,
        array $paymentData,
        int $createdBy,
    ): ServiceResult {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        $invoice = $invoicesTable->get($invoiceId);
        $currentStatus = $invoice->pipeline_status;

        if (!empty($paymentData['is_refund']) && !$this->docTypePolicies->for($invoice->document_type)->allowsRefundPayments()) {
            return ServiceResult::fail('is_refund solo es válido en pagos de Anticipos.');
        }

        $idempotencyKey = isset($paymentData['idempotency_key']) && trim((string)$paymentData['idempotency_key']) !== ''
            ? trim((string)$paymentData['idempotency_key'])
            : Text::uuid();

        // Short-circuit: si ya existe un pago con esta key, devolver éxito apuntando
        // a esa fila — operación repetida, idempotente.
        $existing = $paymentsTable->find()
            ->where(['idempotency_key' => $idempotencyKey])
            ->first();
        if ($existing !== null) {
            return ServiceResult::ok('Pago ya registrado (operación repetida).');
        }

        $connection = $paymentsTable->getConnection();

        return $connection->transactional(function () use (
            $paymentsTable,
            $invoicesTable,
            $invoice,
            $invoiceId,
            $paymentData,
            $createdBy,
            $currentStatus,
            $idempotencyKey,
        ) {
            $payment = $paymentsTable->newEntity([
                'invoice_id' => $invoiceId,
                'banking_entity_id' => $paymentData['banking_entity_id'] ?? null,
                'amount' => $paymentData['amount'] ?? null,
                'payment_date' => $paymentData['payment_date'] ?? null,
                'status' => InvoiceConstants::PAYMENT_RECORD_PENDING,
                'authorized' => false,
                'created_by' => $createdBy,
                'idempotency_key' => $idempotencyKey,
            ]);

            try {
                if (!$paymentsTable->save($payment)) {
                    $errors = [];
                    foreach ($payment->getErrors() as $field => $fieldErrors) {
                        foreach ($fieldErrors as $msg) {
                            $errors[] = "$field: $msg";
                        }
                    }

                    return ServiceResult::fail('No se pudo registrar el pago.' . (!empty($errors) ? ' ' . implode(', ', $errors) : ''));
                }
            } catch (PDOException $e) {
                // SQLSTATE 23000 = integrity constraint violation (duplicate key).
                if ($e->getCode() === '23000') {
                    return ServiceResult::ok('Pago ya registrado (operación repetida).');
                }
                throw $e;
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
     *
     * Plan 5: la rama refund se envuelve en transactional() para mantener la
     * invariante "dispatches dentro de TX" del spec. La llamada directa a
     * AdvanceLegalizationService se reemplaza por dispatch de InvoiceRefundRejectedEvent.
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

        // Refund: la factura del Anticipo permanece en `pagada`; el rechazo solo
        // afecta a la legalización vía evento. TX preserva atomicidad save+dispatch.
        if ((bool)($payment->is_refund ?? false)) {
            $connection = $paymentsTable->getConnection();
            $ok = $connection->transactional(function () use ($paymentsTable, $payment, $reason, $rejectedBy) {
                $payment->status = InvoiceConstants::PAYMENT_RECORD_REJECTED;
                $payment->rejection_reason = $reason;

                if (!$paymentsTable->save($payment)) {
                    return false;
                }

                $this->events->dispatch(new Event(
                    'Invoice.refundRejected',
                    null,
                    ['payload' => new InvoiceRefundRejectedEvent($payment, $rejectedBy)],
                ));

                return true;
            });

            if ($ok === false) {
                return ServiceResult::fail('No se pudo rechazar el pago.');
            }

            return ServiceResult::ok('Reintegro rechazado. La legalización volvió a Tesorería.');
        }

        // No-refund: comportamiento original (sin transactional, fuera del scope del Plan 5).
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
            $userId,
        ) {
            $changes = [];
            foreach ($allowed as $field => $newValue) {
                $oldValue = $payment->get($field);
                $oldNorm = $oldValue instanceof DateTimeInterface ? $oldValue->format('Y-m-d') : $oldValue;
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

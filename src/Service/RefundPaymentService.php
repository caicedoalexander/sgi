<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Constants\RefundConstants;
use App\Event\InvoicePaidEvent;
use App\Service\Interface\HistoryServiceInterface;
use Cake\Event\Event;
use Cake\Event\EventManagerInterface;
use Cake\ORM\TableRegistry;

/**
 * Operaciones de pago del flujo de reintegros: registro pendiente, autorización
 * y rechazo. Análogo a `InvoicePaymentService` para invoices.
 *
 * Separado de `RefundService` (pipeline) para que cada servicio tenga una
 * responsabilidad clara: el pipeline gestiona transiciones de estado y la
 * agrupación de facturas; este servicio gestiona el subdominio de pagos.
 */
class RefundPaymentService
{
    private RefundHistoryService $refundHistory;
    private PipelineAuthorizationService $pipelineAuth;
    private HistoryServiceInterface $invoiceHistory;
    private ?EventManagerInterface $events;

    public function __construct(
        ?PipelineAuthorizationService $pipelineAuth = null,
        ?RefundHistoryService $refundHistory = null,
        ?HistoryServiceInterface $invoiceHistory = null,
        ?EventManagerInterface $events = null,
    ) {
        $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
        $this->refundHistory = $refundHistory ?? new RefundHistoryService();
        $this->invoiceHistory = $invoiceHistory ?? new InvoiceHistoryService();
        $this->events = $events;
    }

    /**
     * Registra un pago pendiente para un reintegro en Tesorería.
     * Avanza el reintegro a Aut. Pago.
     *
     * @param int $recordId Record ID.
     * @param array $data Payment data (banking_entity_id, payment_amount, payment_date).
     * @param int $createdBy User ID registering the payment.
     * @param int $roleId Role ID (para enforcement RBAC).
     * @return \App\Service\ServiceResult
     */
    public function registerPayment(
        int $recordId,
        array $data,
        int $createdBy,
        int $roleId,
    ): ServiceResult {
        if (
            !$this->pipelineAuth->canOperate(
                $roleId,
                '',
                PipelineStepConstants::PIPELINE_REFUNDS,
                RefundConstants::STATUS_TESORERIA,
            )
        ) {
            return ServiceResult::fail('No tiene permisos para registrar pagos en este registro.');
        }

        $recordsTable = TableRegistry::getTableLocator()->get('Refunds');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $connection = $recordsTable->getConnection();

        $serviceResult = null;

        $connection->transactional(function () use (
            $recordsTable,
            $invoicesTable,
            $recordId,
            $data,
            $createdBy,
            &$serviceResult,
        ): bool {
            $record = $recordsTable->find()
                ->where(['id' => $recordId])
                ->epilog('FOR UPDATE')
                ->first();

            if ($record === null) {
                $serviceResult = ServiceResult::fail('Registro no encontrado.');

                return false;
            }

            if ($record->status !== RefundConstants::STATUS_TESORERIA) {
                $serviceResult = ServiceResult::fail('Solo se pueden registrar pagos en estado Tesorería.');

                return false;
            }

            if (!empty($record->banking_entity_id)) {
                $serviceResult = ServiceResult::fail('Ya existe un pago pendiente de autorización.');

                return false;
            }

            $bankingEntityId = !empty($data['banking_entity_id'])
                ? (int)$data['banking_entity_id']
                : null;
            $paymentAmount = isset($data['payment_amount']) && $data['payment_amount'] !== ''
                ? (float)$data['payment_amount']
                : null;
            $paymentDate = !empty($data['payment_date']) ? $data['payment_date'] : null;

            if ($bankingEntityId === null) {
                $serviceResult = ServiceResult::fail('Debe seleccionar una entidad bancaria.');

                return false;
            }
            if ($paymentAmount === null || $paymentAmount <= 0) {
                $serviceResult = ServiceResult::fail('El monto del pago debe ser mayor a cero.');

                return false;
            }
            if ($paymentDate === null) {
                $serviceResult = ServiceResult::fail('La fecha de pago es requerida.');

                return false;
            }

            // Total fresco (SUM(amount)) bajo el lock del refund. `total_amount`
            // de la columna podría estar desfasado si otra request editó las
            // hijas; preferimos la fuente de verdad para no dejar el registro
            // en aut_pago con un monto inconsistente.
            $totalFresh = (float)$invoicesTable->find()
                ->where(['refund_id' => $record->id])
                ->all()
                ->sumOf(static fn($invoice): float => (float)$invoice->amount);
            if (abs($paymentAmount - $totalFresh) > 0.01) {
                $serviceResult = ServiceResult::fail(sprintf(
                    'El monto del pago (%.2f) debe coincidir con el total del registro (%.2f).',
                    $paymentAmount,
                    $totalFresh,
                ));

                return false;
            }

            $record->banking_entity_id = $bankingEntityId;
            $record->payment_amount = $paymentAmount;
            $record->payment_date = $paymentDate;
            $record->payment_created_by = $createdBy;
            $record->payment_rejection_reason = null;
            $record->status = RefundConstants::STATUS_AUTORIZACION_PAGO;

            if (!$recordsTable->save($record)) {
                $serviceResult = ServiceResult::fail(self::_buildSaveErrorMessage(
                    'No se pudo registrar el pago.',
                    $record->getErrors(),
                ));

                return false;
            }

            $this->refundHistory->recordStatusChange(
                $record->id,
                RefundConstants::STATUS_TESORERIA,
                RefundConstants::STATUS_AUTORIZACION_PAGO,
                $createdBy,
            );

            $serviceResult = ServiceResult::ok('Pago registrado. Registro avanzado a Autorización de Pago.');

            return true;
        });

        return $serviceResult ?? ServiceResult::fail('No se pudo registrar el pago.');
    }

    /**
     * Autoriza un pago pendiente de reintegro.
     * Materializa invoice_payments para las facturas hijas y mueve el reintegro a Pagado.
     *
     * @param int $recordId Record ID.
     * @param int $authorizedBy User ID authorizing.
     * @param int $roleId Role ID (para enforcement RBAC).
     * @return \App\Service\ServiceResult
     */
    public function authorizePayment(
        int $recordId,
        int $authorizedBy,
        int $roleId,
    ): ServiceResult {
        if (
            !$this->pipelineAuth->canOperate(
                $roleId,
                '',
                PipelineStepConstants::PIPELINE_REFUNDS,
                RefundConstants::STATUS_AUTORIZACION_PAGO,
            )
        ) {
            return ServiceResult::fail('No tiene permisos para autorizar pagos en este registro.');
        }

        $recordsTable = TableRegistry::getTableLocator()->get('Refunds');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoicePaymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        $connection = $recordsTable->getConnection();

        // Capturado por referencia: la closure retorna `false` para forzar
        // rollback. Si retornara un ServiceResult::fail directamente sería
        // truthy y Cake commitearía los saves parciales que precedieron.
        $serviceResult = null;

        $connection->transactional(function () use (
            $recordId,
            $authorizedBy,
            $recordsTable,
            $invoicesTable,
            $invoicePaymentsTable,
            &$serviceResult,
        ): bool {
            $record = $recordsTable->find()
                ->where(['id' => $recordId])
                ->epilog('FOR UPDATE')
                ->first();

            if ($record === null) {
                $serviceResult = ServiceResult::fail('Registro no encontrado.');

                return false;
            }

            if ($record->status !== RefundConstants::STATUS_AUTORIZACION_PAGO) {
                $serviceResult = ServiceResult::fail('El registro no está en estado Autorización de Pago.');

                return false;
            }

            if (empty($record->banking_entity_id)) {
                $serviceResult = ServiceResult::fail('No hay un pago pendiente para autorizar.');

                return false;
            }

            $childInvoices = $invoicesTable->find()
                ->where(['refund_id' => $record->id])
                ->all()
                ->toArray();

            // Validar que todas las facturas hijas tengan monto positivo.
            // Permitir continuar silenciosamente con amount<=0 dejaría las
            // hijas sin invoice_payment ni pipeline_status=pagada.
            foreach ($childInvoices as $invoice) {
                if ((float)$invoice->amount <= 0) {
                    $serviceResult = ServiceResult::fail(sprintf(
                        'La factura %s tiene un monto inválido (<=0). Corrija o desvincule antes de autorizar.',
                        $invoice->invoice_number ?? '#' . $invoice->id,
                    ));

                    return false;
                }
            }

            // Validar que payment_amount cuadre con la suma de las facturas.
            $totalChildren = 0.0;
            foreach ($childInvoices as $invoice) {
                $totalChildren += (float)$invoice->amount;
            }
            if (abs($totalChildren - (float)$record->payment_amount) > 0.01) {
                $serviceResult = ServiceResult::fail(sprintf(
                    'El monto del pago (%.2f) no coincide con el total de las facturas (%.2f).',
                    (float)$record->payment_amount,
                    $totalChildren,
                ));

                return false;
            }

            $today = date('Y-m-d');
            foreach ($childInvoices as $invoice) {
                $invoicePayment = $invoicePaymentsTable->newEntity([
                    'invoice_id' => $invoice->id,
                    'banking_entity_id' => $record->banking_entity_id,
                    'amount' => $invoice->amount,
                    'payment_date' => $record->payment_date,
                    'refund_id' => $record->id,
                    'status' => InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
                    'authorized' => true,
                    'authorized_by' => $authorizedBy,
                    'authorized_date' => $today,
                    'created_by' => $record->payment_created_by,
                ]);

                if (!$invoicePaymentsTable->save($invoicePayment)) {
                    $serviceResult = ServiceResult::fail(self::_buildSaveErrorMessage(
                        sprintf(
                            'No se pudo registrar el pago de la factura %s.',
                            $invoice->invoice_number ?? '#' . $invoice->id,
                        ),
                        $invoicePayment->getErrors(),
                    ));

                    return false;
                }

                $invoice->pipeline_status = InvoiceConstants::STATUS_VERIFICACION_PAGO;
                $invoice->payment_status = InvoiceConstants::PAYMENT_FULL;
                $invoice->full_payment_date = $record->payment_date;

                if (!$invoicesTable->save($invoice)) {
                    $serviceResult = ServiceResult::fail(self::_buildSaveErrorMessage(
                        sprintf(
                            'No se pudo actualizar la factura %s a Verificación de pago.',
                            $invoice->invoice_number ?? '#' . $invoice->id,
                        ),
                        $invoice->getErrors(),
                    ));

                    return false;
                }
            }

            $record->status = RefundConstants::STATUS_VERIFICACION_PAGO;
            $record->payment_status = InvoiceConstants::PAYMENT_FULL;
            $record->payment_authorized_by = $authorizedBy;
            $record->payment_authorized_date = $today;

            if (!$recordsTable->save($record)) {
                $serviceResult = ServiceResult::fail(self::_buildSaveErrorMessage(
                    'No se pudo actualizar el reintegro a Verificación de pago.',
                    $record->getErrors(),
                ));

                return false;
            }

            $this->refundHistory->recordStatusChange(
                $record->id,
                RefundConstants::STATUS_AUTORIZACION_PAGO,
                RefundConstants::STATUS_VERIFICACION_PAGO,
                $authorizedBy,
            );

            $serviceResult = ServiceResult::ok('Pago autorizado. El reintegro pasó a Verificación de pago.');

            return true;
        });

        return $serviceResult ?? ServiceResult::fail('No se pudo autorizar el pago.');
    }

    /**
     * Rechaza un pago pendiente de reintegro.
     * Limpia los campos de pago y devuelve el reintegro a Tesorería con motivo.
     *
     * @param int $recordId Record ID.
     * @param int $rejectedBy User ID rejecting (currently unused, reserved for audit).
     * @param string $reason Rejection reason (required).
     * @param int $roleId Role ID (para enforcement RBAC).
     * @return \App\Service\ServiceResult
     */
    public function rejectPayment(
        int $recordId,
        int $rejectedBy,
        string $reason,
        int $roleId,
    ): ServiceResult {
        if (
            !$this->pipelineAuth->canOperate(
                $roleId,
                '',
                PipelineStepConstants::PIPELINE_REFUNDS,
                RefundConstants::STATUS_AUTORIZACION_PAGO,
            )
        ) {
            return ServiceResult::fail('No tiene permisos para rechazar pagos en este registro.');
        }

        $recordsTable = TableRegistry::getTableLocator()->get('Refunds');
        $connection = $recordsTable->getConnection();

        $serviceResult = null;

        $connection->transactional(function () use ($recordsTable, $recordId, $reason, &$serviceResult): bool {
            $record = $recordsTable->find()
                ->where(['id' => $recordId])
                ->epilog('FOR UPDATE')
                ->first();

            if ($record === null) {
                $serviceResult = ServiceResult::fail('Registro no encontrado.');

                return false;
            }

            if ($record->status !== RefundConstants::STATUS_AUTORIZACION_PAGO) {
                $serviceResult = ServiceResult::fail('Solo se pueden rechazar pagos en estado Autorización de Pago.');

                return false;
            }

            $record->banking_entity_id = null;
            $record->payment_amount = null;
            $record->payment_date = null;
            $record->payment_created_by = null;
            // Limpieza idempotente — si autorizaciones previas dejaron campos
            // poblados por error, este reset garantiza estado consistente.
            $record->payment_status = null;
            $record->payment_authorized_by = null;
            $record->payment_authorized_date = null;
            $record->payment_rejection_reason = $reason;
            $record->status = RefundConstants::STATUS_TESORERIA;

            if (!$recordsTable->save($record)) {
                $serviceResult = ServiceResult::fail(self::_buildSaveErrorMessage(
                    'No se pudo rechazar el pago.',
                    $record->getErrors(),
                ));

                return false;
            }

            $this->refundHistory->recordStatusChange(
                $record->id,
                RefundConstants::STATUS_AUTORIZACION_PAGO,
                RefundConstants::STATUS_TESORERIA,
                $rejectedBy,
            );
            $this->refundHistory->recordFieldChange(
                $record->id,
                'payment_rejection_reason',
                null,
                $reason,
                $rejectedBy,
            );

            $serviceResult = ServiceResult::ok('Pago rechazado. Registro devuelto a Tesorería.');

            return true;
        });

        return $serviceResult ?? ServiceResult::fail('No se pudo rechazar el pago.');
    }

    /**
     * Builds a readable error message including entity validation details.
     *
     * @param string $base Base message.
     * @param array $entityErrors Errors returned by `Entity::getErrors()`.
     */
    private static function _buildSaveErrorMessage(string $base, array $entityErrors): string
    {
        $details = [];
        foreach ($entityErrors as $field => $fieldErrors) {
            foreach ((array)$fieldErrors as $msg) {
                if (is_string($msg) && $msg !== '') {
                    $details[] = sprintf('%s: %s', $field, $msg);
                }
            }
        }

        return empty($details) ? $base : ($base . ' ' . implode(', ', $details));
    }

    /**
     * Tesorería confirma que el pago del reintegro ya se ejecutó.
     * Avanza reintegro y facturas hijas de verificacion_pago → pagada,
     * registra historial y dispara InvoicePaidEvent por cada hija.
     */
    public function confirmPayment(int $refundId, int $confirmedBy): ServiceResult
    {
        $refundsTable = TableRegistry::getTableLocator()->get('Refunds');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $record = $refundsTable->get($refundId);
        if ($record->status !== RefundConstants::STATUS_VERIFICACION_PAGO) {
            return ServiceResult::fail('El reintegro no está en verificación de pago.');
        }

        $connection = $refundsTable->getConnection();
        $ok = $connection->transactional(function () use ($refundsTable, $invoicesTable, $record, $confirmedBy) {
            $previousStatus = $record->status;
            $record->status = RefundConstants::STATUS_PAGADA;
            if (!$refundsTable->save($record)) {
                return false;
            }

            $this->refundHistory->recordStatusChange(
                $record->id,
                $previousStatus,
                $record->status,
                $confirmedBy,
            );

            $childInvoices = $invoicesTable->find()
                ->where([
                    'refund_id' => $record->id,
                    'pipeline_status' => InvoiceConstants::STATUS_VERIFICACION_PAGO,
                ])
                ->all();

            foreach ($childInvoices as $invoice) {
                $invoicePreviousStatus = $invoice->pipeline_status;
                $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
                if (!$invoicesTable->save($invoice)) {
                    return false;
                }
                $this->invoiceHistory->recordStatusChange(
                    $invoice->id,
                    $invoicePreviousStatus,
                    InvoiceConstants::STATUS_PAGADA,
                    $confirmedBy,
                );
                if ($this->events !== null) {
                    $this->events->dispatch(new Event(
                        'Invoice.paid',
                        null,
                        ['payload' => new InvoicePaidEvent($invoice, $confirmedBy)],
                    ));
                }
            }

            return true;
        });

        if ($ok === false) {
            return ServiceResult::fail('No se pudo confirmar el reintegro.');
        }

        return ServiceResult::ok('Pago confirmado. El reintegro y sus facturas quedaron como pagados.');
    }
}

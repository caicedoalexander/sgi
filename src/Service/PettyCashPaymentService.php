<?php
declare(strict_types=1);

namespace App\Service;

use App\Authorization\AuthorizationFacade;
use App\Constants\InvoiceConstants;
use App\Constants\PettyCashConstants;
use App\Constants\PipelineStepConstants;
use App\Event\InvoicePaidEvent;
use App\Service\Interface\HistoryServiceInterface;
use App\ValueObject\UserContext;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Cake\ORM\TableRegistry;

/**
 * Operaciones de pago del flujo de caja menor: registro pendiente, autorización,
 * rechazo y confirmación de ejecución. Análogo a `InvoicePaymentService` para
 * invoices y a `RefundPaymentService` para reintegros.
 *
 * Separado de `PettyCashPipelineService` (pipeline) para que cada servicio tenga
 * una responsabilidad clara: el pipeline gestiona transiciones de estado y la
 * agrupación de facturas; este servicio gestiona el subdominio de pagos.
 *
 * `buildSyntheticPayments` permanece en `PettyCashPipelineService` (espejo de
 * Refund, donde la materialización del pago bulk para la vista vive en el
 * coordinador del pipeline).
 */
class PettyCashPaymentService
{
    private AuthorizationFacade $auth;
    private PettyCashHistoryService $history;
    private HistoryServiceInterface $invoiceHistory;
    private ?EventManagerInterface $events;

    /**
     * @param \App\Authorization\AuthorizationFacade $auth Authorization facade.
     * @param \App\Service\PettyCashHistoryService|null $history Audit trail for the petty cash record itself.
     * @param \App\Service\Interface\HistoryServiceInterface|null $invoiceHistory History service for child invoices.
     * @param \Cake\Event\EventManagerInterface|null $events Event manager.
     */
    public function __construct(
        AuthorizationFacade $auth,
        ?PettyCashHistoryService $history = null,
        ?HistoryServiceInterface $invoiceHistory = null,
        ?EventManagerInterface $events = null,
    ) {
        $this->auth = $auth;
        $this->history = $history ?? new PettyCashHistoryService();
        $this->invoiceHistory = $invoiceHistory ?? new InvoiceHistoryService();
        $this->events = $events ?? EventManager::instance();
    }

    /**
     * Register a pending payment for a petty cash record in Tesorería.
     * Moves the record to Aut. Pago.
     *
     * @param int $recordId Record ID.
     * @param int $roleId Role ID of the caller (for pipeline authorization).
     * @param array $data Payment data (banking_entity_id, payment_amount, payment_date).
     * @param int $createdBy User ID registering the payment.
     * @return \App\Service\ServiceResult
     */
    public function registerPayment(
        int $recordId,
        int $roleId,
        array $data,
        int $createdBy,
    ): ServiceResult {
        if (
            !$this->auth->canOperate(
                new UserContext($roleId),
                PipelineStepConstants::PIPELINE_PETTY_CASH,
                PettyCashConstants::STATUS_TESORERIA,
            )
        ) {
            return ServiceResult::fail('No tiene permisos para registrar pagos en este registro.');
        }

        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $record = $recordsTable->get($recordId);

        if ($record->status !== PettyCashConstants::STATUS_TESORERIA) {
            return ServiceResult::fail('Solo se pueden registrar pagos en estado Tesorería.');
        }

        if (!empty($record->banking_entity_id)) {
            return ServiceResult::fail('Ya existe un pago pendiente de autorización.');
        }

        if ((float)$record->total_amount <= 0) {
            return ServiceResult::fail('El registro no tiene un monto total válido.');
        }

        // El pago de Caja Menor siempre cubre el total del registro consolidado.
        // Forzamos payment_amount = total_amount para evitar manipulación del POST
        // y mantener consistencia con la materialización en invoice_payments.
        $previousStatus = $record->status;
        $record = $recordsTable->patchEntity($record, [
            'banking_entity_id' => $data['banking_entity_id'] ?? null,
            'payment_amount' => $record->total_amount,
            'payment_date' => $data['payment_date'] ?? null,
            'payment_created_by' => $createdBy,
            'payment_rejection_reason' => null,
            'status' => PettyCashConstants::STATUS_AUTORIZACION_PAGO,
        ]);

        if (!$recordsTable->save($record)) {
            $errors = [];
            foreach ($record->getErrors() as $field => $fieldErrors) {
                foreach ($fieldErrors as $msg) {
                    $errors[] = "$field: $msg";
                }
            }

            $msg = 'No se pudo registrar el pago.';
            if (!empty($errors)) {
                $msg .= ' ' . implode(', ', $errors);
            }

            return ServiceResult::fail($msg);
        }

        $this->history->recordStatusChange($record->id, $previousStatus, $record->status, $createdBy);

        return ServiceResult::ok('Pago registrado. Registro avanzado a Autorización de Pago.');
    }

    /**
     * Authorize a pending petty cash payment.
     * Materializes invoice_payments for child invoices and moves record to Pagado.
     *
     * @param int $recordId Record ID.
     * @param int $roleId Role ID of the caller (for pipeline authorization).
     * @param int $authorizedBy User ID authorizing.
     * @return \App\Service\ServiceResult
     */
    public function authorizePayment(
        int $recordId,
        int $roleId,
        int $authorizedBy,
    ): ServiceResult {
        if (
            !$this->auth->canOperate(
                new UserContext($roleId),
                PipelineStepConstants::PIPELINE_PETTY_CASH,
                PettyCashConstants::STATUS_AUTORIZACION_PAGO,
            )
        ) {
            return ServiceResult::fail('No tiene permisos para autorizar pagos de este registro.');
        }

        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoicePaymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        $record = $recordsTable->get($recordId);

        if ($record->status !== PettyCashConstants::STATUS_AUTORIZACION_PAGO) {
            return ServiceResult::fail('El registro no está en estado Autorización de Pago.');
        }

        if (empty($record->banking_entity_id)) {
            return ServiceResult::fail('No hay un pago pendiente para autorizar.');
        }

        $connection = $recordsTable->getConnection();

        $ok = $connection->transactional(function () use (
            $record,
            $authorizedBy,
            $recordsTable,
            $invoicesTable,
            $invoicePaymentsTable,
        ) {
            $childInvoices = $invoicesTable->find()
                ->where(['petty_cash_record_id' => $record->id])
                ->all();

            foreach ($childInvoices as $invoice) {
                // Materializar invoice_payment solo cuando hay monto efectivo.
                // Facturas con amount = 0 (caso atípico pero posible) se marcan
                // pagada sin crear un pago de cero pesos.
                if ((float)$invoice->amount > 0) {
                    $invoicePayment = $invoicePaymentsTable->newEntity([
                        'invoice_id' => $invoice->id,
                        'banking_entity_id' => $record->banking_entity_id,
                        'amount' => $invoice->amount,
                        'payment_date' => $record->payment_date,
                        'petty_cash_record_id' => $record->id,
                        'status' => InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
                        'authorized' => true,
                        'authorized_by' => $authorizedBy,
                        'authorized_date' => date('Y-m-d'),
                        'created_by' => $record->payment_created_by,
                    ]);

                    if (!$invoicePaymentsTable->save($invoicePayment)) {
                        return false;
                    }
                }

                $invoice->pipeline_status = InvoiceConstants::STATUS_VERIFICACION_PAGO;
                $invoice->payment_status = InvoiceConstants::PAYMENT_FULL;
                $invoice->full_payment_date = $record->payment_date;

                if (!$invoicesTable->save($invoice)) {
                    return false;
                }
            }

            $previousStatus = $record->status;
            $record->status = PettyCashConstants::STATUS_VERIFICACION_PAGO;
            $record->payment_status = InvoiceConstants::PAYMENT_FULL;
            $record->payment_authorized_by = $authorizedBy;
            $record->payment_authorized_date = date('Y-m-d');

            if (!$recordsTable->save($record)) {
                return false;
            }

            $this->history->recordStatusChange(
                $record->id,
                $previousStatus,
                $record->status,
                $authorizedBy,
            );

            return true;
        });

        if ($ok === false) {
            return ServiceResult::fail('No se pudo autorizar el pago.');
        }

        return ServiceResult::ok('Pago autorizado. Registro marcado como Pagado.');
    }

    /**
     * Reject a pending petty cash payment.
     * Clears pending fields and returns record to Tesorería with rejection reason.
     *
     * @param int $recordId Record ID.
     * @param int $roleId Role ID of the caller (for pipeline authorization).
     * @param int $rejectedBy User ID rejecting (currently unused, reserved for audit).
     * @param string $reason Rejection reason (required).
     * @return \App\Service\ServiceResult
     */
    public function rejectPayment(
        int $recordId,
        int $roleId,
        int $rejectedBy,
        string $reason,
    ): ServiceResult {
        if (
            !$this->auth->canOperate(
                new UserContext($roleId),
                PipelineStepConstants::PIPELINE_PETTY_CASH,
                PettyCashConstants::STATUS_AUTORIZACION_PAGO,
            )
        ) {
            return ServiceResult::fail('No tiene permisos para rechazar pagos de este registro.');
        }

        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $record = $recordsTable->get($recordId);

        if ($record->status !== PettyCashConstants::STATUS_AUTORIZACION_PAGO) {
            return ServiceResult::fail('Solo se pueden rechazar pagos en estado Autorización de Pago.');
        }

        $previousStatus = $record->status;
        $record = $recordsTable->patchEntity($record, [
            'banking_entity_id' => null,
            'payment_amount' => null,
            'payment_date' => null,
            'payment_created_by' => null,
            'payment_rejection_reason' => $reason,
            'status' => PettyCashConstants::STATUS_TESORERIA,
        ]);

        if (!$recordsTable->save($record)) {
            return ServiceResult::fail('No se pudo rechazar el pago.');
        }

        $this->history->recordStatusChange($record->id, $previousStatus, $record->status, $rejectedBy);

        return ServiceResult::ok('Pago rechazado. Registro devuelto a Tesorería.');
    }

    /**
     * Tesorería confirma que el pago del record de caja menor ya se ejecutó.
     * Avanza record y todas sus facturas hijas de verificacion_pago → pagada,
     * registra historial y dispara InvoicePaidEvent por cada hija.
     */
    public function confirmPayment(int $recordId, int $confirmedBy): ServiceResult
    {
        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $record = $recordsTable->get($recordId);
        if ($record->status !== PettyCashConstants::STATUS_VERIFICACION_PAGO) {
            return ServiceResult::fail('El registro no está en verificación de pago.');
        }

        $connection = $recordsTable->getConnection();
        $ok = $connection->transactional(function () use ($recordsTable, $invoicesTable, $record, $confirmedBy) {
            $previousStatus = $record->status;
            $record->status = PettyCashConstants::STATUS_PAGADA;
            if (!$recordsTable->save($record)) {
                return false;
            }

            $this->history->recordStatusChange(
                $record->id,
                $previousStatus,
                $record->status,
                $confirmedBy,
            );

            $childInvoices = $invoicesTable->find()
                ->where([
                    'petty_cash_record_id' => $record->id,
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
            return ServiceResult::fail('No se pudo confirmar el pago.');
        }

        return ServiceResult::ok('Pago confirmado. El registro y sus facturas quedaron como pagados.');
    }
}

<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\PettyCashConstants;
use App\Model\Entity\PettyCashRecord;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;

class PettyCashService
{
    private GroupedInvoiceService $grouped;

    /**
     * @param \App\Service\InvoiceHistoryService|null $historyService History service.
     */
    public function __construct(?InvoiceHistoryService $historyService = null)
    {
        $this->grouped = new GroupedInvoiceService(
            InvoiceConstants::DOCTYPE_CAJA_MENOR,
            'petty_cash_record_id',
            'PettyCashRecords',
            'Caja Menor',
            $historyService,
        );
    }

    /**
     * @param array $invoiceIds Invoice IDs to validate.
     * @return array
     */
    public function validateGrouping(array $invoiceIds): array
    {
        return $this->grouped->validateGrouping($invoiceIds);
    }

    /**
     * @param \App\Model\Entity\PettyCashRecord $record Record.
     * @param array $invoiceIds Invoice IDs.
     * @return array
     */
    public function addInvoices(PettyCashRecord $record, array $invoiceIds): array
    {
        return $this->grouped->addInvoices($record, $invoiceIds);
    }

    /**
     * @param \App\Model\Entity\PettyCashRecord $record Record.
     * @param int $invoiceId Invoice ID.
     * @return bool
     */
    public function removeInvoice(PettyCashRecord $record, int $invoiceId): bool
    {
        return $this->grouped->removeInvoice($record, $invoiceId);
    }

    /**
     * @param \App\Model\Entity\PettyCashRecord $record Record.
     * @return void
     */
    public function calculateAndSaveTotal(PettyCashRecord $record): void
    {
        $this->grouped->calculateAndSaveTotal($record);
    }

    /**
     * @param array $filters Filters.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function getAvailableInvoices(array $filters = []): SelectQuery
    {
        return $this->grouped->getAvailableInvoices($filters);
    }

    /**
     * @param \App\Model\Entity\PettyCashRecord $record Record.
     * @param int $userId User ID.
     * @return array
     */
    public function advanceStatus(PettyCashRecord $record, int $userId): array
    {
        $currentStatus = $record->status;
        $nextStatus = PettyCashConstants::TRANSITIONS[$currentStatus] ?? null;

        if ($nextStatus === null) {
            return ['success' => false, 'error' => 'Este registro ya está en su estado final.'];
        }

        if ($nextStatus === PettyCashConstants::STATUS_AUT_PAGO) {
            return ['success' => false, 'error' => 'Debe registrar un pago para avanzar desde Tesorería.'];
        }

        if ($currentStatus === PettyCashConstants::STATUS_AUT_PAGO) {
            return ['success' => false, 'error' => 'La autorización de pago se gestiona desde la sección de pagos.'];
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $fkField = $this->grouped->getFkField();
        $invoices = $invoicesTable->find()
            ->where([$fkField => $record->id])
            ->all()
            ->toArray();

        if (empty($invoices)) {
            return ['success' => false, 'error' => 'El registro debe tener al menos una factura agrupada.'];
        }

        $validationErrors = $this->_validateTransition($currentStatus, $record);
        if (!empty($validationErrors)) {
            return [
                'success' => false,
                'error' => 'No se puede avanzar. ' . implode('. ', $validationErrors),
            ];
        }

        $connection = $invoicesTable->getConnection();

        return $connection->transactional(function () use ($record, $nextStatus, $invoicesTable, $fkField, $userId) {
            $today = date('Y-m-d');
            $updateData = [];

            if ($nextStatus === PettyCashConstants::STATUS_CONTABILIDAD) {
                $updateData = [
                    'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
                ];
            } elseif ($nextStatus === PettyCashConstants::STATUS_TESORERIA) {
                $updateData = [
                    'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
                    'accrued' => (bool)$record->accrued,
                    'accrual_date' => $record->accrual_date ?? $today,
                    'ready_for_payment' => $record->ready_for_payment,
                ];
            }

            $invoicesBefore = $invoicesTable->find()
                ->select(['id', 'pipeline_status'])
                ->where([$fkField => $record->id])
                ->all()
                ->toArray();

            if (!empty($updateData)) {
                $invoicesTable->updateAll(
                    $updateData,
                    [$fkField => $record->id],
                );

                $newPipelineStatus = $updateData['pipeline_status'] ?? null;
                if ($newPipelineStatus) {
                    $this->grouped->recordBulkHistory($record->id, $invoicesBefore, $newPipelineStatus, $userId);
                }
            }

            $table = TableRegistry::getTableLocator()->get('PettyCashRecords');
            $record->status = $nextStatus;
            $table->save($record);

            return [
                'success' => true,
                'nextStatus' => $nextStatus,
            ];
        });
    }

    /**
     * Get transition validation errors for the current record (used by views).
     *
     * @param \App\Model\Entity\PettyCashRecord $record Record.
     * @return array
     */
    public function getTransitionErrors(PettyCashRecord $record): array
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoices = $invoicesTable->find()
            ->where([$this->grouped->getFkField() => $record->id])
            ->all()
            ->toArray();

        if (empty($invoices)) {
            return ['El registro debe tener al menos una factura agrupada.'];
        }

        return $this->_validateTransition($record->status, $record);
    }

    /**
     * Validate petty cash specific transition requirements.
     *
     * @param string $fromStatus Current status.
     * @param \App\Model\Entity\PettyCashRecord $record Record.
     * @return array
     */
    private function _validateTransition(string $fromStatus, PettyCashRecord $record): array
    {
        $errors = [];

        switch ($fromStatus) {
            case PettyCashConstants::STATUS_AGRUPACION:
                break;

            case PettyCashConstants::STATUS_CONTABILIDAD:
                if (empty($record->accrued)) {
                    $errors[] = 'El registro debe estar marcado como Causado.';
                }
                if (empty($record->ready_for_payment)) {
                    $errors[] = 'Debe seleccionar "Lista para Pago".';
                }
                break;

            case PettyCashConstants::STATUS_TESORERIA:
                break;
        }

        return $errors;
    }

    /**
     * @param \App\Model\Entity\PettyCashRecord $record Record.
     * @return bool
     */
    public function canDelete(PettyCashRecord $record): bool
    {
        return $record->isAgrupacion();
    }

    /**
     * Register a pending payment for a petty cash record in Tesorería.
     * Moves the record to Aut. Pago.
     *
     * @param int $recordId Record ID.
     * @param array $data Payment data (banking_entity_id, payment_amount, payment_date).
     * @param int $createdBy User ID registering the payment.
     * @return \App\Service\ServiceResult
     */
    public function registerPayment(int $recordId, array $data, int $createdBy): ServiceResult
    {
        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $record = $recordsTable->get($recordId);

        if ($record->status !== PettyCashConstants::STATUS_TESORERIA) {
            return ServiceResult::fail('Solo se pueden registrar pagos en estado Tesorería.');
        }

        if (!empty($record->banking_entity_id)) {
            return ServiceResult::fail('Ya existe un pago pendiente de autorización.');
        }

        $record = $recordsTable->patchEntity($record, [
            'banking_entity_id' => $data['banking_entity_id'] ?? null,
            'payment_amount' => $data['payment_amount'] ?? null,
            'payment_date' => $data['payment_date'] ?? null,
            'payment_created_by' => $createdBy,
            'payment_rejection_reason' => null,
            'status' => PettyCashConstants::STATUS_AUT_PAGO,
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

        return ServiceResult::ok('Pago registrado. Registro avanzado a Autorización de Pago.');
    }

    /**
     * Authorize a pending petty cash payment.
     * Materializes invoice_payments for child invoices and moves record to Pagado.
     *
     * @param int $recordId Record ID.
     * @param int $authorizedBy User ID authorizing.
     * @return \App\Service\ServiceResult
     */
    public function authorizePayment(int $recordId, int $authorizedBy): ServiceResult
    {
        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoicePaymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        $record = $recordsTable->get($recordId);

        if ($record->status !== PettyCashConstants::STATUS_AUT_PAGO) {
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
                if ((float)$invoice->amount <= 0) {
                    continue;
                }

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

                $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
                $invoice->payment_status = InvoiceConstants::PAYMENT_FULL;
                $invoice->full_payment_date = $record->payment_date;

                if (!$invoicesTable->save($invoice)) {
                    return false;
                }
            }

            $record->status = PettyCashConstants::STATUS_PAGADO;
            $record->payment_status = InvoiceConstants::PAYMENT_FULL;
            $record->payment_authorized_by = $authorizedBy;
            $record->payment_authorized_date = date('Y-m-d');

            return (bool)$recordsTable->save($record);
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
     * @param int $rejectedBy User ID rejecting (currently unused, reserved for audit).
     * @param string $reason Rejection reason (required).
     * @return \App\Service\ServiceResult
     */
    public function rejectPayment(int $recordId, int $rejectedBy, string $reason): ServiceResult
    {
        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $record = $recordsTable->get($recordId);

        if ($record->status !== PettyCashConstants::STATUS_AUT_PAGO) {
            return ServiceResult::fail('Solo se pueden rechazar pagos en estado Autorización de Pago.');
        }

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

        return ServiceResult::ok('Pago rechazado. Registro devuelto a Tesorería.');
    }
}

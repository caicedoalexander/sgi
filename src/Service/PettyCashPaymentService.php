<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\PettyCashConstants;
use Cake\ORM\TableRegistry;

class PettyCashPaymentService
{
    /**
     * Register a payment for a petty cash record.
     *
     * @param int $recordId Petty cash record ID.
     * @param array $paymentData Payment form data.
     * @param int $createdBy User ID who creates the payment.
     * @return \App\Service\ServiceResult
     */
    public function registerPayment(int $recordId, array $paymentData, int $createdBy): ServiceResult
    {
        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $paymentsTable = TableRegistry::getTableLocator()->get('PettyCashPayments');

        $record = $recordsTable->get($recordId);

        if ($record->status !== PettyCashConstants::STATUS_TESORERIA) {
            return ServiceResult::fail('Solo se pueden registrar pagos en estado Tesorería.');
        }

        $existing = $paymentsTable->find()
            ->where(['petty_cash_record_id' => $recordId, 'authorized' => false])
            ->first();
        if ($existing) {
            return ServiceResult::fail('Ya existe un pago pendiente de autorización.');
        }

        $payment = $paymentsTable->newEntity([
            'petty_cash_record_id' => $recordId,
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

            $msg = 'No se pudo registrar el pago.';
            if (!empty($errors)) {
                $msg .= ' ' . implode(', ', $errors);
            }

            return ServiceResult::fail($msg);
        }

        $record->status = PettyCashConstants::STATUS_AUT_PAGO;
        $recordsTable->save($record);

        return ServiceResult::ok('Pago registrado. Registro avanzado a Autorización de Pago.');
    }

    /**
     * Authorize a pending payment.
     *
     * @param int $paymentId Payment ID to authorize.
     * @param int $authorizedBy User ID who authorizes.
     * @return array
     */
    public function authorizePayment(int $paymentId, int $authorizedBy): array
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('PettyCashPayments');
        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $payment = $paymentsTable->get($paymentId);
        $payment->authorized = true;
        $payment->authorized_by = $authorizedBy;
        $payment->authorized_date = date('Y-m-d');

        if (!$paymentsTable->save($payment)) {
            return ['success' => false];
        }

        $record = $recordsTable->get($payment->petty_cash_record_id);
        $record->status = PettyCashConstants::STATUS_PAGADO;
        $record->payment_status = InvoiceConstants::PAYMENT_FULL;
        $record->payment_date = $payment->payment_date;
        $recordsTable->save($record);

        // Update child invoices to pagada
        $invoicesTable->updateAll(
            [
                'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
                'payment_status' => InvoiceConstants::PAYMENT_FULL,
                'full_payment_date' => $payment->payment_date,
            ],
            ['petty_cash_record_id' => $record->id],
        );

        return ['success' => true, 'newPipelineStatus' => PettyCashConstants::STATUS_PAGADO];
    }

    /**
     * Reject a pending payment and return record to Tesorería.
     *
     * @param int $paymentId Payment ID to reject.
     * @param int $rejectedBy User ID who rejects.
     * @return \App\Service\ServiceResult
     */
    public function rejectPayment(int $paymentId, int $rejectedBy): ServiceResult
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('PettyCashPayments');
        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');

        $payment = $paymentsTable->get($paymentId);

        if ($payment->authorized) {
            return ServiceResult::fail('No se puede rechazar un pago ya autorizado.');
        }

        $recordId = $payment->petty_cash_record_id;

        if (!$paymentsTable->delete($payment)) {
            return ServiceResult::fail('No se pudo rechazar el pago.');
        }

        $record = $recordsTable->get($recordId);
        $record->status = PettyCashConstants::STATUS_TESORERIA;
        $recordsTable->save($record);

        return ServiceResult::ok('Pago rechazado. Registro devuelto a Tesorería.');
    }
}

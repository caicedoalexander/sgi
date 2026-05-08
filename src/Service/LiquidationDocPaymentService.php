<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\NoveltyConstants;
use Cake\ORM\TableRegistry;

class LiquidationDocPaymentService
{
    /**
     * Register a payment for a liquidation document.
     *
     * @param int $docId Liquidation document ID.
     * @param array $paymentData Payment form data.
     * @param int $createdBy User ID who creates the payment.
     * @return \App\Service\ServiceResult
     */
    public function registerPayment(int $docId, array $paymentData, int $createdBy): ServiceResult
    {
        $docsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');
        $paymentsTable = TableRegistry::getTableLocator()->get('LiquidationDocPayments');
        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');

        $doc = $docsTable->get($docId);

        if ($doc->pipeline_status !== NoveltyConstants::STATUS_TESORERIA) {
            return ServiceResult::fail('Solo se pueden registrar pagos en estado Tesorería.');
        }

        $existing = $paymentsTable->find()
            ->where(['liquidation_doc_id' => $docId, 'authorized' => false])
            ->first();
        if ($existing) {
            return ServiceResult::fail('Ya existe un pago pendiente de autorización.');
        }

        $payment = $paymentsTable->newEntity([
            'liquidation_doc_id' => $docId,
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

        // Advance doc and novelties to aut_pago
        $doc->pipeline_status = NoveltyConstants::STATUS_AUTORIZACION_PAGO;
        $docsTable->save($doc);

        $noveltiesTable->updateAll(
            ['pipeline_status' => NoveltyConstants::STATUS_AUTORIZACION_PAGO],
            ['liquidation_doc_id' => $docId],
        );

        return ServiceResult::ok('Pago registrado. Documento avanzado a Autorización de Pago.');
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
        $paymentsTable = TableRegistry::getTableLocator()->get('LiquidationDocPayments');
        $docsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');
        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');

        $payment = $paymentsTable->get($paymentId);
        $payment->authorized = true;
        $payment->authorized_by = $authorizedBy;
        $payment->authorized_date = date('Y-m-d');

        if (!$paymentsTable->save($payment)) {
            return ['success' => false];
        }

        $doc = $docsTable->get($payment->liquidation_doc_id);
        $doc->pipeline_status = NoveltyConstants::STATUS_VERIFICACION_PAGO;
        $doc->payment_status = NoveltyConstants::PAYMENT_PAGADO;
        $doc->payment_date = $payment->payment_date;
        $docsTable->save($doc);

        $noveltiesTable->updateAll(
            ['pipeline_status' => NoveltyConstants::STATUS_VERIFICACION_PAGO],
            ['liquidation_doc_id' => $payment->liquidation_doc_id],
        );

        return ['success' => true, 'newPipelineStatus' => NoveltyConstants::STATUS_VERIFICACION_PAGO];
    }

    /**
     * Reject a pending payment and return document to Tesorería.
     *
     * @param int $paymentId Payment ID to reject.
     * @param int $rejectedBy User ID who rejects.
     * @return \App\Service\ServiceResult
     */
    public function rejectPayment(int $paymentId, int $rejectedBy): ServiceResult
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('LiquidationDocPayments');
        $docsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');
        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');

        $payment = $paymentsTable->get($paymentId);

        if ($payment->authorized) {
            return ServiceResult::fail('No se puede rechazar un pago ya autorizado.');
        }

        $docId = $payment->liquidation_doc_id;

        if (!$paymentsTable->delete($payment)) {
            return ServiceResult::fail('No se pudo rechazar el pago.');
        }

        $doc = $docsTable->get($docId);
        $doc->pipeline_status = NoveltyConstants::STATUS_TESORERIA;
        $docsTable->save($doc);

        $noveltiesTable->updateAll(
            ['pipeline_status' => NoveltyConstants::STATUS_TESORERIA],
            ['liquidation_doc_id' => $docId],
        );

        return ServiceResult::ok('Pago rechazado. Documento devuelto a Tesorería.');
    }

    /**
     * Tesorería confirma que el pago del documento de liquidación ya se ejecutó.
     * Avanza doc y novedades hijas de verificacion_pago → pagada.
     */
    public function confirmPayment(int $docId, int $confirmedBy): ServiceResult
    {
        $docsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');
        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');

        $doc = $docsTable->get($docId);
        if ($doc->pipeline_status !== NoveltyConstants::STATUS_VERIFICACION_PAGO) {
            return ServiceResult::fail('El documento no está en verificación de pago.');
        }

        $connection = $docsTable->getConnection();
        $ok = $connection->transactional(function () use ($docsTable, $noveltiesTable, $doc) {
            $doc->pipeline_status = NoveltyConstants::STATUS_PAGADA;
            if (!$docsTable->save($doc)) {
                return false;
            }

            $noveltiesTable->updateAll(
                ['pipeline_status' => NoveltyConstants::STATUS_PAGADA],
                [
                    'liquidation_doc_id' => $doc->id,
                    'pipeline_status' => NoveltyConstants::STATUS_VERIFICACION_PAGO,
                ],
            );

            return true;
        });

        if ($ok === false) {
            return ServiceResult::fail('No se pudo confirmar el pago del documento.');
        }

        return ServiceResult::ok('Pago confirmado. El documento y sus novedades quedaron como pagados.');
    }
}

<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\NoveltyConstants;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

class LiquidationDocPaymentService
{
    private NoveltyHistoryService $noveltyHistory;

    public function __construct(?NoveltyHistoryService $noveltyHistory = null)
    {
        $this->noveltyHistory = $noveltyHistory ?? new NoveltyHistoryService();
    }

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
        $connection = $docsTable->getConnection();
        $ok = $connection->transactional(function () use ($docsTable, $noveltiesTable, $doc, $docId, $createdBy) {
            $doc->pipeline_status = NoveltyConstants::STATUS_AUTORIZACION_PAGO;
            if (!$docsTable->save($doc)) {
                return false;
            }

            return $this->advanceChildren(
                $noveltiesTable,
                $docId,
                NoveltyConstants::STATUS_AUTORIZACION_PAGO,
                $createdBy,
            );
        });

        if ($ok === false) {
            return ServiceResult::fail('No se pudo avanzar el documento a Autorización de Pago.');
        }

        return ServiceResult::ok('Pago registrado. Documento avanzado a Autorización de Pago.');
    }

    /**
     * Authorize a pending payment.
     *
     * @param int $paymentId Payment ID to authorize.
     * @param int $authorizedBy User ID who authorizes.
     * @return \App\Service\ServiceResult
     */
    public function authorizePayment(int $paymentId, int $authorizedBy): ServiceResult
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('LiquidationDocPayments');
        $docsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');
        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');

        $payment = $paymentsTable->get($paymentId);
        $payment->authorized = true;
        $payment->authorized_by = $authorizedBy;
        $payment->authorized_date = date('Y-m-d');

        if (!$paymentsTable->save($payment)) {
            return ServiceResult::fail('No se pudo autorizar el pago.');
        }

        $doc = $docsTable->get($payment->liquidation_doc_id);

        $connection = $docsTable->getConnection();
        $ok = $connection->transactional(function () use ($docsTable, $noveltiesTable, $doc, $payment, $authorizedBy) {
            $doc->pipeline_status = NoveltyConstants::STATUS_VERIFICACION_PAGO;
            $doc->payment_status = NoveltyConstants::PAYMENT_PAGADO;
            $doc->payment_date = $payment->payment_date;
            if (!$docsTable->save($doc)) {
                return false;
            }

            return $this->advanceChildren(
                $noveltiesTable,
                (int)$payment->liquidation_doc_id,
                NoveltyConstants::STATUS_VERIFICACION_PAGO,
                $authorizedBy,
            );
        });

        if ($ok === false) {
            return ServiceResult::fail('No se pudo autorizar el pago.');
        }

        return ServiceResult::ok('Pago autorizado. Documento en verificación de pago — Tesorería debe confirmar la ejecución.');
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
        $doc = $docsTable->get($docId);

        $connection = $docsTable->getConnection();
        $ok = $connection->transactional(
            function () use ($paymentsTable, $docsTable, $noveltiesTable, $payment, $doc, $docId, $rejectedBy) {
                if (!$paymentsTable->delete($payment)) {
                    return false;
                }

                $doc->pipeline_status = NoveltyConstants::STATUS_TESORERIA;
                if (!$docsTable->save($doc)) {
                    return false;
                }

                return $this->advanceChildren(
                    $noveltiesTable,
                    (int)$docId,
                    NoveltyConstants::STATUS_TESORERIA,
                    $rejectedBy,
                );
            },
        );

        if ($ok === false) {
            return ServiceResult::fail('No se pudo rechazar el pago.');
        }

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
        $ok = $connection->transactional(function () use ($docsTable, $noveltiesTable, $doc, $confirmedBy) {
            $doc->pipeline_status = NoveltyConstants::STATUS_PAGADA;
            if (!$docsTable->save($doc)) {
                return false;
            }

            // Iterar para registrar histórico por novedad. updateAll bypassa
            // callbacks; no preserva trazabilidad por hijo, que es lo que el
            // resto de módulos del feature ya garantiza (Invoices, PettyCash,
            // Refund vía recordStatusChange por hija).
            $children = $noveltiesTable->find()
                ->where([
                    'liquidation_doc_id' => $doc->id,
                    'pipeline_status' => NoveltyConstants::STATUS_VERIFICACION_PAGO,
                ])
                ->all();

            foreach ($children as $novelty) {
                $previousStatus = $novelty->pipeline_status;
                $novelty->pipeline_status = NoveltyConstants::STATUS_PAGADA;
                if (!$noveltiesTable->save($novelty)) {
                    return false;
                }
                $this->noveltyHistory->recordStatusChange(
                    $novelty->id,
                    $previousStatus,
                    NoveltyConstants::STATUS_PAGADA,
                    $confirmedBy,
                );
            }

            return true;
        });

        if ($ok === false) {
            return ServiceResult::fail('No se pudo confirmar el pago del documento.');
        }

        return ServiceResult::ok('Pago confirmado. El documento y sus novedades quedaron como pagados.');
    }

    /**
     * Avanza las novedades hijas de un documento de liquidación al nuevo estado
     * iterando una a una para preservar la trazabilidad por hija. updateAll
     * bypassa callbacks y no registra historial; este patrón es el mismo que
     * confirmPayment ya usa (recordStatusChange por hija).
     *
     * Debe invocarse dentro de un `transactional`: si un `save()` falla retorna
     * false para que el callamante aborte la transacción y haga rollback.
     *
     * @param \Cake\ORM\Table $noveltiesTable Tabla de novedades.
     * @param int $docId ID del documento de liquidación.
     * @param string $newStatus Estado de destino.
     * @param int $userId Usuario que realiza el cambio.
     * @return bool true si todas las hijas se guardaron; false ante el primer fallo.
     */
    private function advanceChildren(
        Table $noveltiesTable,
        int $docId,
        string $newStatus,
        int $userId,
    ): bool {
        $children = $noveltiesTable->find()
            ->where(['liquidation_doc_id' => $docId])
            ->all();

        foreach ($children as $novelty) {
            $previousStatus = (string)$novelty->pipeline_status;
            if ($previousStatus === $newStatus) {
                continue;
            }
            $novelty->pipeline_status = $newStatus;
            if (!$noveltiesTable->save($novelty)) {
                return false;
            }
            $this->noveltyHistory->recordStatusChange(
                (int)$novelty->id,
                $previousStatus,
                $newStatus,
                $userId,
            );
        }

        return true;
    }
}

<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AdvanceConstants;
use App\Constants\Domain\Advance\PipelineStatus as AdvancePipelineStatus;
use App\Constants\InvoiceConstants;
use App\Event\AdvanceLegalizedEvent;
use App\Model\Entity\AdvanceLegalization;
use App\Model\Entity\Invoice;
use App\Service\Pipeline\Advance\AdvanceLegalizationPipelineStateRegistry;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\Event;
use Cake\Event\EventManagerInterface;
use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;
use Throwable;

class AdvanceLegalizationService
{
    private AdvanceLegalizationPipelineStateRegistry $stateRegistry;

    public function __construct(
        private readonly EventManagerInterface $events,
        private readonly AdvanceLegalizationHistoryService $historyService,
        private readonly AdvanceLegalizationDocumentService $documentService,
        ?AdvanceLegalizationPipelineStateRegistry $stateRegistry = null,
    ) {
        $this->stateRegistry = $stateRegistry ?? new AdvanceLegalizationPipelineStateRegistry();
    }

    /**
     * Idempotently create the advance_legalizations row for a paid Anticipo.
     *
     * @param \App\Model\Entity\Invoice $advance Anticipo invoice (must be in `pagada`).
     * @param int $userId User id triggering the initialization.
     * @return \App\Service\ServiceResult
     */
    public function initialize(Invoice $advance, int $userId): ServiceResult
    {
        if (($advance->document_type ?? null) !== InvoiceConstants::DOCTYPE_ANTICIPO) {
            return ServiceResult::fail('Solo los Anticipos pueden iniciar legalización.');
        }
        if (($advance->pipeline_status ?? null) !== InvoiceConstants::STATUS_PAGADA) {
            return ServiceResult::fail('El anticipo debe estar Pagada para iniciar legalización.');
        }

        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        $existing = $table->find()->where(['advance_invoice_id' => $advance->id])->first();
        if ($existing) {
            return ServiceResult::ok($existing);
        }

        $entity = $table->newEntity([
            'advance_invoice_id' => $advance->id,
            'created_by' => $userId,
        ]);
        // `status` es non-accessible (MI-002): direct assignment por el service.
        $entity->status = AdvanceConstants::STATUS_VALIDACION;

        if (!$table->save($entity)) {
            return ServiceResult::fail(
                'No se pudo crear la legalización: ' . $this->_firstErrorMessage($entity->getErrors()),
            );
        }

        return ServiceResult::ok($entity);
    }

    /**
     * Returns true if there is an advance_legalizations row linked to the given anticipo invoice id.
     */
    public function hasLegalization(int $invoiceId): bool
    {
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        return $table->find()
            ->where(['advance_invoice_id' => $invoiceId])
            ->count() > 0;
    }

    /**
     * Bulk-link Legalización invoices to this advance.
     *
     * @param array<int> $invoiceIds
     */
    public function linkInvoices(AdvanceLegalization $leg, array $invoiceIds, int $userId): ServiceResult
    {
        if (!$leg->canLinkInvoices()) {
            return ServiceResult::fail('Solo se pueden vincular facturas en estado Validación.');
        }
        if (empty($invoiceIds)) {
            return ServiceResult::fail('Seleccione al menos una factura.');
        }

        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        $result = null;
        $invoices->getConnection()->transactional(
            function () use ($leg, $invoiceIds, $userId, $invoices, $legTable, &$result): bool {
                $count = $invoices->updateAll(
                    ['advance_id' => $leg->advance_invoice_id],
                    [
                        'id IN' => $invoiceIds,
                        'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
                        'advance_id IS' => null,
                    ],
                );

                $leg->updated_by = $userId;
                if (!$legTable->save($leg)) {
                    $result = ServiceResult::fail(
                        'No se pudo actualizar la legalización: ' . $this->_firstErrorMessage($leg->getErrors()),
                    );

                    return false;
                }

                $result = ServiceResult::ok(['linked' => (int)$count]);

                return true;
            },
        );

        return $result ?? ServiceResult::fail('La transacción falló.');
    }

    /**
     * Detach a single Legalización invoice from this advance.
     */
    public function unlinkInvoice(AdvanceLegalization $leg, int $invoiceId, int $userId): ServiceResult
    {
        if (!$leg->canUnlinkInvoice()) {
            return ServiceResult::fail('Solo se pueden desvincular facturas en estado Validación.');
        }

        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        $result = null;
        $invoices->getConnection()->transactional(
            function () use ($leg, $invoiceId, $userId, $invoices, $legTable, &$result): bool {
                $count = $invoices->updateAll(
                    ['advance_id' => null],
                    [
                        'id' => $invoiceId,
                        'advance_id' => $leg->advance_invoice_id,
                    ],
                );

                if ($count === 0) {
                    $result = ServiceResult::fail('La factura no estaba vinculada a este anticipo.');

                    return false;
                }

                $leg->updated_by = $userId;
                if (!$legTable->save($leg)) {
                    $result = ServiceResult::fail(
                        'No se pudo actualizar la legalización: ' . $this->_firstErrorMessage($leg->getErrors()),
                    );

                    return false;
                }

                $result = ServiceResult::ok(['unlinked' => 1]);

                return true;
            },
        );

        return $result ?? ServiceResult::fail('La transacción falló.');
    }

    /**
     * Advance from validacion → revision_firmas. Requires ≥1 linked invoice, a relation
     * document, and that every linked invoice is at least in `contabilidad`.
     */
    public function moveToRevisionFirmas(AdvanceLegalization $leg, int $userId): ServiceResult
    {
        if (!$leg->canMoveToRevision()) {
            return ServiceResult::fail('La legalización no está en Validación.');
        }

        // Los requirements (≥1 factura vinculada, todas en CONTABILIDAD, doc PDF)
        // viven en ValidacionState::validateAdvance — audit MA-010 / SU-001.
        $statusEnum = AdvancePipelineStatus::tryFrom((string)$leg->status);
        if ($statusEnum === null) {
            return ServiceResult::fail("Estado inválido: {$leg->status}");
        }
        $errors = $this->stateRegistry->get($statusEnum)->validateAdvance($leg);
        if (!empty($errors)) {
            return ServiceResult::fail($errors[0]);
        }

        return $this->_setStatus($leg, AdvanceConstants::STATUS_REVISION_FIRMAS, $userId);
    }

    /**
     * Mark the pending signature as signed and advance to contabilidad.
     */
    public function markSigned(AdvanceLegalization $leg, int $userId): ServiceResult
    {
        if (!$leg->canMarkSigned()) {
            return ServiceResult::fail('La legalización no está en Revisión y Firmas.');
        }

        $sigTable = TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures');
        $pending = $sigTable->find()
            ->where(['legalization_id' => $leg->id, 'signature_status' => AdvanceConstants::SIGNATURE_PENDING])
            ->order(['id' => 'DESC'])
            ->first();

        if (!$pending) {
            return ServiceResult::fail('No hay documento pendiente para firmar.');
        }

        $result = null;
        $sigTable->getConnection()->transactional(
            function () use ($pending, $userId, $sigTable, $leg, &$result): bool {
                $pending->signed_by_user_id = $userId;
                $pending->signed_at = date('Y-m-d H:i:s');
                $pending->signature_status = AdvanceConstants::SIGNATURE_SIGNED;
                if (!$sigTable->save($pending)) {
                    $result = ServiceResult::fail(
                        'No se pudo guardar la firma: ' . $this->_firstErrorMessage($pending->getErrors()),
                    );

                    return false;
                }

                $inner = $this->_setStatus($leg, AdvanceConstants::STATUS_CONTABILIDAD, $userId);
                if (!$inner->success) {
                    $result = $inner;

                    return false;
                }

                $result = $inner;

                return true;
            },
        );

        return $result ?? ServiceResult::fail('La transacción falló.');
    }

    /**
     * Reject signature and bounce back to validacion with a reason.
     */
    public function returnToValidacion(AdvanceLegalization $leg, string $reason, int $userId): ServiceResult
    {
        if (!$leg->canReturnToValidacion()) {
            return ServiceResult::fail('La legalización no está en Revisión y Firmas.');
        }
        if (trim($reason) === '') {
            return ServiceResult::fail('Indique el motivo de la devolución.');
        }

        $sigTable = TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures');

        $result = null;
        $sigTable->getConnection()->transactional(
            function () use ($leg, $reason, $userId, $sigTable, &$result): bool {
                $pending = $sigTable->find()
                    ->where([
                        'legalization_id' => $leg->id,
                        'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
                    ])
                    ->order(['id' => 'DESC'])
                    ->first();
                if ($pending) {
                    $pending->signature_status = AdvanceConstants::SIGNATURE_REJECTED;
                    $pending->rejection_reason = $reason;
                    if (!$sigTable->save($pending)) {
                        $result = ServiceResult::fail(
                            'No se pudo registrar el rechazo: ' . $this->_firstErrorMessage($pending->getErrors()),
                        );

                        return false;
                    }
                }

                $inner = $this->_setStatus($leg, AdvanceConstants::STATUS_VALIDACION, $userId);
                if (!$inner->success) {
                    $result = $inner;

                    return false;
                }

                $result = $inner;

                return true;
            },
        );

        return $result ?? ServiceResult::fail('La transacción falló.');
    }

    /**
     * Sum of amounts of linked Legalización invoices.
     */
    public function getLinkedTotal(AdvanceLegalization $leg): float
    {
        $invoices = TableRegistry::getTableLocator()->get('Invoices');

        return (float)$invoices->find()
            ->where([
                'advance_id' => $leg->advance_invoice_id,
                'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            ])
            ->all()
            ->sumOf('amount');
    }

    /**
     * Difference: advance.amount - sum(linked.amount).
     * - >0 means shortage (anticipo > linked invoices; beneficiary returns the rest).
     * - <0 means surplus (linked > anticipo; company refunds the beneficiary).
     *
     * @param float|null $linkedTotal Si el caller ya calculó el total vinculado,
     *     pasarlo para evitar la query redundante de getLinkedTotal (audit SU-002).
     */
    public function getDifference(AdvanceLegalization $leg, ?float $linkedTotal = null): float
    {
        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        try {
            $advance = $invoices->get($leg->advance_invoice_id);
        } catch (RecordNotFoundException) {
            // El anticipo asociado fue borrado/cascadeado; no hay diferencia que calcular.
            return 0.0;
        }

        $total = $linkedTotal ?? $this->getLinkedTotal($leg);

        return (float)$advance->amount - $total;
    }

    /**
     * Close as caso exacto when difference is zero.
     */
    public function markExact(AdvanceLegalization $leg, int $userId): ServiceResult
    {
        if (!$leg->canMarkExact()) {
            return ServiceResult::fail('La legalización no permite marcarse como exacta.');
        }

        if (abs($this->getDifference($leg)) > 0.005) {
            return ServiceResult::fail('La diferencia no es cero. Use Faltante o Sobrante.');
        }

        $leg->case_type = AdvanceConstants::CASE_EXACTO;
        $leg->legalized_at = date('Y-m-d H:i:s');

        return $this->_setStatus($leg, AdvanceConstants::STATUS_LEGALIZADA, $userId, [
            'case_type' => [null, AdvanceConstants::CASE_EXACTO],
        ]);
    }

    /**
     * Contabilidad declares a shortage (anticipo > linked invoices). The legalization
     * jumps to Tesorería awaiting the beneficiary's deposit.
     */
    public function registerShortage(AdvanceLegalization $leg, float $amount, int $userId): ServiceResult
    {
        // canRegisterShortage cubre status=contabilidad + case_type=null (MA-005).
        if (!$leg->canRegisterShortage()) {
            return ServiceResult::fail('La legalización no permite declarar un faltante.');
        }
        if ($amount <= 0) {
            return ServiceResult::fail('El monto del faltante debe ser mayor a cero.');
        }

        $leg->case_type = AdvanceConstants::CASE_FALTANTE;
        $leg->shortage_amount = $amount;

        return $this->_setStatus($leg, AdvanceConstants::STATUS_TESORERIA, $userId, [
            'case_type' => [null, AdvanceConstants::CASE_FALTANTE],
            'shortage_amount' => [null, (string)$amount],
        ]);
    }

    /**
     * Tesorería confirms the beneficiary's deposit.
     *
     * @param array{
     *     receipt_number?: string,
     *     received_at?: string,
     *     receipt_file?: \Laminas\Diactoros\UploadedFile|null,
     * } $data Payload del form: receipt_number es obligatorio, received_at en
     *     formato Y-m-d (opcional, default hoy), receipt_file opcional.
     */
    public function confirmShortageReceipt(AdvanceLegalization $leg, array $data, int $userId): ServiceResult
    {
        if (!$leg->canConfirmShortage()) {
            return ServiceResult::fail('La legalización no está esperando consignación de faltante.');
        }
        $number = trim((string)($data['receipt_number'] ?? ''));
        if ($number === '') {
            return ServiceResult::fail('El número de comprobante es obligatorio.');
        }

        $leg->shortage_receipt_number = $number;
        $leg->shortage_received_at = !empty($data['received_at'])
            ? date('Y-m-d H:i:s', strtotime($data['received_at']))
            : date('Y-m-d H:i:s');

        if (!empty($data['receipt_file']) && $data['receipt_file'] instanceof UploadedFile) {
            $upload = $this->documentService->attachShortageReceipt($leg, $data['receipt_file']);
            if (!$upload->success) {
                return $upload;
            }
            $leg->shortage_receipt_path = $upload->data;
        }

        $leg->legalized_at = date('Y-m-d H:i:s');

        return $this->_setStatus($leg, AdvanceConstants::STATUS_LEGALIZADA, $userId, [
            'shortage_receipt_number' => [null, $number],
        ]);
    }

    /**
     * Contabilidad declares a surplus (linked invoices > anticipo). The legalization
     * jumps to Tesorería awaiting the company's refund payment to the beneficiary.
     */
    public function registerSurplus(AdvanceLegalization $leg, float $amount, int $userId): ServiceResult
    {
        // canRegisterSurplus cubre status=contabilidad + case_type=null (MA-005).
        if (!$leg->canRegisterSurplus()) {
            return ServiceResult::fail('La legalización no permite declarar un sobrante.');
        }
        if ($amount <= 0) {
            return ServiceResult::fail('El monto del sobrante debe ser mayor a cero.');
        }

        $leg->case_type = AdvanceConstants::CASE_SOBRANTE;
        $leg->surplus_amount = $amount;

        return $this->_setStatus($leg, AdvanceConstants::STATUS_TESORERIA, $userId, [
            'case_type' => [null, AdvanceConstants::CASE_SOBRANTE],
            'surplus_amount' => [null, (string)$amount],
        ]);
    }

    /**
     * Crea un InvoicePayment con is_refund=true sobre el Invoice del Anticipo,
     * y deja la legalización en Tesorería esperando autorización.
     *
     * @param array{
     *     banking_entity_id?: int|null,
     *     payment_date?: string,
     * } $data Payload del form: banking_entity_id de la entidad receptora del
     *     reintegro y payment_date en formato Y-m-d (default hoy).
     */
    public function registerRefundPayment(AdvanceLegalization $leg, array $data, int $userId): ServiceResult
    {
        // canRegisterRefund cubre status=tesoreria + case_type=sobrante + sin pago previo.
        if (!$leg->canRegisterRefund()) {
            return ServiceResult::fail('La legalización no permite registrar reintegro.');
        }
        if ($leg->surplus_amount === null) {
            return ServiceResult::fail('Monto del sobrante no definido.');
        }

        $payments = TableRegistry::getTableLocator()->get('InvoicePayments');
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        $result = null;
        $payments->getConnection()->transactional(
            function () use ($leg, $data, $userId, $payments, $legTable, &$result): bool {
                $payment = $payments->newEntity([
                    'invoice_id' => $leg->advance_invoice_id,
                    'banking_entity_id' => $data['banking_entity_id'] ?? null,
                    'amount' => (float)$leg->surplus_amount,
                    'payment_date' => $data['payment_date'] ?? date('Y-m-d'),
                    'is_refund' => true,
                    'status' => InvoiceConstants::PAYMENT_RECORD_PENDING,
                    'authorized' => false,
                    'created_by' => $userId,
                ]);

                if (!$payments->save($payment)) {
                    $result = ServiceResult::fail(
                        'No se pudo crear el reintegro: ' . $this->_firstErrorMessage($payment->getErrors()),
                    );

                    return false;
                }

                // El estado del Anticipo (Invoice) queda en `pagada`: el reintegro es un
                // movimiento posterior que vive solo en la legalización.
                $oldStatus = $leg->status;
                $leg->surplus_payment_id = $payment->id;
                $leg->status = AdvanceConstants::STATUS_AUTORIZACION_PAGO;
                $leg->updated_by = $userId;
                if (!$legTable->save($leg)) {
                    $result = ServiceResult::fail(
                        'No se pudo actualizar la legalización: ' . $this->_firstErrorMessage($leg->getErrors()),
                    );

                    return false;
                }

                // Audit trail dentro de la transacción (audit SU-004).
                $this->historyService->recordStatusChange(
                    $leg->id,
                    $oldStatus,
                    AdvanceConstants::STATUS_AUTORIZACION_PAGO,
                    $userId,
                );
                $this->historyService->recordFieldChange(
                    $leg->id,
                    'surplus_payment_id',
                    null,
                    (string)$payment->id,
                    $userId,
                );

                $result = ServiceResult::ok($payment);

                return true;
            },
        );

        return $result ?? ServiceResult::fail('La transacción falló.');
    }

    /**
     * Called from InvoicePaymentService::authorizePayment when a refund payment is authorized.
     * Cierra la legalización (caso sobrante) cuando estaba esperando autorización de pago.
     */
    public function closeOnRefundAuthorized(int $paymentId, int $userId): ServiceResult
    {
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg = $legTable->find()->where(['surplus_payment_id' => $paymentId])->first();
        if (!$leg) {
            return ServiceResult::fail('No hay legalización vinculada al pago.');
        }
        if ($leg->status === AdvanceConstants::STATUS_LEGALIZADA) {
            return ServiceResult::ok($leg);
        }
        if ($leg->status !== AdvanceConstants::STATUS_AUTORIZACION_PAGO) {
            return ServiceResult::fail('La legalización no está esperando autorización de pago.');
        }

        $leg->legalized_at = date('Y-m-d H:i:s');

        return $this->_setStatus($leg, AdvanceConstants::STATUS_LEGALIZADA, $userId);
    }

    /**
     * Called from InvoicePaymentService::rejectPayment when a refund payment is rejected.
     * Devuelve la legalización a Tesorería para registrar un nuevo reintegro y limpia
     * el surplus_payment_id (el pago rechazado se conserva en historial).
     */
    public function reopenAfterRefundRejected(int $paymentId, int $userId): ServiceResult
    {
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg = $legTable->find()->where(['surplus_payment_id' => $paymentId])->first();
        if (!$leg) {
            return ServiceResult::fail('No hay legalización vinculada al pago.');
        }
        if ($leg->status !== AdvanceConstants::STATUS_AUTORIZACION_PAGO) {
            return ServiceResult::ok($leg);
        }

        $oldPaymentId = $leg->surplus_payment_id;
        $leg->surplus_payment_id = null;

        return $this->_setStatus($leg, AdvanceConstants::STATUS_TESORERIA, $userId, [
            'surplus_payment_id' => [(string)$oldPaymentId, null],
        ]);
    }

    /**
     * Aplana los errores de validación de una entidad y devuelve el primer
     * mensaje legible — evita exponer texto crudo de json_encode($entity->getErrors())
     * al usuario final (audit MI-004).
     *
     * @param array<string, mixed> $errors Errores como los devuelve Entity::getErrors().
     */
    private function _firstErrorMessage(array $errors): string
    {
        $flat = [];
        array_walk_recursive($errors, function ($message) use (&$flat): void {
            if (is_string($message) && $message !== '') {
                $flat[] = $message;
            }
        });

        return $flat[0] ?? 'Error de validación.';
    }

    /**
     * Persist a status transition and updated_by stamp. Cuando el nuevo estado es
     * STATUS_LEGALIZADA, publica AdvanceLegalizedEvent (Plan 5) en lugar de llamar
     * directamente al pipeline service. El subscriber LinkedInvoicesPromoterSubscriber
     * promueve las facturas vinculadas vía LinkedInvoiceLegalizer.
     *
     * El save, las entradas de historial y el dispatch del evento corren en una
     * misma transacción: si el subscriber lanza, el cambio de estado del leg y
     * el historial se rollbackean juntos (audit CR-004 + SU-004).
     *
     * @param array<string, array{0: scalar|null, 1: scalar|null}> $extraChanges
     *     Cambios de campo adicionales a registrar en el audit trail, formato
     *     [field => [oldValue, newValue]]. Útil para registrar case_type, montos,
     *     comprobantes en la misma transacción que el cambio de estado.
     */
    private function _setStatus(
        AdvanceLegalization $leg,
        string $newStatus,
        int $userId,
        array $extraChanges = [],
    ): ServiceResult {
        $oldStatus = $leg->status;
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        $result = null;
        $table->getConnection()->transactional(
            function () use (
                $leg,
                $oldStatus,
                $newStatus,
                $userId,
                $extraChanges,
                $table,
                &$result,
            ): bool {
                $leg->status = $newStatus;
                $leg->updated_by = $userId;
                if (!$table->save($leg)) {
                    $result = ServiceResult::fail(
                        'No se pudo guardar la legalización: ' . $this->_firstErrorMessage($leg->getErrors()),
                    );

                    return false;
                }

                $this->historyService->recordStatusChange($leg->id, $oldStatus, $newStatus, $userId);
                foreach ($extraChanges as $field => $values) {
                    [$oldVal, $newVal] = $values;
                    $this->historyService->recordFieldChange(
                        $leg->id,
                        $field,
                        $oldVal === null ? null : (string)$oldVal,
                        $newVal === null ? null : (string)$newVal,
                        $userId,
                    );
                }

                if ($newStatus === AdvanceConstants::STATUS_LEGALIZADA) {
                    try {
                        $this->events->dispatch(new Event(
                            'AdvanceLegalization.legalized',
                            null,
                            ['payload' => new AdvanceLegalizedEvent($leg, $userId)],
                        ));
                    } catch (Throwable $e) {
                        $result = ServiceResult::fail(
                            'No se pudo cerrar la legalización: ' . $e->getMessage(),
                        );

                        return false;
                    }
                }

                $result = ServiceResult::ok($leg);

                return true;
            },
        );

        return $result ?? ServiceResult::fail('La transacción falló.');
    }
}

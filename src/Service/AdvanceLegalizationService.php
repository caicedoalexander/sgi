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
use Throwable;

class AdvanceLegalizationService
{
    private AdvanceLegalizationPipelineStateRegistry $stateRegistry;

    /**
     * @param \Cake\Event\EventManagerInterface $events Event manager para publicar los eventos de legalización.
     * @param \App\Service\AdvanceLegalizationHistoryService $historyService Servicio de auditoría de la legalización.
     * @param \App\Service\AdvanceLegalizationDocumentService $documentService Servicio de documentos de la legalización.
     * @param \App\Service\Pipeline\Advance\AdvanceLegalizationPipelineStateRegistry|null $stateRegistry Registro de estados del pipeline.
     */
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
     * Devuelve la legalización del anticipo si está en Validación (estado en que
     * se puede vincular/crear-vinculado), o null. Usado por la creación directa
     * (Fase 3) para validar el contexto.
     */
    public function legalizationInValidacion(int $advanceInvoiceId): ?AdvanceLegalization
    {
        $leg = TableRegistry::getTableLocator()->get('AdvanceLegalizations')
            ->find()
            ->where(['advance_invoice_id' => $advanceInvoiceId])
            ->first();

        return $leg !== null && $leg->status === AdvanceConstants::STATUS_VALIDACION ? $leg : null;
    }

    /**
     * Registra en el historial de la legalización el vínculo de una factura creada
     * directamente vinculada (Fase 3), en paridad con linkInvoices() (invoices_linked).
     */
    public function recordDirectLink(AdvanceLegalization $leg, Invoice $invoice, int $userId): void
    {
        $this->historyService->recordFieldChange(
            $leg->id,
            'invoices_linked',
            null,
            (string)($invoice->invoice_number ?? $invoice->id),
            $userId,
        );
    }

    /**
     * Bulk-link Legalización y Recibo de Caja en `aprobacion` a este anticipo.
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
                        'advance_id IS' => null,
                        'petty_cash_record_id IS' => null,
                        'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
                        'document_type IN' => [
                            InvoiceConstants::DOCTYPE_LEGALIZACION,
                            InvoiceConstants::DOCTYPE_RECIBO_CAJA,
                        ],
                    ],
                );

                $leg->updated_by = $userId;
                if (!$legTable->save($leg)) {
                    $result = ServiceResult::fail(
                        'No se pudo actualizar la legalización: ' . $this->_firstErrorMessage($leg->getErrors()),
                    );

                    return false;
                }

                if ((int)$count > 0) {
                    $linkedNumbers = $invoices->find()
                        ->select(['invoice_number'])
                        ->where([
                            'id IN' => $invoiceIds,
                            'advance_id' => $leg->advance_invoice_id,
                        ])
                        ->all()
                        ->extract('invoice_number')
                        ->toList();
                    $this->historyService->recordFieldChange(
                        $leg->id,
                        'invoices_linked',
                        null,
                        $this->_summarizeInvoiceNumbers($linkedNumbers),
                        $userId,
                    );
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
                // Capturar el invoice_number ANTES de limpiar advance_id.
                $invoice = $invoices->find()
                    ->select(['invoice_number'])
                    ->where(['id' => $invoiceId, 'advance_id' => $leg->advance_invoice_id])
                    ->first();

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

                $this->historyService->recordFieldChange(
                    $leg->id,
                    'invoices_unlinked',
                    (string)($invoice->invoice_number ?? $invoiceId),
                    null,
                    $userId,
                );

                $result = ServiceResult::ok(['unlinked' => 1]);

                return true;
            },
        );

        return $result ?? ServiceResult::fail('La transacción falló.');
    }

    /**
     * Advance validacion → aprobacion. Requiere ≥1 factura vinculada y el PDF de
     * relación (ValidacionState, MA-006 ya subsumida). Arma el grupo para la
     * aprobación de área en lote.
     */
    public function moveToAprobacion(AdvanceLegalization $leg, int $userId): ServiceResult
    {
        if (!$leg->canMoveToAprobacion()) {
            return ServiceResult::fail('La legalización no está en Validación.');
        }
        $statusEnum = AdvancePipelineStatus::tryFrom((string)$leg->status);
        if ($statusEnum === null) {
            return ServiceResult::fail("Estado inválido: {$leg->status}");
        }
        $errors = $this->stateRegistry->get($statusEnum)->validateAdvance($leg);
        if (!empty($errors)) {
            return ServiceResult::fail($errors[0]);
        }

        return $this->_setStatus($leg, AdvanceConstants::STATUS_APROBACION, $userId);
    }

    /**
     * Regresa aprobacion → validacion para editar el grupo (vincular/desvincular).
     * El controller invalida las aprobaciones activas (supersedeAll) tras esta llamada.
     */
    public function returnToValidacionFromAprobacion(AdvanceLegalization $leg, int $userId): ServiceResult
    {
        if (!$leg->canReturnFromAprobacion()) {
            return ServiceResult::fail('La legalización no está en Aprobación.');
        }

        return $this->_setStatus($leg, AdvanceConstants::STATUS_VALIDACION, $userId);
    }

    /**
     * Consolidate aprobacion → revision_firmas. Gate: quórum de aprobadores +
     * DIAN por hija (AprobacionState). Efecto: mueve cada factura hija de
     * invoice-aprobacion a invoice-contabilidad + area_approval=Aprobada
     * (propagación leg→facturas; MA-006 garantizada por construcción).
     */
    public function moveToRevisionFirmas(AdvanceLegalization $leg, int $userId): ServiceResult
    {
        if (!$leg->canConsolidateApproval()) {
            return ServiceResult::fail('La legalización no está en Aprobación.');
        }

        $statusEnum = AdvancePipelineStatus::tryFrom((string)$leg->status);
        if ($statusEnum === null) {
            return ServiceResult::fail("Estado inválido: {$leg->status}");
        }
        $errors = $this->stateRegistry->get($statusEnum)->validateAdvance($leg);
        if (!empty($errors)) {
            return ServiceResult::fail($errors[0]);
        }

        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        $result = null;
        $legTable->getConnection()->transactional(
            function () use ($leg, $userId, $invoices, &$result): bool {
                // Propagación leg→facturas: mueve las hijas en invoice-aprobacion a
                // invoice-contabilidad + area_approval (MA-006 como efecto).
                $invoices->updateAll(
                    [
                        'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
                        'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
                        'area_approval_date' => date('Y-m-d H:i:s'),
                    ],
                    [
                        'advance_id' => $leg->advance_invoice_id,
                        'document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
                        'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
                    ],
                );

                $inner = $this->_setStatus($leg, AdvanceConstants::STATUS_REVISION_FIRMAS, $userId);
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
            ->orderBy(['id' => 'DESC'])
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
     * Rechaza la firma y devuelve la legalización a Aprobación (paso anterior)
     * con un motivo. Inverso de la consolidación: reversa la propagación de las
     * facturas hijas (invoice-contabilidad → invoice-aprobacion), conservando su
     * `area_approval` y los aprobadores del grupo para poder re-consolidar.
     */
    public function returnToAprobacion(AdvanceLegalization $leg, string $reason, int $userId): ServiceResult
    {
        if (!$leg->canReturnToAprobacion()) {
            return ServiceResult::fail('La legalización no está en Revisión y Firmas.');
        }
        if (trim($reason) === '') {
            return ServiceResult::fail('Indique el motivo de la devolución.');
        }

        $sigTable = TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures');
        $invoices = TableRegistry::getTableLocator()->get('Invoices');

        $result = null;
        $sigTable->getConnection()->transactional(
            function () use ($leg, $reason, $userId, $sigTable, $invoices, &$result): bool {
                $pending = $sigTable->find()
                    ->where([
                        'legalization_id' => $leg->id,
                        'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
                    ])
                    ->orderBy(['id' => 'DESC'])
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
                    $this->historyService->recordFieldChange(
                        $leg->id,
                        'signature_rejection_reason',
                        null,
                        $reason,
                        $userId,
                    );
                }

                // Inverso de moveToRevisionFirmas: reversa las hijas consolidadas
                // a invoice-aprobacion. Solo el pipeline_status; area_approval se
                // conserva (lo fijó onAllApproved en aprobacion, no la consolidación).
                $invoices->updateAll(
                    ['pipeline_status' => InvoiceConstants::STATUS_APROBACION],
                    [
                        'advance_id' => $leg->advance_invoice_id,
                        'document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
                        'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
                    ],
                );

                $inner = $this->_setStatus($leg, AdvanceConstants::STATUS_APROBACION, $userId);
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
     * Sum of amounts of linked invoices (Legalización and Recibo de Caja).
     */
    public function getLinkedTotal(AdvanceLegalization $leg): float
    {
        $invoices = TableRegistry::getTableLocator()->get('Invoices');

        return (float)$invoices->find()
            ->where([
                'advance_id' => $leg->advance_invoice_id,
                'document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
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
     * Close as caso exacto when difference is zero. La causación del paso
     * Contabilidad es obligatoria (gate en ContabilidadState).
     *
     * @param array{accrued?: bool, accrual_date?: string|null, ready_for_payment?: string|null} $accounting
     */
    public function markExact(AdvanceLegalization $leg, array $accounting, int $userId): ServiceResult
    {
        if (!$leg->canMarkExact()) {
            return ServiceResult::fail('La legalización no permite marcarse como exacta.');
        }

        if (abs($this->getDifference($leg)) > 0.005) {
            return ServiceResult::fail('La diferencia no es cero. Use Faltante o Sobrante.');
        }

        $applied = $this->_applyAccounting($leg, $accounting);
        if (!empty($applied['errors'])) {
            return ServiceResult::fail($applied['errors'][0]);
        }

        $leg->case_type = AdvanceConstants::CASE_EXACTO;
        $leg->legalized_at = date('Y-m-d H:i:s');

        return $this->_setStatus($leg, AdvanceConstants::STATUS_LEGALIZADA, $userId, array_merge([
            'case_type' => [null, AdvanceConstants::CASE_EXACTO],
        ], $applied['changes']));
    }

    /**
     * Contabilidad declares a shortage (anticipo > linked invoices). The legalization
     * jumps to Tesorería awaiting the beneficiary's deposit. La causación del paso
     * es obligatoria (gate en ContabilidadState).
     *
     * @param array{accrued?: bool, accrual_date?: string|null, ready_for_payment?: string|null} $accounting
     */
    public function registerShortage(
        AdvanceLegalization $leg,
        float $amount,
        array $accounting,
        int $userId,
    ): ServiceResult {
        // canRegisterShortage cubre status=contabilidad + case_type=null (MA-005).
        if (!$leg->canRegisterShortage()) {
            return ServiceResult::fail('La legalización no permite declarar un faltante.');
        }
        if ($amount <= 0) {
            return ServiceResult::fail('El monto del faltante debe ser mayor a cero.');
        }

        $applied = $this->_applyAccounting($leg, $accounting);
        if (!empty($applied['errors'])) {
            return ServiceResult::fail($applied['errors'][0]);
        }

        $leg->case_type = AdvanceConstants::CASE_FALTANTE;
        $leg->shortage_amount = $amount;

        return $this->_setStatus($leg, AdvanceConstants::STATUS_TESORERIA, $userId, array_merge([
            'case_type' => [null, AdvanceConstants::CASE_FALTANTE],
            'shortage_amount' => [null, (string)$amount],
        ], $applied['changes']));
    }

    /**
     * Tesorería confirms the beneficiary's deposit.
     *
     * @param array{
     *     receipt_number?: string,
     *     received_at?: string,
     * } $data Payload del form: receipt_number es obligatorio, received_at en
     *     formato Y-m-d (opcional, default hoy; si viene y no es parseable, falla).
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

        // Se valida antes de mutar la entidad: una fecha no parseable no debe
        // dejar cambios parciales en la legalización.
        $rawReceivedAt = trim((string)($data['received_at'] ?? ''));
        $receivedAt = date('Y-m-d H:i:s');
        if ($rawReceivedAt !== '') {
            $timestamp = strtotime($rawReceivedAt);
            if ($timestamp === false) {
                return ServiceResult::fail('La fecha de consignación no es válida.');
            }
            $receivedAt = date('Y-m-d H:i:s', $timestamp);
        }

        $leg->shortage_receipt_number = $number;
        $leg->shortage_received_at = $receivedAt;

        $leg->legalized_at = date('Y-m-d H:i:s');

        return $this->_setStatus($leg, AdvanceConstants::STATUS_LEGALIZADA, $userId, [
            'shortage_receipt_number' => [null, $number],
        ]);
    }

    /**
     * Contabilidad declares a surplus (linked invoices > anticipo). The legalization
     * jumps to Tesorería awaiting the company's refund payment to the beneficiary.
     * La causación del paso es obligatoria (gate en ContabilidadState).
     *
     * @param array{accrued?: bool, accrual_date?: string|null, ready_for_payment?: string|null} $accounting
     */
    public function registerSurplus(
        AdvanceLegalization $leg,
        float $amount,
        array $accounting,
        int $userId,
    ): ServiceResult {
        // canRegisterSurplus cubre status=contabilidad + case_type=null (MA-005).
        if (!$leg->canRegisterSurplus()) {
            return ServiceResult::fail('La legalización no permite declarar un sobrante.');
        }
        if ($amount <= 0) {
            return ServiceResult::fail('El monto del sobrante debe ser mayor a cero.');
        }

        $applied = $this->_applyAccounting($leg, $accounting);
        if (!empty($applied['errors'])) {
            return ServiceResult::fail($applied['errors'][0]);
        }

        $leg->case_type = AdvanceConstants::CASE_SOBRANTE;
        $leg->surplus_amount = $amount;

        return $this->_setStatus($leg, AdvanceConstants::STATUS_TESORERIA, $userId, array_merge([
            'case_type' => [null, AdvanceConstants::CASE_SOBRANTE],
            'surplus_amount' => [null, (string)$amount],
        ], $applied['changes']));
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
     * Mueve la legalización (caso sobrante) de `autorizacion_pago` → `verificacion_pago`,
     * dejándola pendiente de que Tesorería confirme la ejecución efectiva del reintegro
     * vía confirmRefundExecuted().
     *
     * (Antes esta operación cerraba directamente a `legalizada`; el feature
     * "Verificación de pago" inserta el paso intermedio de confirmación.)
     */
    public function closeOnRefundAuthorized(int $paymentId, int $userId): ServiceResult
    {
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg = $legTable->find()->where(['surplus_payment_id' => $paymentId])->first();
        if (!$leg) {
            return ServiceResult::fail('No hay legalización vinculada al pago.');
        }
        if (
            in_array($leg->status, [
            AdvanceConstants::STATUS_VERIFICACION_PAGO,
            AdvanceConstants::STATUS_LEGALIZADA,
            ], true)
        ) {
            return ServiceResult::ok($leg);
        }
        if ($leg->status !== AdvanceConstants::STATUS_AUTORIZACION_PAGO) {
            return ServiceResult::fail('La legalización no está esperando autorización de pago.');
        }

        return $this->_setStatus($leg, AdvanceConstants::STATUS_VERIFICACION_PAGO, $userId);
    }

    /**
     * Tesorería confirma que el reintegro al beneficiario ya se ejecutó.
     * Cierra la legalización (caso sobrante) moviéndola de `verificacion_pago` → `legalizada`.
     * El dispatch de AdvanceLegalizedEvent ocurre dentro de _setStatus().
     */
    public function confirmRefundExecuted(AdvanceLegalization $leg, int $userId): ServiceResult
    {
        if ($leg->status === AdvanceConstants::STATUS_LEGALIZADA) {
            return ServiceResult::ok($leg);
        }
        if ($leg->status !== AdvanceConstants::STATUS_VERIFICACION_PAGO) {
            return ServiceResult::fail('La legalización no está en verificación de pago.');
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
     * Construye un resumen legible de los números de factura para el audit
     * trail de vinculación. Una sola factura → su número; varias → conteo +
     * lista entre paréntesis.
     *
     * @param array<int, string|null> $numbers Números de factura vinculados.
     */
    private function _summarizeInvoiceNumbers(array $numbers): string
    {
        $clean = array_values(array_filter(array_map('strval', $numbers), fn($n) => $n !== ''));
        if (count($clean) === 1) {
            return $clean[0];
        }

        return sprintf('%d facturas (%s)', count($clean), implode(', ', $clean));
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
     * Asigna los campos de causación del paso Contabilidad sobre la legalización
     * y devuelve los errores del gate más los cambios listos para el audit trail.
     *
     * Los 3 campos son non-accessible (MI-002): se asignan por propiedad directa.
     *
     * Los valores originales se capturan ANTES de mutar. `recordFieldChange()`
     * descarta los cambios donde old === new, así que leerlos después de asignar
     * dejaría el historial vacío sin ningún error visible.
     *
     * @param array{accrued?: bool, accrual_date?: string|null, ready_for_payment?: string|null} $accounting
     * @return array{errors: array<string>, changes: array<string, array{0: scalar|null, 1: scalar|null}>}
     */
    private function _applyAccounting(AdvanceLegalization $leg, array $accounting): array
    {
        $oldAccrued = (bool)($leg->accrued ?? false);
        $oldDate = $leg->accrual_date;
        $oldReady = $leg->ready_for_payment;

        $newAccrued = (bool)($accounting['accrued'] ?? false);
        $rawDate = trim((string)($accounting['accrual_date'] ?? ''));
        $newDate = null;
        if ($rawDate !== '') {
            $ts = strtotime($rawDate);
            if ($ts !== false) {
                $newDate = date('Y-m-d', $ts);
            }
        }
        $rawReady = trim((string)($accounting['ready_for_payment'] ?? ''));
        $newReady = $rawReady !== '' ? $rawReady : null;

        $leg->accrued = $newAccrued;
        $leg->accrual_date = $newDate;
        $leg->ready_for_payment = $newReady;

        $oldDateStr = $oldDate === null
            ? null
            : (is_string($oldDate) ? $oldDate : $oldDate->format('Y-m-d'));

        return [
            'errors' => $this->stateRegistry
                ->get(AdvancePipelineStatus::CONTABILIDAD)
                ->validateAdvance($leg),
            'changes' => [
                'accrued' => [$oldAccrued ? 'Sí' : 'No', $newAccrued ? 'Sí' : 'No'],
                'accrual_date' => [$oldDateStr, $newDate],
                'ready_for_payment' => [$oldReady, $newReady],
            ],
        ];
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

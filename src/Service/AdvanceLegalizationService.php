<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Event\AdvanceLegalizedEvent;
use App\Model\Entity\AdvanceLegalization;
use App\Model\Entity\Invoice;
use App\Service\Trait\DocumentUploadTrait;
use Cake\Event\Event;
use Cake\Event\EventManagerInterface;
use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;

class AdvanceLegalizationService
{
    use DocumentUploadTrait;

    public function __construct(
        private readonly EventManagerInterface $events,
    ) {
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
            'status' => AdvanceConstants::STATUS_VALIDACION,
            'created_by' => $userId,
        ]);

        if (!$table->save($entity)) {
            return ServiceResult::fail(
                'No se pudo crear la legalización: ' . json_encode($entity->getErrors()),
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
        if ($leg->status !== AdvanceConstants::STATUS_VALIDACION) {
            return ServiceResult::fail('Solo se pueden vincular facturas en estado Validación.');
        }
        if (empty($invoiceIds)) {
            return ServiceResult::fail('Seleccione al menos una factura.');
        }

        $invoices = TableRegistry::getTableLocator()->get('Invoices');

        $count = $invoices->updateAll(
            ['advance_id' => $leg->advance_invoice_id],
            [
                'id IN' => $invoiceIds,
                'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
                'advance_id IS' => null,
            ],
        );

        $this->_touchUpdatedBy($leg, $userId);

        return ServiceResult::ok(['linked' => (int)$count]);
    }

    /**
     * Detach a single Legalización invoice from this advance.
     */
    public function unlinkInvoice(AdvanceLegalization $leg, int $invoiceId, int $userId): ServiceResult
    {
        if ($leg->status !== AdvanceConstants::STATUS_VALIDACION) {
            return ServiceResult::fail('Solo se pueden desvincular facturas en estado Validación.');
        }

        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $count = $invoices->updateAll(
            ['advance_id' => null],
            [
                'id' => $invoiceId,
                'advance_id' => $leg->advance_invoice_id,
            ],
        );

        if ($count === 0) {
            return ServiceResult::fail('La factura no estaba vinculada a este anticipo.');
        }

        $this->_touchUpdatedBy($leg, $userId);

        return ServiceResult::ok(['unlinked' => 1]);
    }

    /**
     * Save the relation-of-invoices document; supersedes any pending signature row.
     */
    public function attachRelationDocument(AdvanceLegalization $leg, UploadedFile $file, int $userId): ServiceResult
    {
        $allowed = [AdvanceConstants::STATUS_VALIDACION, AdvanceConstants::STATUS_REVISION_FIRMAS];
        if (!in_array($leg->status, $allowed, true)) {
            return ServiceResult::fail('Solo se puede subir el documento en Validación o Revisión y Firmas.');
        }

        $result = $this->uploadAndSave(
            $file,
            'AdvanceLegalizationSignatures',
            'advances/' . $leg->id,
            'leg_',
            [
                'legalization_id' => $leg->id,
                'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
            ],
        );

        if (is_string($result)) {
            return ServiceResult::fail($result);
        }

        // Mark prior pending docs as superseded by deleting them — keep history simple.
        $sigTable = TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures');
        $sigTable->deleteAll([
            'legalization_id' => $leg->id,
            'id !=' => $result->id,
            'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
        ]);

        $this->_touchUpdatedBy($leg, $userId);

        return ServiceResult::ok($result);
    }

    /**
     * Advance from validacion → revision_firmas. Requires ≥1 linked invoice, a relation
     * document, and that every linked invoice is at least in `contabilidad`.
     */
    public function moveToRevisionFirmas(AdvanceLegalization $leg, int $userId): ServiceResult
    {
        if ($leg->status !== AdvanceConstants::STATUS_VALIDACION) {
            return ServiceResult::fail('La legalización no está en Validación.');
        }

        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $linked = $invoices->find()
            ->where([
                'advance_id' => $leg->advance_invoice_id,
                'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            ])
            ->all();

        if ($linked->isEmpty()) {
            return ServiceResult::fail('Vincule al menos una factura antes de avanzar.');
        }

        $allowedStatuses = [
            InvoiceConstants::STATUS_CONTABILIDAD,
            InvoiceConstants::STATUS_PAGADA,
        ];
        foreach ($linked as $li) {
            if (!in_array($li->pipeline_status, $allowedStatuses, true)) {
                return ServiceResult::fail(
                    'Todas las facturas vinculadas deben estar al menos en Contabilidad. '
                    . 'Falta: factura ' . ($li->invoice_number ?: '#' . $li->id),
                );
            }
        }

        $sigTable = TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures');
        $hasDoc = $sigTable->exists([
            'legalization_id' => $leg->id,
            'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
        ]);
        if (!$hasDoc) {
            return ServiceResult::fail('Debe adjuntar la relación de facturas (PDF).');
        }

        return $this->_setStatus($leg, AdvanceConstants::STATUS_REVISION_FIRMAS, $userId);
    }

    /**
     * Mark the pending signature as signed and advance to contabilidad.
     */
    public function markSigned(AdvanceLegalization $leg, int $userId): ServiceResult
    {
        if ($leg->status !== AdvanceConstants::STATUS_REVISION_FIRMAS) {
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

        $pending->signed_by_user_id = $userId;
        $pending->signed_at = date('Y-m-d H:i:s');
        $pending->signature_status = AdvanceConstants::SIGNATURE_SIGNED;
        $sigTable->save($pending);

        return $this->_setStatus($leg, AdvanceConstants::STATUS_CONTABILIDAD, $userId);
    }

    /**
     * Reject signature and bounce back to validacion with a reason.
     */
    public function returnToValidacion(AdvanceLegalization $leg, string $reason, int $userId): ServiceResult
    {
        if ($leg->status !== AdvanceConstants::STATUS_REVISION_FIRMAS) {
            return ServiceResult::fail('La legalización no está en Revisión y Firmas.');
        }
        if (trim($reason) === '') {
            return ServiceResult::fail('Indique el motivo de la devolución.');
        }

        $sigTable = TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures');
        $pending = $sigTable->find()
            ->where(['legalization_id' => $leg->id, 'signature_status' => AdvanceConstants::SIGNATURE_PENDING])
            ->order(['id' => 'DESC'])
            ->first();
        if ($pending) {
            $pending->signature_status = AdvanceConstants::SIGNATURE_REJECTED;
            $pending->rejection_reason = $reason;
            $sigTable->save($pending);
        }

        return $this->_setStatus($leg, AdvanceConstants::STATUS_VALIDACION, $userId);
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
     */
    public function getDifference(AdvanceLegalization $leg): float
    {
        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $advance = $invoices->get($leg->advance_invoice_id);

        return (float)$advance->amount - $this->getLinkedTotal($leg);
    }

    /**
     * Close as caso exacto when difference is zero.
     */
    public function markExact(AdvanceLegalization $leg, int $userId): ServiceResult
    {
        if ($leg->status !== AdvanceConstants::STATUS_CONTABILIDAD) {
            return ServiceResult::fail('La legalización no está en Contabilidad.');
        }

        if (abs($this->getDifference($leg)) > 0.005) {
            return ServiceResult::fail('La diferencia no es cero. Use Faltante o Sobrante.');
        }

        $leg->case_type = AdvanceConstants::CASE_EXACTO;
        $leg->legalized_at = date('Y-m-d H:i:s');

        return $this->_setStatus($leg, AdvanceConstants::STATUS_LEGALIZADA, $userId);
    }

    /**
     * Contabilidad declares a shortage (anticipo > linked invoices). The legalization
     * jumps to Tesorería awaiting the beneficiary's deposit.
     */
    public function registerShortage(AdvanceLegalization $leg, float $amount, int $userId): ServiceResult
    {
        if ($leg->status !== AdvanceConstants::STATUS_CONTABILIDAD) {
            return ServiceResult::fail('La legalización no está en Contabilidad.');
        }
        if ($amount <= 0) {
            return ServiceResult::fail('El monto del faltante debe ser mayor a cero.');
        }

        $leg->case_type = AdvanceConstants::CASE_FALTANTE;
        $leg->shortage_amount = $amount;

        return $this->_setStatus($leg, AdvanceConstants::STATUS_TESORERIA, $userId);
    }

    /**
     * Tesorería confirms the beneficiary's deposit. Payload keys:
     *   - receipt_number (string, required)
     *   - received_at (Y-m-d, optional)
     *   - receipt_file (UploadedFile, optional)
     */
    public function confirmShortageReceipt(AdvanceLegalization $leg, array $data, int $userId): ServiceResult
    {
        $isWaiting = $leg->status === AdvanceConstants::STATUS_TESORERIA
            && $leg->case_type === AdvanceConstants::CASE_FALTANTE;
        if (!$isWaiting) {
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
            $file = $data['receipt_file'];
            $uploadDir = WWW_ROOT . 'uploads/advances/' . $leg->id;
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $ext = pathinfo($file->getClientFilename() ?? '', PATHINFO_EXTENSION) ?: 'pdf';
            $name = uniqid('shortage_') . '.' . $ext;
            $file->moveTo($uploadDir . DS . $name);
            $leg->shortage_receipt_path = 'uploads/advances/' . $leg->id . '/' . $name;
        }

        $leg->legalized_at = date('Y-m-d H:i:s');

        return $this->_setStatus($leg, AdvanceConstants::STATUS_LEGALIZADA, $userId);
    }

    /**
     * Contabilidad declares a surplus (linked invoices > anticipo). The legalization
     * jumps to Tesorería awaiting the company's refund payment to the beneficiary.
     */
    public function registerSurplus(AdvanceLegalization $leg, float $amount, int $userId): ServiceResult
    {
        if ($leg->status !== AdvanceConstants::STATUS_CONTABILIDAD) {
            return ServiceResult::fail('La legalización no está en Contabilidad.');
        }
        if ($amount <= 0) {
            return ServiceResult::fail('El monto del sobrante debe ser mayor a cero.');
        }

        $leg->case_type = AdvanceConstants::CASE_SOBRANTE;
        $leg->surplus_amount = $amount;

        return $this->_setStatus($leg, AdvanceConstants::STATUS_TESORERIA, $userId);
    }

    /**
     * Crea un InvoicePayment con is_refund=true sobre el Invoice del Anticipo,
     * y deja la legalización en Tesorería esperando autorización.
     */
    public function registerRefundPayment(AdvanceLegalization $leg, array $data, int $userId): ServiceResult
    {
        $isWaiting = $leg->status === AdvanceConstants::STATUS_TESORERIA
            && $leg->case_type === AdvanceConstants::CASE_SOBRANTE;
        if (!$isWaiting) {
            return ServiceResult::fail('La legalización no está esperando reintegro.');
        }
        if (!empty($leg->surplus_payment_id)) {
            return ServiceResult::fail('Ya existe un pago de reintegro registrado.');
        }
        if ($leg->surplus_amount === null) {
            return ServiceResult::fail('Monto del sobrante no definido.');
        }

        $payments = TableRegistry::getTableLocator()->get('InvoicePayments');
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        $connection = $payments->getConnection();

        return $connection->transactional(function () use ($leg, $data, $userId, $payments, $legTable) {
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
                return ServiceResult::fail('No se pudo crear el reintegro: ' . json_encode($payment->getErrors()));
            }

            // El estado del Anticipo (Invoice) queda en `pagada`: el reintegro es un
            // movimiento posterior que vive solo en la legalización.
            $leg->surplus_payment_id = $payment->id;
            $leg->status = AdvanceConstants::STATUS_AUTORIZACION_PAGO;
            $leg->updated_by = $userId;
            if (!$legTable->save($leg)) {
                return ServiceResult::fail('No se pudo actualizar la legalización: ' . json_encode($leg->getErrors()));
            }

            return ServiceResult::ok($payment);
        });
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

        $leg->surplus_payment_id = null;

        return $this->_setStatus($leg, AdvanceConstants::STATUS_TESORERIA, $userId);
    }

    /**
     * Persist a status transition and updated_by stamp. Cuando el nuevo estado es
     * STATUS_LEGALIZADA, publica AdvanceLegalizedEvent (Plan 5) en lugar de llamar
     * directamente al pipeline service. El subscriber LinkedInvoicesPromoterSubscriber
     * promueve las facturas vinculadas vía LinkedInvoiceLegalizer.
     */
    private function _setStatus(AdvanceLegalization $leg, string $newStatus, int $userId): ServiceResult
    {
        $leg->status = $newStatus;
        $leg->updated_by = $userId;
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        if (!$table->save($leg)) {
            return ServiceResult::fail('No se pudo guardar la legalización: ' . json_encode($leg->getErrors()));
        }

        if ($newStatus === AdvanceConstants::STATUS_LEGALIZADA) {
            $this->events->dispatch(new Event(
                'AdvanceLegalization.legalized',
                null,
                ['payload' => new AdvanceLegalizedEvent($leg, $userId)],
            ));
        }

        return ServiceResult::ok($leg);
    }

    /**
     * Bump updated_by without status change.
     */
    private function _touchUpdatedBy(AdvanceLegalization $leg, int $userId): void
    {
        $leg->updated_by = $userId;
        TableRegistry::getTableLocator()->get('AdvanceLegalizations')->save($leg);
    }
}

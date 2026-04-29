<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Model\Entity\Invoice;
use App\Service\Trait\DocumentUploadTrait;
use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;

class AdvanceLegalizationService
{
    use DocumentUploadTrait;

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
     * Persist a status transition and updated_by stamp.
     */
    private function _setStatus(AdvanceLegalization $leg, string $newStatus, int $userId): ServiceResult
    {
        $leg->status = $newStatus;
        $leg->updated_by = $userId;
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        if (!$table->save($leg)) {
            return ServiceResult::fail('No se pudo guardar la legalización: ' . json_encode($leg->getErrors()));
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

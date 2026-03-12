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
    public function generateCode(): string
    {
        $year = date('Y');
        $table = TableRegistry::getTableLocator()->get('PettyCashRecords');

        $lastRecord = $table->find()
            ->where(['code LIKE' => PettyCashConstants::CODE_PREFIX . '-' . $year . '-%'])
            ->order(['code' => 'DESC'])
            ->first();

        $nextNumber = 1;
        if ($lastRecord) {
            $parts = explode('-', $lastRecord->code);
            $nextNumber = (int)end($parts) + 1;
        }

        return sprintf('%s-%s-%04d', PettyCashConstants::CODE_PREFIX, $year, $nextNumber);
    }

    public function validateGrouping(array $invoiceIds): array
    {
        $errors = [];
        if (empty($invoiceIds)) {
            return ['Debe seleccionar al menos una factura.'];
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoices = $invoicesTable->find()
            ->where(['Invoices.id IN' => $invoiceIds])
            ->all();

        $foundIds = [];
        foreach ($invoices as $invoice) {
            $foundIds[] = $invoice->id;

            if ($invoice->document_type !== 'Caja menor') {
                $errors[] = sprintf(
                    'La factura #%s no es de tipo "Caja menor".',
                    $invoice->invoice_number ?? $invoice->id,
                );
            }
            if ($invoice->pipeline_status !== 'aprobacion') {
                $errors[] = sprintf(
                    'La factura #%s no está en estado "aprobación".',
                    $invoice->invoice_number ?? $invoice->id,
                );
            }
            if (!empty($invoice->petty_cash_record_id)) {
                $errors[] = sprintf(
                    'La factura #%s ya pertenece a otro registro de Caja Menor.',
                    $invoice->invoice_number ?? $invoice->id,
                );
            }
        }

        $missingIds = array_diff($invoiceIds, $foundIds);
        foreach ($missingIds as $missingId) {
            $errors[] = sprintf('La factura con ID %d no fue encontrada.', $missingId);
        }

        return $errors;
    }

    public function addInvoices(PettyCashRecord $record, array $invoiceIds): array
    {
        $errors = $this->validateGrouping($invoiceIds);
        if (!empty($errors)) {
            return $errors;
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        foreach ($invoiceIds as $invoiceId) {
            $invoicesTable->updateAll(
                ['petty_cash_record_id' => $record->id],
                ['id' => $invoiceId],
            );
        }

        $this->calculateAndSaveTotal($record);

        return [];
    }

    public function removeInvoice(PettyCashRecord $record, int $invoiceId): bool
    {
        if (!$record->isAgrupacion()) {
            return false;
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoicesTable->updateAll(
            ['petty_cash_record_id' => null],
            ['id' => $invoiceId, 'petty_cash_record_id' => $record->id],
        );

        $this->calculateAndSaveTotal($record);

        return true;
    }

    public function calculateAndSaveTotal(PettyCashRecord $record): void
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $result = $invoicesTable->find()
            ->where(['petty_cash_record_id' => $record->id])
            ->select(['total' => $invoicesTable->find()->func()->sum('amount')])
            ->first();

        $table = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $record->total_amount = (float)($result->total ?? 0);
        $table->save($record);
    }

    public function advanceStatus(PettyCashRecord $record, int $userId): array
    {
        $currentStatus = $record->status;
        $nextStatus = PettyCashConstants::TRANSITIONS[$currentStatus] ?? null;

        if ($nextStatus === null) {
            return ['success' => false, 'error' => 'Este registro ya está en su estado final.'];
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoices = $invoicesTable->find()
            ->where(['petty_cash_record_id' => $record->id])
            ->all()
            ->toArray();

        if (empty($invoices)) {
            return ['success' => false, 'error' => 'El registro debe tener al menos una factura agrupada.'];
        }

        // Validate transition requirements specific to each petty cash step
        $validationErrors = $this->validatePettyCashTransition($currentStatus, $invoices, $record);
        if (!empty($validationErrors)) {
            return [
                'success' => false,
                'error' => 'No se puede avanzar. ' . implode('. ', $validationErrors),
            ];
        }

        $connection = $invoicesTable->getConnection();

        return $connection->transactional(function () use ($record, $nextStatus, $currentStatus, $invoicesTable) {
            $today = date('Y-m-d');
            $updateData = [];

            if ($nextStatus === PettyCashConstants::STATUS_CONTABILIDAD) {
                // Agrupación → Contabilidad: advance invoices to contabilidad
                $updateData = [
                    'pipeline_status' => 'contabilidad',
                ];
            } elseif ($nextStatus === PettyCashConstants::STATUS_TESORERIA) {
                // Contabilidad → Tesorería: apply accounting fields from record to invoices
                $updateData = [
                    'pipeline_status' => 'tesoreria',
                    'accrued' => (bool)$record->accrued,
                    'accrual_date' => $record->accrual_date ?? $today,
                    'ready_for_payment' => $record->ready_for_payment,
                ];
            } elseif ($nextStatus === PettyCashConstants::STATUS_PAGADO) {
                // Tesorería → Pagado: apply treasury fields from record to invoices
                $updateData = [
                    'pipeline_status' => 'pagada',
                    'payment_status' => $record->payment_status ?? InvoiceConstants::PAYMENT_FULL,
                    'payment_date' => $record->payment_date ?? $today,
                ];
            }

            if (!empty($updateData)) {
                $invoicesTable->updateAll(
                    $updateData,
                    ['petty_cash_record_id' => $record->id],
                );
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
     */
    public function getTransitionErrors(PettyCashRecord $record): array
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoices = $invoicesTable->find()
            ->where(['petty_cash_record_id' => $record->id])
            ->all()
            ->toArray();

        if (empty($invoices)) {
            return ['El registro debe tener al menos una factura agrupada.'];
        }

        return $this->validatePettyCashTransition($record->status, $invoices, $record);
    }

    /**
     * Validate petty cash specific transition requirements.
     * Petty cash invoices bypass normal pipeline approval requirements.
     */
    private function validatePettyCashTransition(string $fromStatus, array $invoices, PettyCashRecord $record): array
    {
        $errors = [];

        switch ($fromStatus) {
            case PettyCashConstants::STATUS_AGRUPACION:
                // No special requirements - just need invoices grouped
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
                if (empty($record->payment_status)) {
                    $errors[] = 'Debe seleccionar un Estado de Pago.';
                }
                if (empty($record->payment_date)) {
                    $errors[] = 'Debe ingresar una Fecha de Pago.';
                }
                break;
        }

        return $errors;
    }

    public function getAvailableInvoices(array $filters = []): SelectQuery
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $query = $invoicesTable->find()
            ->contain(['Providers', 'OperationCenters'])
            ->where([
                'Invoices.document_type' => 'Caja menor',
                'Invoices.pipeline_status' => 'aprobacion',
                'Invoices.petty_cash_record_id IS' => null,
            ])
            ->order(['Invoices.issue_date' => 'ASC']);

        if (!empty($filters['date_from'])) {
            $query->where(['Invoices.issue_date >=' => $filters['date_from']]);
        }
        if (!empty($filters['date_to'])) {
            $query->where(['Invoices.issue_date <=' => $filters['date_to']]);
        }
        if (!empty($filters['operation_center_id'])) {
            $query->where(['Invoices.operation_center_id' => $filters['operation_center_id']]);
        }

        return $query;
    }

    public function canDelete(PettyCashRecord $record): bool
    {
        return $record->isAgrupacion();
    }
}

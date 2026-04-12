<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\LegalizationConstants;
use App\Model\Entity\LegalizationRecord;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;

class LegalizationService
{
    private InvoiceHistoryService $historyService;

    public function __construct(?InvoiceHistoryService $historyService = null)
    {
        $this->historyService = $historyService ?? new InvoiceHistoryService();
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

            if ($invoice->document_type !== InvoiceConstants::DOCTYPE_LEGALIZACION) {
                $errors[] = sprintf(
                    'La factura #%s no es de tipo "Legalización".',
                    $invoice->invoice_number ?? $invoice->id,
                );
            }
            if ($invoice->pipeline_status !== InvoiceConstants::STATUS_CONTABILIDAD) {
                $errors[] = sprintf(
                    'La factura #%s no está en estado "contabilidad".',
                    $invoice->invoice_number ?? $invoice->id,
                );
            }
            if (!empty($invoice->legalization_record_id)) {
                $errors[] = sprintf(
                    'La factura #%s ya pertenece a otro registro de Legalización.',
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

    public function addInvoices(LegalizationRecord $record, array $invoiceIds): array
    {
        $errors = $this->validateGrouping($invoiceIds);
        if (!empty($errors)) {
            return $errors;
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        foreach ($invoiceIds as $invoiceId) {
            $invoicesTable->updateAll(
                ['legalization_record_id' => $record->id],
                ['id' => $invoiceId],
            );
        }

        $this->calculateAndSaveTotal($record);

        return [];
    }

    public function removeInvoice(LegalizationRecord $record, int $invoiceId): bool
    {
        if (!$record->isAgrupacion()) {
            return false;
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoicesTable->updateAll(
            ['legalization_record_id' => null],
            ['id' => $invoiceId, 'legalization_record_id' => $record->id],
        );

        $this->calculateAndSaveTotal($record);

        return true;
    }

    public function calculateAndSaveTotal(LegalizationRecord $record): void
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $result = $invoicesTable->find()
            ->where(['legalization_record_id' => $record->id])
            ->select(['total' => $invoicesTable->find()->func()->sum('amount')])
            ->first();

        $table = TableRegistry::getTableLocator()->get('LegalizationRecords');
        $record->total_amount = (float)($result->total ?? 0);
        $table->save($record);
    }

    public function advanceStatus(LegalizationRecord $record, int $userId): array
    {
        $currentStatus = $record->status;
        $nextStatus = LegalizationConstants::TRANSITIONS[$currentStatus] ?? null;

        if ($nextStatus === null) {
            return ['success' => false, 'error' => 'Este registro ya está en su estado final.'];
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoices = $invoicesTable->find()
            ->where(['legalization_record_id' => $record->id])
            ->all()
            ->toArray();

        if (empty($invoices)) {
            return ['success' => false, 'error' => 'El registro debe tener al menos una factura agrupada.'];
        }

        $validationErrors = $this->validateLegalizationTransition($currentStatus, $invoices, $record);
        if (!empty($validationErrors)) {
            return [
                'success' => false,
                'error' => 'No se puede avanzar. ' . implode('. ', $validationErrors),
            ];
        }

        $connection = $invoicesTable->getConnection();

        return $connection->transactional(function () use ($record, $nextStatus, $currentStatus, $invoicesTable, $userId) {
            $today = date('Y-m-d');
            $updateData = [];

            if ($nextStatus === LegalizationConstants::STATUS_CONTABILIDAD) {
                $updateData = [
                    'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
                ];
            } elseif ($nextStatus === LegalizationConstants::STATUS_TESORERIA) {
                $updateData = [
                    'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
                    'accrued' => (bool)$record->accrued,
                    'accrual_date' => $record->accrual_date ?? $today,
                    'ready_for_payment' => $record->ready_for_payment,
                ];
            } elseif ($nextStatus === LegalizationConstants::STATUS_PAGADO) {
                $updateData = [
                    'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
                    'payment_status' => $record->payment_status ?? InvoiceConstants::PAYMENT_FULL,
                    'payment_date' => $record->payment_date ?? $today,
                ];
            }

            // Capture invoice states before bulk update for history
            $invoicesBefore = $invoicesTable->find()
                ->select(['id', 'pipeline_status'])
                ->where(['legalization_record_id' => $record->id])
                ->all()
                ->toArray();

            if (!empty($updateData)) {
                $invoicesTable->updateAll(
                    $updateData,
                    ['legalization_record_id' => $record->id],
                );

                // Record per-invoice audit trail
                $newPipelineStatus = $updateData['pipeline_status'] ?? null;
                if ($newPipelineStatus) {
                    foreach ($invoicesBefore as $inv) {
                        $this->historyService->recordStatusChange(
                            $inv->id,
                            $inv->pipeline_status,
                            $newPipelineStatus,
                            $userId,
                        );
                    }
                }
            }

            $table = TableRegistry::getTableLocator()->get('LegalizationRecords');
            $record->status = $nextStatus;
            $table->save($record);

            return [
                'success' => true,
                'nextStatus' => $nextStatus,
            ];
        });
    }

    public function getTransitionErrors(LegalizationRecord $record): array
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoices = $invoicesTable->find()
            ->where(['legalization_record_id' => $record->id])
            ->all()
            ->toArray();

        if (empty($invoices)) {
            return ['El registro debe tener al menos una factura agrupada.'];
        }

        return $this->validateLegalizationTransition($record->status, $invoices, $record);
    }

    private function validateLegalizationTransition(
        string $fromStatus,
        array $invoices,
        LegalizationRecord $record,
    ): array {
        $errors = [];

        switch ($fromStatus) {
            case LegalizationConstants::STATUS_AGRUPACION:
                break;

            case LegalizationConstants::STATUS_CONTABILIDAD:
                if (empty($record->accrued)) {
                    $errors[] = 'El registro debe estar marcado como Causado.';
                }
                if (empty($record->ready_for_payment)) {
                    $errors[] = 'Debe seleccionar "Lista para Pago".';
                }
                break;

            case LegalizationConstants::STATUS_TESORERIA:
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
                'Invoices.document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
                'Invoices.pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
                'Invoices.legalization_record_id IS' => null,
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

    public function canDelete(LegalizationRecord $record): bool
    {
        return $record->isAgrupacion();
    }
}

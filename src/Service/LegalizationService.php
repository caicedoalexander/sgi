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
    private GroupedInvoiceService $grouped;

    /**
     * @param \App\Service\InvoiceHistoryService|null $historyService History service.
     */
    public function __construct(?InvoiceHistoryService $historyService = null)
    {
        $this->grouped = new GroupedInvoiceService(
            InvoiceConstants::DOCTYPE_LEGALIZACION,
            'legalization_record_id',
            'LegalizationRecords',
            'Legalización',
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
     * @param \App\Model\Entity\LegalizationRecord $record Record.
     * @param array $invoiceIds Invoice IDs.
     * @return array
     */
    public function addInvoices(LegalizationRecord $record, array $invoiceIds): array
    {
        return $this->grouped->addInvoices($record, $invoiceIds);
    }

    /**
     * @param \App\Model\Entity\LegalizationRecord $record Record.
     * @param int $invoiceId Invoice ID.
     * @return bool
     */
    public function removeInvoice(LegalizationRecord $record, int $invoiceId): bool
    {
        return $this->grouped->removeInvoice($record, $invoiceId);
    }

    /**
     * @param \App\Model\Entity\LegalizationRecord $record Record.
     * @return void
     */
    public function calculateAndSaveTotal(LegalizationRecord $record): void
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
     * @param \App\Model\Entity\LegalizationRecord $record Record.
     * @param int $userId User ID.
     * @return array
     */
    public function advanceStatus(LegalizationRecord $record, int $userId): array
    {
        $currentStatus = $record->status;
        $nextStatus = LegalizationConstants::TRANSITIONS[$currentStatus] ?? null;

        if ($nextStatus === null) {
            return ['success' => false, 'error' => 'Este registro ya está en su estado final.'];
        }

        if ($nextStatus === LegalizationConstants::STATUS_AUT_PAGO) {
            return ['success' => false, 'error' => 'Debe registrar un pago para avanzar desde Tesorería.'];
        }

        if ($currentStatus === LegalizationConstants::STATUS_AUT_PAGO) {
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

            $table = TableRegistry::getTableLocator()->get('LegalizationRecords');
            $record->status = $nextStatus;
            $table->save($record);

            return [
                'success' => true,
                'nextStatus' => $nextStatus,
            ];
        });
    }

    /**
     * @param \App\Model\Entity\LegalizationRecord $record Record.
     * @return array
     */
    public function getTransitionErrors(LegalizationRecord $record): array
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
     * @param string $fromStatus Current status.
     * @param \App\Model\Entity\LegalizationRecord $record Record.
     * @return array
     */
    private function _validateTransition(string $fromStatus, LegalizationRecord $record): array
    {
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
                break;
        }

        return $errors;
    }

    /**
     * @param \App\Model\Entity\LegalizationRecord $record Record.
     * @return bool
     */
    public function canDelete(LegalizationRecord $record): bool
    {
        return $record->isAgrupacion();
    }
}

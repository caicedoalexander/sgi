<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Service\Interface\HistoryServiceInterface;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;

/**
 * Shared logic for services that group invoices (Petty Cash).
 */
class GroupedInvoiceService
{
    /**
     * @param string $documentType Invoice document_type value to filter by.
     * @param string $fkField FK column name on the invoices table.
     * @param string $recordTableName CakePHP table name for the parent record.
     * @param string $fkLabel Human-readable label for the FK (error messages).
     * @param \App\Service\Interface\HistoryServiceInterface $historyService History service.
     */
    public function __construct(
        private readonly string $documentType,
        private readonly string $fkField,
        private readonly string $recordTableName,
        private readonly string $fkLabel,
        private readonly HistoryServiceInterface $historyService,
    ) {
    }

    /**
     * @return \App\Service\Interface\HistoryServiceInterface
     */
    public function getHistoryService(): HistoryServiceInterface
    {
        return $this->historyService;
    }

    /**
     * @return string
     */
    public function getFkField(): string
    {
        return $this->fkField;
    }

    /**
     * Validate that invoices can be grouped.
     *
     * @param array $invoiceIds Invoice IDs to validate.
     * @return array List of error strings (empty = valid).
     */
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

            if ($invoice->document_type !== $this->documentType) {
                $errors[] = sprintf(
                    'La factura #%s no es de tipo "%s".',
                    $invoice->invoice_number ?? $invoice->id,
                    $this->fkLabel,
                );
            }
            if ($invoice->pipeline_status !== InvoiceConstants::STATUS_CONTABILIDAD) {
                $errors[] = sprintf(
                    'La factura #%s no está en estado "contabilidad".',
                    $invoice->invoice_number ?? $invoice->id,
                );
            }
            if (!empty($invoice->{$this->fkField})) {
                $errors[] = sprintf(
                    'La factura #%s ya pertenece a otro registro de %s.',
                    $invoice->invoice_number ?? $invoice->id,
                    $this->fkLabel,
                );
            }
        }

        $missingIds = array_diff($invoiceIds, $foundIds);
        foreach ($missingIds as $missingId) {
            $errors[] = sprintf('La factura con ID %d no fue encontrada.', $missingId);
        }

        return $errors;
    }

    /**
     * Add invoices to a record.
     *
     * @param object $record Parent record entity.
     * @param array $invoiceIds Invoice IDs to add.
     * @return array List of error strings (empty = success).
     */
    public function addInvoices(object $record, array $invoiceIds): array
    {
        $errors = $this->validateGrouping($invoiceIds);
        if (!empty($errors)) {
            return $errors;
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        foreach ($invoiceIds as $invoiceId) {
            $invoicesTable->updateAll(
                [$this->fkField => $record->id],
                ['id' => $invoiceId],
            );
        }

        $this->calculateAndSaveTotal($record);

        return [];
    }

    /**
     * Remove an invoice from a record.
     *
     * @param object $record Parent record entity.
     * @param int $invoiceId Invoice ID to remove.
     * @return bool
     */
    public function removeInvoice(object $record, int $invoiceId): bool
    {
        if (!$record->isAgrupacion()) {
            return false;
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoicesTable->updateAll(
            [$this->fkField => null],
            ['id' => $invoiceId, $this->fkField => $record->id],
        );

        $this->calculateAndSaveTotal($record);

        return true;
    }

    /**
     * Recalculate and save total_amount on the parent record.
     *
     * @param object $record Parent record entity.
     * @return void
     */
    public function calculateAndSaveTotal(object $record): void
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $result = $invoicesTable->find()
            ->where([$this->fkField => $record->id])
            ->select(['total' => $invoicesTable->find()->func()->sum('amount')])
            ->first();

        $table = TableRegistry::getTableLocator()->get($this->recordTableName);
        $record->total_amount = (float)($result->total ?? 0);
        $table->save($record);
    }

    /**
     * Default lookback window when no date_from filter is provided.
     * Cap aplicado para evitar cargar todo el histórico al alimentar el
     * <select multiple> de facturas disponibles.
     */
    private const DEFAULT_LOOKBACK_DAYS = 90;

    /**
     * Get available invoices for grouping.
     *
     * @param array $filters Optional filters (date_from, date_to, operation_center_id, provider_id).
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function getAvailableInvoices(array $filters = []): SelectQuery
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $query = $invoicesTable->find()
            ->contain(['Providers', 'OperationCenters'])
            ->where([
                'Invoices.document_type' => $this->documentType,
                'Invoices.pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
                "Invoices.{$this->fkField} IS" => null,
            ])
            ->orderBy(['Invoices.issue_date' => 'ASC']);

        if (!empty($filters['date_from'])) {
            $query->where(['Invoices.issue_date >=' => $filters['date_from']]);
        } else {
            // Sin date_from explícito, limitar al horizonte por defecto.
            $defaultFrom = date('Y-m-d', strtotime('-' . self::DEFAULT_LOOKBACK_DAYS . ' days'));
            $query->where(['Invoices.issue_date >=' => $defaultFrom]);
        }
        if (!empty($filters['date_to'])) {
            $query->where(['Invoices.issue_date <=' => $filters['date_to']]);
        }
        if (!empty($filters['operation_center_id'])) {
            $query->where(['Invoices.operation_center_id' => $filters['operation_center_id']]);
        }
        if (!empty($filters['provider_id'])) {
            $query->where(['Invoices.provider_id' => $filters['provider_id']]);
        }

        return $query;
    }

    /**
     * Record per-invoice history after a bulk pipeline status update.
     *
     * @param int $recordId Parent record ID.
     * @param array $invoicesBefore Array of entities with id and pipeline_status before update.
     * @param string $newPipelineStatus The new pipeline status.
     * @param int $userId User performing the change.
     * @return void
     */
    public function recordBulkHistory(
        int $recordId,
        array $invoicesBefore,
        string $newPipelineStatus,
        int $userId,
    ): void {
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

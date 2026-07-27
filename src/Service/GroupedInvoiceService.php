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
     * @var list<string>
     */
    private readonly array $documentTypes;

    /**
     * @var list<string>
     */
    private readonly array $linkableStatuses;

    /**
     * @param array|string $documentType Invoice document_type value(s) to filter by.
     * @param string $fkField FK column name on the invoices table.
     * @param string $recordTableName CakePHP table name for the parent record.
     * @param string $fkLabel Human-readable label for the FK (error messages).
     * @param \App\Service\Interface\HistoryServiceInterface $historyService History service.
     * @param array|string $linkableStatus Invoice pipeline_status(es) required to be linkable.
     */
    public function __construct(
        string|array $documentType,
        private readonly string $fkField,
        private readonly string $recordTableName,
        private readonly string $fkLabel,
        private readonly HistoryServiceInterface $historyService,
        string|array $linkableStatus = InvoiceConstants::STATUS_CONTABILIDAD,
    ) {
        $this->documentTypes = array_values((array)$documentType);
        $this->linkableStatuses = array_values((array)$linkableStatus);
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

            if (!in_array($invoice->document_type, $this->documentTypes, true)) {
                $errors[] = sprintf(
                    'La factura #%s no es un tipo vinculable a %s (%s).',
                    $invoice->invoice_number ?? $invoice->id,
                    $this->fkLabel,
                    implode(' o ', $this->documentTypes),
                );
            }
            if (!empty($invoice->advance_id)) {
                $errors[] = sprintf(
                    'La factura #%s ya está vinculada a un anticipo.',
                    $invoice->invoice_number ?? $invoice->id,
                );
            }
            if (!in_array($invoice->pipeline_status, $this->linkableStatuses, true)) {
                $labels = array_map(
                    static fn(string $s): string => InvoiceConstants::STATUS_LABELS[$s] ?? $s,
                    $this->linkableStatuses,
                );
                $errors[] = sprintf(
                    'La factura #%s no está en un estado vinculable (%s).',
                    $invoice->invoice_number ?? $invoice->id,
                    implode(' o ', $labels),
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
        // Deduplicar: el compare-and-set compara filas afectadas contra count($invoiceIds);
        // sin esto, ids repetidos (POST malformado) darían un falso "no disponible".
        $invoiceIds = array_values(array_unique(array_map('intval', $invoiceIds)));

        $errors = $this->validateGrouping($invoiceIds);
        if (!empty($errors)) {
            return $errors;
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        // Compare-and-set atómico: solo vincula filas que SIGUEN libres de ambos FKs.
        // Garantía de exclusividad D1 bajo concurrencia — NO borrar la cláusula
        // `advance_id IS null` creyéndola redundante con validateGrouping: ese check es
        // read-then-write; esta es la escritura condicional que cierra la carrera.
        $affected = $invoicesTable->updateAll(
            [$this->fkField => $record->id],
            [
                'id IN' => $invoiceIds,
                $this->fkField . ' IS' => null,
                'advance_id IS' => null,
            ],
        );
        if ($affected !== count($invoiceIds)) {
            return ['Una o más facturas ya no están disponibles para vincular. Refresque e intente de nuevo.'];
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
            ->contain(['Providers', 'OperationCenters', 'Employees'])
            ->where([
                'Invoices.document_type IN' => $this->documentTypes,
                'Invoices.pipeline_status IN' => $this->linkableStatuses,
                "Invoices.{$this->fkField} IS" => null,
                'Invoices.advance_id IS' => null,
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

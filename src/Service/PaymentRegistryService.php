<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;

class PaymentRegistryService
{
    /**
     * Mapa de keys de tipo a labels visibles en el registro de pagos.
     */
    public const TYPE_LABELS = [
        'refund'        => 'Reintegro',
        'advance'       => 'Anticipo',
        'debit_note'    => 'Nota Débito',
        'receipt'       => 'Recibo',
        'credit_card'   => 'Tarjeta de Crédito',
        'reintegro_doc' => 'Reintegro (Doc)',
        'invoice'       => 'Factura',
        'liquidation'   => 'Liquidación',
    ];

    /**
     * Get one page of payments across modules, merged and sorted by `created` DESC.
     *
     * M3: en lugar de materializar TODAS las filas de ambas tablas y paginar en
     * memoria, cada sub-query trae a lo sumo `offset + limit` filas (LIMIT en SQL,
     * ya ordenadas DESC), se fusionan, se reordenan y se recorta la ventana de la
     * página. El total se calcula con COUNT(*) por tabla (ver count()), no contando
     * un array materializado. Memoria acotada a O(offset + limit), no O(N).
     *
     * @param array $filters Optional filters (type, authorized, banking_entity_id, date_from, date_to).
     * @param int $offset Filas a saltar (0-based).
     * @param int $limit Tamaño de página.
     * @return array
     */
    public function getPage(array $filters, int $offset, int $limit): array
    {
        $max = $offset + $limit;

        $results = array_merge(
            $this->_queryInvoicePayments($filters, $max),
            $this->_queryLiquidationDocPayments($filters, $max),
        );

        // Sort by created DESC
        usort($results, fn($a, $b) => strtotime($b['created']) - strtotime($a['created']));

        return array_slice($results, $offset, $limit);
    }

    /**
     * Total de pagos que coinciden con los filtros, vía COUNT(*) por tabla.
     *
     * @param array $filters Mismos filtros que getPage().
     * @return int
     */
    public function count(array $filters = []): int
    {
        return $this->_countInvoicePayments($filters)
            + $this->_countLiquidationDocPayments($filters);
    }

    /**
     * Resolves the type key for an invoice payment based on its document_type and is_refund flag.
     */
    private function _resolveInvoicePaymentType(?string $documentType, bool $isRefund): string
    {
        return match (true) {
            $isRefund                                                => 'refund',
            $documentType === InvoiceConstants::DOCTYPE_ANTICIPO     => 'advance',
            $documentType === InvoiceConstants::DOCTYPE_NOTA_DEBITO  => 'debit_note',
            $documentType === InvoiceConstants::DOCTYPE_RECIBO       => 'receipt',
            $documentType === InvoiceConstants::DOCTYPE_TARJETA_CREDITO => 'credit_card',
            $documentType === InvoiceConstants::DOCTYPE_REINTEGRO    => 'reintegro_doc',
            default                                                  => 'invoice',
        };
    }

    /**
     * Query invoice payments.
     *
     * @param array $filters Filters to apply.
     * @param int|null $max Límite de filas a traer (SQL LIMIT); null = sin límite.
     * @return array
     */
    private function _queryInvoicePayments(array $filters, ?int $max = null): array
    {
        $requestedType = $filters['type'] ?? null;
        $invoiceTypeKeys = ['refund', 'advance', 'debit_note', 'receipt', 'credit_card', 'reintegro_doc', 'invoice'];
        if (!empty($requestedType) && !in_array($requestedType, $invoiceTypeKeys, true)) {
            return [];
        }

        $table = TableRegistry::getTableLocator()->get('InvoicePayments');
        $query = $table->find()
            ->contain(['Invoices', 'BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers', 'PaymentSchedulings', 'PettyCashRecords'])
            ->orderBy(['InvoicePayments.created' => 'DESC']);

        $this->_applyCommonFilters($query, $filters, 'InvoicePayments');
        $this->_applyInvoiceTypeFilter($query, $requestedType);

        if ($max !== null) {
            $query->limit($max);
        }

        return array_map(function ($p) {
            $documentType = $p->invoice->document_type ?? null;
            $isRefund = (bool)$p->is_refund;
            $typeKey = $this->_resolveInvoicePaymentType($documentType, $isRefund);

            return [
                'type' => $typeKey,
                'type_label' => self::TYPE_LABELS[$typeKey] ?? 'Factura',
                'reference' => $p->invoice->invoice_number ?? "FAC-{$p->invoice_id}",
                'banking_entity' => $p->banking_entity->name ?? '—',
                'amount' => (float)$p->amount,
                'payment_date' => $p->payment_date?->format('Y-m-d'),
                'authorized' => (bool)$p->authorized,
                'authorized_by' => $p->authorized_by_user->full_name
                    ?? $p->authorized_by_user->username ?? null,
                'authorized_date' => $p->authorized_date?->format('Y-m-d'),
                'created_by' => $p->created_by_user->full_name
                    ?? $p->created_by_user->username ?? '—',
                'source_type' => match (true) {
                    !empty($p->payment_scheduling_id) => 'scheduling',
                    !empty($p->petty_cash_record_id) => 'petty_cash',
                    default => 'individual',
                },
                'source_label' => match (true) {
                    !empty($p->payment_scheduling_id) => 'Programación ' . ($p->payment_scheduling->code ?? '#' . $p->payment_scheduling_id),
                    !empty($p->petty_cash_record_id) => 'Caja Menor ' . ($p->petty_cash_record->code ?? '#' . $p->petty_cash_record_id),
                    default => 'Individual',
                },
                'source_url' => match (true) {
                    !empty($p->payment_scheduling_id) => ['controller' => 'PaymentSchedulings', 'action' => 'view', $p->payment_scheduling_id],
                    !empty($p->petty_cash_record_id) => ['controller' => 'PettyCashRecords', 'action' => 'view', $p->petty_cash_record_id],
                    default => null,
                },
                'created' => $p->created?->format('Y-m-d H:i:s') ?? '',
            ];
        }, $query->all()->toArray());
    }

    /**
     * Apply a type filter on invoice payments based on document_type / is_refund.
     */
    private function _applyInvoiceTypeFilter(SelectQuery $query, ?string $type): void
    {
        if (empty($type)) {
            return;
        }

        switch ($type) {
            case 'refund':
                $query->where(['InvoicePayments.is_refund' => true]);
                break;
            case 'advance':
                $query->where([
                    'InvoicePayments.is_refund' => false,
                    'Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
                ]);
                break;
            case 'debit_note':
                $query->where([
                    'InvoicePayments.is_refund' => false,
                    'Invoices.document_type' => InvoiceConstants::DOCTYPE_NOTA_DEBITO,
                ]);
                break;
            case 'receipt':
                $query->where([
                    'InvoicePayments.is_refund' => false,
                    'Invoices.document_type' => InvoiceConstants::DOCTYPE_RECIBO,
                ]);
                break;
            case 'credit_card':
                $query->where([
                    'InvoicePayments.is_refund' => false,
                    'Invoices.document_type' => InvoiceConstants::DOCTYPE_TARJETA_CREDITO,
                ]);
                break;
            case 'reintegro_doc':
                $query->where([
                    'InvoicePayments.is_refund' => false,
                    'Invoices.document_type' => InvoiceConstants::DOCTYPE_REINTEGRO,
                ]);
                break;
            case 'invoice':
                $query->where([
                    'InvoicePayments.is_refund' => false,
                    'Invoices.document_type IN' => [
                        InvoiceConstants::DOCTYPE_FACTURA,
                        InvoiceConstants::DOCTYPE_CAJA_MENOR,
                    ],
                ]);
                break;
        }
    }

    /**
     * Query liquidation document payments.
     *
     * @param array $filters Filters to apply.
     * @param int|null $max Límite de filas a traer (SQL LIMIT); null = sin límite.
     * @return array
     */
    private function _queryLiquidationDocPayments(array $filters, ?int $max = null): array
    {
        if (!empty($filters['type']) && $filters['type'] !== 'liquidation') {
            return [];
        }

        $table = TableRegistry::getTableLocator()->get('LiquidationDocPayments');
        $query = $table->find()
            ->contain(['NoveltyLiquidationDocs', 'BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers'])
            ->orderBy(['LiquidationDocPayments.created' => 'DESC']);

        $this->_applyCommonFilters($query, $filters, 'LiquidationDocPayments');

        if ($max !== null) {
            $query->limit($max);
        }

        return array_map(fn($p) => [
            'type' => 'liquidation',
            'type_label' => self::TYPE_LABELS['liquidation'],
            'reference' => $p->novelty_liquidation_doc->liquidation_number
                ?? "LIQ-{$p->liquidation_doc_id}",
            'banking_entity' => $p->banking_entity->name ?? '—',
            'amount' => (float)$p->amount,
            'payment_date' => $p->payment_date?->format('Y-m-d'),
            'authorized' => (bool)$p->authorized,
            'authorized_by' => $p->authorized_by_user->full_name
                ?? $p->authorized_by_user->username ?? null,
            'authorized_date' => $p->authorized_date?->format('Y-m-d'),
            'created_by' => $p->created_by_user->full_name
                ?? $p->created_by_user->username ?? '—',
            'source_type' => 'legacy',
            'source_label' => null,
            'source_url' => null,
            'created' => $p->created?->format('Y-m-d H:i:s') ?? '',
        ], $query->all()->toArray());
    }

    /**
     * COUNT(*) de pagos de facturas que coinciden con los filtros (M3).
     *
     * @param array $filters Filtros a aplicar.
     * @return int
     */
    private function _countInvoicePayments(array $filters): int
    {
        $requestedType = $filters['type'] ?? null;
        $invoiceTypeKeys = ['refund', 'advance', 'debit_note', 'receipt', 'credit_card', 'reintegro_doc', 'invoice'];
        if (!empty($requestedType) && !in_array($requestedType, $invoiceTypeKeys, true)) {
            return 0;
        }

        $table = TableRegistry::getTableLocator()->get('InvoicePayments');
        // contain('Invoices') agrega el JOIN belongsTo (1:1) para que el filtro por
        // document_type resuelva; count() ignora el select de las asociaciones.
        $query = $table->find()->contain(['Invoices']);
        $this->_applyCommonFilters($query, $filters, 'InvoicePayments');
        $this->_applyInvoiceTypeFilter($query, $requestedType);

        return $query->count();
    }

    /**
     * COUNT(*) de pagos de documentos de liquidación que coinciden con los filtros (M3).
     *
     * @param array $filters Filtros a aplicar.
     * @return int
     */
    private function _countLiquidationDocPayments(array $filters): int
    {
        if (!empty($filters['type']) && $filters['type'] !== 'liquidation') {
            return 0;
        }

        $table = TableRegistry::getTableLocator()->get('LiquidationDocPayments');
        $query = $table->find();
        $this->_applyCommonFilters($query, $filters, 'LiquidationDocPayments');

        return $query->count();
    }

    /**
     * Apply common filters to a payment query.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query to filter.
     * @param array $filters Filter values.
     * @param string $alias Table alias for column prefixing.
     * @return void
     */
    private function _applyCommonFilters(SelectQuery $query, array $filters, string $alias): void
    {
        if (isset($filters['authorized']) && $filters['authorized'] !== '' && $filters['authorized'] !== null) {
            $query->where(["$alias.authorized" => $filters['authorized'] === 'yes']);
        }
        if (!empty($filters['banking_entity_id'])) {
            $query->where(["$alias.banking_entity_id" => $filters['banking_entity_id']]);
        }
        if (!empty($filters['date_from'])) {
            $query->where(["$alias.payment_date >=" => $filters['date_from']]);
        }
        if (!empty($filters['date_to'])) {
            $query->where(["$alias.payment_date <=" => $filters['date_to']]);
        }
    }
}

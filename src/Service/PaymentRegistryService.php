<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;

class PaymentRegistryService
{
    /**
     * Get all payments across modules, merged and sorted.
     *
     * @param array $filters Optional filters (type, authorized, banking_entity_id, date_from, date_to).
     * @return array
     */
    public function getAll(array $filters = []): array
    {
        $results = [];

        $results = array_merge($results, $this->_queryInvoicePayments($filters));
        $results = array_merge($results, $this->_queryLiquidationDocPayments($filters));
        $results = array_merge($results, $this->_queryPettyCashPayments($filters));
        $results = array_merge($results, $this->_queryLegalizationPayments($filters));

        // Sort by created DESC
        usort($results, fn($a, $b) => strtotime($b['created']) - strtotime($a['created']));

        return $results;
    }

    /**
     * Query invoice payments.
     *
     * @param array $filters Filters to apply.
     * @return array
     */
    private function _queryInvoicePayments(array $filters): array
    {
        if (!empty($filters['type']) && $filters['type'] !== 'invoice') {
            return [];
        }

        $table = TableRegistry::getTableLocator()->get('InvoicePayments');
        $query = $table->find()
            ->contain(['Invoices', 'BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers', 'PaymentSchedulings', 'PettyCashRecords', 'LegalizationRecords'])
            ->order(['InvoicePayments.created' => 'DESC']);

        $this->_applyCommonFilters($query, $filters, 'InvoicePayments');

        return array_map(fn($p) => [
            'type' => 'invoice',
            'type_label' => 'Factura',
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
                !empty($p->legalization_record_id) => 'legalization',
                default => 'individual',
            },
            'source_label' => match (true) {
                !empty($p->payment_scheduling_id) => 'Programación ' . ($p->payment_scheduling->code ?? '#' . $p->payment_scheduling_id),
                !empty($p->petty_cash_record_id) => 'Caja Menor ' . ($p->petty_cash_record->code ?? '#' . $p->petty_cash_record_id),
                !empty($p->legalization_record_id) => 'Legalización ' . ($p->legalization_record->code ?? '#' . $p->legalization_record_id),
                default => 'Individual',
            },
            'source_url' => match (true) {
                !empty($p->payment_scheduling_id) => ['controller' => 'PaymentSchedulings', 'action' => 'view', $p->payment_scheduling_id],
                !empty($p->petty_cash_record_id) => ['controller' => 'PettyCashRecords', 'action' => 'view', $p->petty_cash_record_id],
                !empty($p->legalization_record_id) => ['controller' => 'LegalizationRecords', 'action' => 'view', $p->legalization_record_id],
                default => null,
            },
            'created' => $p->created?->format('Y-m-d H:i:s') ?? '',
        ], $query->all()->toArray());
    }

    /**
     * Query liquidation document payments.
     *
     * @param array $filters Filters to apply.
     * @return array
     */
    private function _queryLiquidationDocPayments(array $filters): array
    {
        if (!empty($filters['type']) && $filters['type'] !== 'liquidation') {
            return [];
        }

        $table = TableRegistry::getTableLocator()->get('LiquidationDocPayments');
        $query = $table->find()
            ->contain(['NoveltyLiquidationDocs', 'BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers'])
            ->order(['LiquidationDocPayments.created' => 'DESC']);

        $this->_applyCommonFilters($query, $filters, 'LiquidationDocPayments');

        return array_map(fn($p) => [
            'type' => 'liquidation',
            'type_label' => 'Liquidación',
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
     * Query petty cash payments.
     *
     * @param array $filters Filters to apply.
     * @return array
     */
    private function _queryPettyCashPayments(array $filters): array
    {
        if (!empty($filters['type']) && $filters['type'] !== 'petty_cash') {
            return [];
        }

        $table = TableRegistry::getTableLocator()->get('PettyCashPayments');
        $query = $table->find()
            ->contain(['PettyCashRecords', 'BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers'])
            ->order(['PettyCashPayments.created' => 'DESC']);

        $this->_applyCommonFilters($query, $filters, 'PettyCashPayments');

        $invoicePaymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $childRecordIds = $invoicePaymentsTable->find()
            ->select(['petty_cash_record_id'])
            ->where(['petty_cash_record_id IS NOT' => null])
            ->distinct(['petty_cash_record_id'])
            ->all()
            ->extract('petty_cash_record_id')
            ->toArray();

        if (!empty($childRecordIds)) {
            $query->where(['PettyCashPayments.petty_cash_record_id NOT IN' => $childRecordIds]);
        }

        return array_map(fn($p) => [
            'type' => 'petty_cash',
            'type_label' => 'Caja Menor',
            'reference' => $p->petty_cash_record->code ?? "CM-{$p->petty_cash_record_id}",
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
     * Query legalization payments.
     *
     * @param array $filters Filters to apply.
     * @return array
     */
    private function _queryLegalizationPayments(array $filters): array
    {
        if (!empty($filters['type']) && $filters['type'] !== 'legalization') {
            return [];
        }

        $table = TableRegistry::getTableLocator()->get('LegalizationPayments');
        $query = $table->find()
            ->contain(['LegalizationRecords', 'BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers'])
            ->order(['LegalizationPayments.created' => 'DESC']);

        $this->_applyCommonFilters($query, $filters, 'LegalizationPayments');

        $invoicePaymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $childRecordIds = $invoicePaymentsTable->find()
            ->select(['legalization_record_id'])
            ->where(['legalization_record_id IS NOT' => null])
            ->distinct(['legalization_record_id'])
            ->all()
            ->extract('legalization_record_id')
            ->toArray();

        if (!empty($childRecordIds)) {
            $query->where(['LegalizationPayments.legalization_record_id NOT IN' => $childRecordIds]);
        }

        return array_map(fn($p) => [
            'type' => 'legalization',
            'type_label' => 'Legalización',
            'reference' => $p->legalization_record->code ?? "LEG-{$p->legalization_record_id}",
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

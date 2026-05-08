<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Filter\BaseFilterService;
use Cake\ORM\Query\SelectQuery;

class InvoiceFilterService extends BaseFilterService
{
    private const SEARCH_FIELDS = [
        'Invoices.invoice_number',
        'Invoices.purchase_order',
        'Invoices.detail',
        'Providers.name',
    ];

    /**
     * Apply search and filter parameters to an invoices query.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Base query (already contains associations).
     * @param array<string,mixed> $params Query-string parameters.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function apply(SelectQuery $query, array $params): SelectQuery
    {
        $this->applySearch($query, $params['search'] ?? null, self::SEARCH_FIELDS);
        $this->applyExact($query, 'Invoices.provider_id', $params['provider_id'] ?? null);
        $this->applyExact($query, 'Invoices.operation_center_id', $params['operation_center_id'] ?? null);
        $this->applyExact($query, 'Invoices.expense_type_id', $params['expense_type_id'] ?? null);
        $this->applyExact($query, 'Invoices.pipeline_status', $params['pipeline_status'] ?? null);
        $this->applyDateRange($query, 'Invoices.issue_date', $params['date_from'] ?? null, $params['date_to'] ?? null);

        return $query;
    }
}

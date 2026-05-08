<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\EmployeeStatusConstants;
use App\Service\Filter\BaseFilterService;
use Cake\ORM\Query\SelectQuery;

class EmployeeFilterService extends BaseFilterService
{
    private const SEARCH_FIELDS = [
        'Employees.first_name',
        'Employees.last_name1',
        'Employees.last_name2',
        'Employees.document_number',
        'Employees.email',
    ];

    /**
     * Apply search and filter parameters to an employees query.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Base query (already contains associations).
     * @param array<string,mixed> $params Query-string parameters.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function apply(SelectQuery $query, array $params): SelectQuery
    {
        $this->applySearch($query, $params['search'] ?? null, self::SEARCH_FIELDS);
        $this->applyExact($query, 'Employees.position_id', $params['position_id'] ?? null);
        $this->applyExact($query, 'Employees.operation_center_id', $params['operation_center_id'] ?? null);
        $this->applyEmployeeStatus($query, $params['status'] ?? null);

        return $query;
    }

    /**
     * Aplica filtro de status con default 'activo' (CR-007).
     *
     * - Sin parametro o vacio  -> filtra por 'activo'
     * - 'all'                  -> sin filtro (bypass explicito)
     * - cualquier otro         -> filtra literal (ej: 'retirado')
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query a modificar.
     * @param mixed $status Valor de status del query string.
     */
    private function applyEmployeeStatus(SelectQuery $query, mixed $status): void
    {
        if ($status === 'all') {
            return;
        }

        $effective = is_string($status) && $status !== ''
            ? $status
            : EmployeeStatusConstants::ACTIVO;

        $query->where(['Employees.status' => $effective]);
    }
}

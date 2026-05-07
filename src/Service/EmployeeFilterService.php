<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\EmployeeStatusConstants;
use Cake\ORM\Query\SelectQuery;

class EmployeeFilterService
{
    /**
     * Apply search and filter parameters to an employees query.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Base query (already contains associations).
     * @param array<string,mixed> $params Query-string parameters.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function apply(SelectQuery $query, array $params): SelectQuery
    {
        $this->applySearch($query, $params['search'] ?? null);
        $this->applyExact($query, 'Employees.position_id', $params['position_id'] ?? null);
        $this->applyExact($query, 'Employees.operation_center_id', $params['operation_center_id'] ?? null);
        $this->applyEmployeeStatus($query, $params['status'] ?? null);

        return $query;
    }

    private function applySearch(SelectQuery $query, mixed $search): void
    {
        if ($search === null || $search === '') {
            return;
        }

        $like = '%' . $search . '%';
        $query->where([
            'OR' => [
                'Employees.first_name LIKE' => $like,
                'Employees.last_name1 LIKE' => $like,
                'Employees.last_name2 LIKE' => $like,
                'Employees.document_number LIKE' => $like,
                'Employees.email LIKE' => $like,
            ],
        ]);
    }

    private function applyExact(SelectQuery $query, string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $query->where([$field => $value]);
    }

    /**
     * Aplica filtro de status con default 'activo' (CR-007).
     *
     * - Sin parametro o vacio  -> filtra por 'activo'
     * - 'all'                  -> sin filtro (bypass explicito)
     * - cualquier otro         -> filtra literal (ej: 'retirado')
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

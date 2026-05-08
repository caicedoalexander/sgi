<?php
declare(strict_types=1);

namespace App\Service\Filter;

use Cake\ORM\Query\SelectQuery;

/**
 * Helpers compartidos para filter services. Extrae la duplicación entre
 * EmployeeFilterService e InvoiceFilterService (CR-026).
 */
abstract class BaseFilterService
{
    /**
     * Aplica búsqueda LIKE %term% sobre múltiples campos en OR.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query a modificar.
     * @param mixed $term Término de búsqueda.
     * @param array<int,string> $fields Lista de campos calificados (ej: ['Employees.first_name', ...]).
     */
    protected function applySearch(SelectQuery $query, mixed $term, array $fields): void
    {
        if ($term === null || $term === '' || $fields === []) {
            return;
        }

        $like = '%' . $term . '%';
        $or = [];
        foreach ($fields as $field) {
            $or[$field . ' LIKE'] = $like;
        }
        $query->where(['OR' => $or]);
    }

    /**
     * Aplica filtro de igualdad exacta si el valor no es null/vacío.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query a modificar.
     * @param string $field Campo calificado.
     * @param mixed $value Valor de filtro.
     */
    protected function applyExact(SelectQuery $query, string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $query->where([$field => $value]);
    }

    /**
     * Aplica filtro de rango de fechas inclusivo.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query a modificar.
     * @param string $field Campo calificado.
     * @param mixed $from Fecha inicial.
     * @param mixed $to Fecha final.
     */
    protected function applyDateRange(SelectQuery $query, string $field, mixed $from, mixed $to): void
    {
        if ($from !== null && $from !== '') {
            $query->where([$field . ' >=' => $from]);
        }
        if ($to !== null && $to !== '') {
            $query->where([$field . ' <=' => $to]);
        }
    }
}

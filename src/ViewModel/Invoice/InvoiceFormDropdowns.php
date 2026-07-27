<?php
declare(strict_types=1);

namespace App\ViewModel\Invoice;

/**
 * Bundle de listados/dropdowns que alimentan el formulario de edit. Cada
 * campo es `mixed` porque puede ser un ResultSet de Cake o un array plano
 * según el origen (find('list'), array_combine, etc.).
 */
final readonly class InvoiceFormDropdowns
{
    /**
     * @param mixed $providers Listado de proveedores.
     * @param mixed $operationCenters Listado de centros de operación.
     * @param mixed $expenseTypes Listado de tipos de gasto.
     * @param mixed $costCenters Listado de centros de costo.
     * @param mixed $approvers Listado de aprobadores.
     * @param mixed $employees Listado de empleados.
     * @param mixed $bankingEntities Listado de entidades bancarias.
     */
    public function __construct(
        public mixed $providers,
        public mixed $operationCenters,
        public mixed $expenseTypes,
        public mixed $costCenters,
        public mixed $approvers,
        public mixed $employees,
        public mixed $bankingEntities,
    ) {
    }
}

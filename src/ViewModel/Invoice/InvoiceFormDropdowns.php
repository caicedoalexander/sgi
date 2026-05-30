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

<?php
declare(strict_types=1);

namespace App\ViewModel\Invoice;

/**
 * Bundle de listados/dropdowns que alimentan el formulario de edit. Cada
 * campo es `mixed` porque puede ser un ResultSet de Cake o un array plano
 * según el origen (find('list'), array_combine, etc.).
 */
final class InvoiceFormDropdowns
{
    public function __construct(
        public readonly mixed $providers,
        public readonly mixed $operationCenters,
        public readonly mixed $expenseTypes,
        public readonly mixed $costCenters,
        public readonly mixed $approvers,
        public readonly mixed $employees,
        public readonly mixed $bankingEntities,
    ) {
    }
}

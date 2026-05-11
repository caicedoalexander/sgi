<?php
declare(strict_types=1);

namespace App\ViewModel\Invoice;

/**
 * Bundle de capacidades (rol × estado × entidad) que aplican al edit de una
 * factura. El VM las desempaca a propiedades planas para preservar la API
 * que consume el template.
 */
final class InvoiceEditPermissions
{
    public function __construct(
        public readonly bool $canAdvance,
        public readonly bool $canDeleteDocuments,
        public readonly bool $canRegress,
        public readonly bool $canConfirmPayment,
        public readonly bool $canRegisterPayment,
        public readonly bool $canAuthorizePayment,
        public readonly bool $isRejected,
        public readonly bool $isApproved,
    ) {
    }
}

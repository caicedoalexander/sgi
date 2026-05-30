<?php
declare(strict_types=1);

namespace App\ViewModel\Invoice;

/**
 * Bundle de capacidades (rol × estado × entidad) que aplican al edit de una
 * factura. El VM las desempaca a propiedades planas para preservar la API
 * que consume el template.
 */
final readonly class InvoiceEditPermissions
{
    public function __construct(
        public bool $canAdvance,
        public bool $canDeleteDocuments,
        public bool $canRegress,
        public bool $canConfirmPayment,
        public bool $canRegisterPayment,
        public bool $canAuthorizePayment,
        public bool $isRejected,
        public bool $isApproved,
    ) {
    }
}

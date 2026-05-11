<?php
declare(strict_types=1);

namespace App\View\Presentation;

/**
 * DTO inmutable con los datos de presentación de una fila del listado de
 * facturas (Invoices/index). Producido por InvoicePresentation::forRow().
 */
final readonly class InvoiceRowView
{
    public function __construct(
        public string $statusLabel,
        public string $statusBadgeClass,
        public bool   $isRejected,
        public bool   $isApproved,
        public bool   $isPartialPay,
        public bool   $isPaid,
        public bool   $isReadyForPay,
        public bool   $isOverdue,
    ) {
    }
}

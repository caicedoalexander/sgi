<?php
declare(strict_types=1);

namespace App\ViewModel\Invoice;

/**
 * Estado del flujo multi-aprobador para una factura en `aprobacion`.
 */
final class InvoiceApprovalState
{
    /**
     * @param array<int, mixed> $currentApprovals Lista de InvoiceApprovals activos.
     */
    public function __construct(
        public readonly array $currentApprovals,
        public readonly bool $hasPendingApprovals,
        public readonly bool $canSendLinks,
        public readonly bool $canModifyApprovers,
    ) {
    }
}

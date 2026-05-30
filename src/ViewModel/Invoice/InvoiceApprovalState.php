<?php
declare(strict_types=1);

namespace App\ViewModel\Invoice;

/**
 * Estado del flujo multi-aprobador para una factura en `aprobacion`.
 */
final readonly class InvoiceApprovalState
{
    /**
     * @param array<int, mixed> $currentApprovals Lista de InvoiceApprovals activos.
     */
    public function __construct(
        public array $currentApprovals,
        public bool $hasPendingApprovals,
        public bool $canSendLinks,
        public bool $canModifyApprovers,
    ) {
    }
}

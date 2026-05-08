<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\Policy;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Service\Pipeline\Invoice\DocumentTypePolicy;
use App\Service\Pipeline\Invoice\InvoicePipelineState;

/**
 * Policy por defecto para los doctypes sin comportamiento especial:
 * Factura, Nota Débito, Caja Menor, Tarjeta de Crédito, Reintegro, Recibo.
 */
final class StandardDocumentTypePolicy implements DocumentTypePolicy
{
    public function getDocumentType(): string
    {
        return '*';
    }

    public function blocksAdvance(InvoicePipelineState $state, object $invoice): ?string
    {
        return null;
    }

    public function getPipelineStatusesForView(): array
    {
        return InvoiceConstants::PIPELINE_STATUSES;
    }

    public function filterVisibleSections(array $sections): array
    {
        return $sections;
    }

    public function triggersAutoLegalization(PipelineStatus $newStatus): bool
    {
        return false;
    }

    public function getRegressionLockReason(object $invoice): ?string
    {
        return null;
    }

    public function allowsRefundPayments(): bool
    {
        return false;
    }
}

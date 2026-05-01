<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Policy;

use App\Constants\InvoiceConstants;
use App\Service\Pipeline\DocumentTypePolicy;
use App\Service\Pipeline\InvoicePipelineState;

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

    public function triggersAutoLegalization(string $newStatus): bool
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

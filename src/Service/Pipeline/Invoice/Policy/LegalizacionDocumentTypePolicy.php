<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\Policy;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Service\Pipeline\Invoice\DocumentTypePolicy;
use App\Service\Pipeline\Invoice\InvoicePipelineState;

/**
 * Reglas específicas de las Legalizaciones:
 *  - pipeline visual corto: aprobacion → contabilidad → legalizada,
 *  - en `contabilidad` no se avanza manualmente (lo dispara LinkedInvoiceLegalizer),
 *  - secciones de tesorería y autorización de pago no aplican.
 */
final class LegalizacionDocumentTypePolicy implements DocumentTypePolicy
{
    public function getDocumentType(): string
    {
        return InvoiceConstants::DOCTYPE_LEGALIZACION;
    }

    public function blocksAdvance(InvoicePipelineState $state, object $invoice): ?string
    {
        if ($state->getStatus() === PipelineStatus::CONTABILIDAD) {
            return 'La legalización avanzará automáticamente cuando el Anticipo padre se legalice.';
        }

        return null;
    }

    public function getPipelineStatusesForView(): array
    {
        return InvoiceConstants::PIPELINE_STATUSES_LEGALIZACION;
    }

    public function filterVisibleSections(array $sections): array
    {
        return array_values(array_filter(
            $sections,
            static fn(string $s): bool => !in_array($s, ['treasury', 'payment_authorization'], true),
        ));
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

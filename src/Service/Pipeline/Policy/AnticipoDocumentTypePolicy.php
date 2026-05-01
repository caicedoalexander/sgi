<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Policy;

use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationService;
use App\Service\Pipeline\DocumentTypePolicy;
use App\Service\Pipeline\InvoicePipelineState;

/**
 * Reglas específicas de los Anticipos:
 *  - al pagar (transition a `pagada`) se dispara auto-init de legalización,
 *  - una vez iniciada la legalización, la regresión queda bloqueada,
 *  - permite is_refund=true en sus pagos (devoluciones de Anticipo).
 */
final class AnticipoDocumentTypePolicy implements DocumentTypePolicy
{
    public function __construct(
        private readonly AdvanceLegalizationService $advanceLegalizationService,
    ) {
    }

    public function getDocumentType(): string
    {
        return InvoiceConstants::DOCTYPE_ANTICIPO;
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
        return array_values(array_filter(
            $sections,
            static fn(string $s): bool => $s !== 'revision',
        ));
    }

    public function triggersAutoLegalization(string $newStatus): bool
    {
        return $newStatus === InvoiceConstants::STATUS_PAGADA;
    }

    public function getRegressionLockReason(object $invoice): ?string
    {
        if (
            !empty($invoice->id)
            && $this->advanceLegalizationService->hasLegalization((int)$invoice->id)
        ) {
            return 'No se puede regresar: la legalización del anticipo ya fue iniciada.';
        }

        return null;
    }

    public function allowsRefundPayments(): bool
    {
        return true;
    }
}

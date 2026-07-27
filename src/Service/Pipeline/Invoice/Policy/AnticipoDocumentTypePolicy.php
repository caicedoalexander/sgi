<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\Policy;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationService;
use App\Service\Pipeline\Invoice\DocumentTypePolicy;
use App\Service\Pipeline\Invoice\InvoicePipelineState;

/**
 * Reglas específicas de los Anticipos:
 *  - al pagar (transition a `pagada`) se dispara auto-init de legalización,
 *  - una vez iniciada la legalización, la regresión queda bloqueada,
 *  - permite is_refund=true en sus pagos (devoluciones de Anticipo).
 */
final class AnticipoDocumentTypePolicy implements DocumentTypePolicy
{
    /**
     * @param \App\Service\AdvanceLegalizationService $advanceLegalizationService Servicio de legalización de anticipos.
     */
    public function __construct(
        private readonly AdvanceLegalizationService $advanceLegalizationService,
    ) {
    }

    /**
     * Doctype Anticipo.
     *
     * @return string
     */
    public function getDocumentType(): string
    {
        return InvoiceConstants::DOCTYPE_ANTICIPO;
    }

    /**
     * Anticipo no bloquea el avance por doctype.
     *
     * @param \App\Service\Pipeline\Invoice\InvoicePipelineState $state Estado actual.
     * @param object $invoice Factura.
     * @return string|null
     */
    public function blocksAdvance(InvoicePipelineState $state, object $invoice): ?string
    {
        return null;
    }

    /**
     * Pipeline visual estándar de 6 estados.
     *
     * @param object|null $invoice Factura (no usado por esta policy).
     * @return array<string>
     */
    public function getPipelineStatusesForView(?object $invoice = null): array
    {
        return InvoiceConstants::PIPELINE_STATUSES;
    }

    /**
     * Oculta la sección de revisión (no aplica a los Anticipos).
     *
     * @param array<string> $sections Secciones candidatas.
     * @param object|null $invoice Factura (no usado por esta policy).
     * @return array<string>
     */
    public function filterVisibleSections(array $sections, ?object $invoice = null): array
    {
        return array_values(array_filter(
            $sections,
            static fn(string $s): bool => $s !== 'revision',
        ));
    }

    /**
     * Dispara la auto-inicialización de la legalización al pasar a `pagada`.
     *
     * @param \App\Constants\Domain\Invoice\PipelineStatus $newStatus Estado destino.
     * @return bool
     */
    public function triggersAutoLegalization(PipelineStatus $newStatus): bool
    {
        return $newStatus === PipelineStatus::PAGADA;
    }

    /**
     * Bloquea la regresión si la legalización del anticipo ya fue iniciada.
     *
     * @param object $invoice Factura.
     * @return string|null
     */
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

    /**
     * Permite pagos con is_refund=true (devoluciones de anticipo).
     *
     * @return bool
     */
    public function allowsRefundPayments(): bool
    {
        return true;
    }

    /** ¿El avance aprobacion→contabilidad exige dian_validation='Aprobada'? Flag de clase (no depende de la instancia). */
    public static function requiresDianValidation(): bool
    {
        return true;
    }

    /** ¿El avance aprobacion→contabilidad exige ≥1 documento en invoice_documents? Flag de clase. */
    public static function requiresSupportDocument(): bool
    {
        return true;
    }
}

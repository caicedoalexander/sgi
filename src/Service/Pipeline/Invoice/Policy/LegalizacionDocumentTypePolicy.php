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
    /**
     * Doctype Legalización.
     *
     * @return string
     */
    public function getDocumentType(): string
    {
        return InvoiceConstants::DOCTYPE_LEGALIZACION;
    }

    /**
     * Bloquea el avance manual en `contabilidad`: la legalización avanza
     * automáticamente cuando el Anticipo padre se legaliza.
     *
     * @param \App\Service\Pipeline\Invoice\InvoicePipelineState $state Estado actual.
     * @param object $invoice Factura.
     * @return string|null
     */
    public function blocksAdvance(InvoicePipelineState $state, object $invoice): ?string
    {
        if ($state->getStatus() === PipelineStatus::CONTABILIDAD) {
            return 'La legalización avanzará automáticamente cuando el Anticipo padre se legalice.';
        }

        return null;
    }

    /**
     * Pipeline visual corto de legalización (aprobacion → contabilidad → legalizada).
     *
     * @param object|null $invoice Factura (no usado por esta policy).
     * @return array<string>
     */
    public function getPipelineStatusesForView(?object $invoice = null): array
    {
        return InvoiceConstants::PIPELINE_STATUSES_LEGALIZACION;
    }

    /**
     * Oculta las secciones de tesorería y autorización de pago (no aplican).
     *
     * @param array<string> $sections Secciones candidatas.
     * @param object|null $invoice Factura (no usado por esta policy).
     * @return array<string>
     */
    public function filterVisibleSections(array $sections, ?object $invoice = null): array
    {
        return array_values(array_filter(
            $sections,
            static fn(string $s): bool => !in_array($s, ['treasury', 'payment_authorization'], true),
        ));
    }

    /**
     * No dispara la auto-inicialización de la legalización.
     *
     * @param \App\Constants\Domain\Invoice\PipelineStatus $newStatus Estado destino.
     * @return bool
     */
    public function triggersAutoLegalization(PipelineStatus $newStatus): bool
    {
        return false;
    }

    /**
     * No bloquea la regresión por su propio estado.
     *
     * @param object $invoice Factura.
     * @return string|null
     */
    public function getRegressionLockReason(object $invoice): ?string
    {
        return null;
    }

    /**
     * No permite pagos con is_refund.
     *
     * @return bool
     */
    public function allowsRefundPayments(): bool
    {
        return false;
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

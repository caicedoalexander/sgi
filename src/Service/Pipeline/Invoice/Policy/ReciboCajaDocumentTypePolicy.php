<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\Policy;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Service\Pipeline\Invoice\DocumentTypePolicy;
use App\Service\Pipeline\Invoice\InvoicePipelineState;

/**
 * Reglas del Recibo de Caja:
 *  - cuando está vinculado a una legalización (advance_id != null) queda parqueado
 *    en `contabilidad` (no avanza manualmente): es justificación de un gasto ya
 *    cubierto por el anticipo, no se paga por su cuenta. Espejo del freeze de
 *    Legalización, pero condicionado a advance_id (un RC sin vincular usa el
 *    pipeline normal de 6 pasos).
 */
final class ReciboCajaDocumentTypePolicy implements DocumentTypePolicy
{
    private readonly LegalizacionDocumentTypePolicy $legalizacion;

    /**
     * @param \App\Service\Pipeline\Invoice\Policy\LegalizacionDocumentTypePolicy|null $legalizacion Policy de Legalización a la que delega cuando el RC está vinculado.
     */
    public function __construct(?LegalizacionDocumentTypePolicy $legalizacion = null)
    {
        $this->legalizacion = $legalizacion ?? new LegalizacionDocumentTypePolicy();
    }

    /**
     * Doctype Recibo de Caja.
     *
     * @return string
     */
    public function getDocumentType(): string
    {
        return InvoiceConstants::DOCTYPE_RECIBO_CAJA;
    }

    /**
     * Bloquea el avance manual en `contabilidad` cuando el Recibo de Caja está
     * vinculado a una legalización (advance_id != null); un RC sin vincular usa
     * el pipeline normal.
     *
     * @param \App\Service\Pipeline\Invoice\InvoicePipelineState $state Estado actual.
     * @param object $invoice Factura.
     * @return string|null
     */
    public function blocksAdvance(InvoicePipelineState $state, object $invoice): ?string
    {
        if (
            ($invoice->advance_id ?? null) !== null
            && $state->getStatus() === PipelineStatus::CONTABILIDAD
        ) {
            return 'Este Recibo de Caja está vinculado a una legalización; avanzará junto con ella.';
        }

        return null;
    }

    /**
     * Pipeline corto de legalización si está vinculado (advance_id != null); el
     * estándar de 6 estados en caso contrario.
     *
     * @param object|null $invoice Factura; su advance_id decide el pipeline.
     * @return array<string>
     */
    public function getPipelineStatusesForView(?object $invoice = null): array
    {
        if (($invoice->advance_id ?? null) !== null) {
            return $this->legalizacion->getPipelineStatusesForView($invoice);
        }

        return InvoiceConstants::PIPELINE_STATUSES;
    }

    /**
     * Cuando está vinculado, oculta las mismas secciones que Legalización; si no,
     * no oculta ninguna.
     *
     * @param array<string> $sections Secciones candidatas.
     * @param object|null $invoice Factura; su advance_id decide el filtrado.
     * @return array<string>
     */
    public function filterVisibleSections(array $sections, ?object $invoice = null): array
    {
        if (($invoice->advance_id ?? null) !== null) {
            return $this->legalizacion->filterVisibleSections($sections, $invoice);
        }

        return $sections;
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
        return false;
    }

    /** ¿El avance aprobacion→contabilidad exige ≥1 documento en invoice_documents? Flag de clase. */
    public static function requiresSupportDocument(): bool
    {
        return true;
    }
}

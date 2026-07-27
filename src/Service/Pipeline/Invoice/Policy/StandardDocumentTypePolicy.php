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
    /**
     * Sentinel '*': esta policy aplica a los doctypes sin comportamiento especial.
     *
     * @return string
     */
    public function getDocumentType(): string
    {
        return '*';
    }

    /**
     * Standard nunca bloquea el avance por doctype.
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
     * Standard no oculta ninguna sección.
     *
     * @param array<string> $sections Secciones candidatas.
     * @param object|null $invoice Factura (no usado por esta policy).
     * @return array<string>
     */
    public function filterVisibleSections(array $sections, ?object $invoice = null): array
    {
        return $sections;
    }

    /**
     * Standard nunca dispara la auto-inicialización de la legalización.
     *
     * @param \App\Constants\Domain\Invoice\PipelineStatus $newStatus Estado destino.
     * @return bool
     */
    public function triggersAutoLegalization(PipelineStatus $newStatus): bool
    {
        return false;
    }

    /**
     * Standard no bloquea la regresión por su propio estado.
     *
     * @param object $invoice Factura.
     * @return string|null
     */
    public function getRegressionLockReason(object $invoice): ?string
    {
        return null;
    }

    /**
     * Standard no permite pagos con is_refund.
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

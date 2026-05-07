<?php
declare(strict_types=1);

namespace App\Service\Pipeline;

use App\Constants\Domain\Invoice\PipelineStatus;

/**
 * Encapsula las reglas que diferencian a un document_type del flujo normal.
 *
 * Cualquier rama `if ($documentType === DOCTYPE_*)` que hoy vive en pipeline,
 * payment o field-access policy se sustituye por una llamada a la policy
 * correspondiente. La factory siempre devuelve una policy concreta — los
 * doctypes sin reglas especiales caen en StandardDocumentTypePolicy.
 */
interface DocumentTypePolicy
{
    /** Sentinel '*' para Standard; valor de InvoiceConstants::DOCTYPE_* para los demás. */
    public function getDocumentType(): string;

    /**
     * Mensaje si el doctype bloquea avance desde el estado actual; null = no bloquea.
     * Usado por Legalización en `contabilidad`.
     */
    public function blocksAdvance(InvoicePipelineState $state, object $invoice): ?string;

    /**
     * Estados visuales del pipeline (Standard/Anticipo: 5; Legalización: 3).
     *
     * @return array<string>
     */
    public function getPipelineStatusesForView(): array;

    /**
     * Filtra secciones visibles que no aplican a este doctype.
     *
     * @param array<string> $sections
     * @return array<string>
     */
    public function filterVisibleSections(array $sections): array;

    /** ¿Avanzar a $newStatus dispara auto-init de la legalización? Sólo Anticipo cuando newStatus = PAGADA. */
    public function triggersAutoLegalization(PipelineStatus $newStatus): bool;

    /** Mensaje si el doctype bloquea regresión por su propio estado; null = no. */
    public function getRegressionLockReason(object $invoice): ?string;

    /** ¿Permite is_refund=true en sus pagos? Sólo Anticipo. */
    public function allowsRefundPayments(): bool;
}

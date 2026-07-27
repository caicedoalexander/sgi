<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice;

use App\Constants\Domain\Invoice\PipelineStatus;

/**
 * Polymorphic representation of one pipeline state.
 *
 * Cada State conoce su transición natural, qué roles lo ven y qué requisitos
 * verifica para avanzar. NO conoce el doctype (eso es de DocumentTypePolicy)
 * ni los locks (eso es de InvoiceLockPolicy).
 */
interface InvoicePipelineState
{
    /** Estado canónico tipado. */
    public function getStatus(): PipelineStatus;

    /** Estado siguiente "natural"; null si terminal. La DocumentTypePolicy puede bloquearlo. */
    public function getNextStatus(): ?PipelineStatus;

    /** Estado anterior; null si es el primero. */
    public function getPreviousStatus(): ?PipelineStatus;

    /**
     * Errores de requirement de este estado para avanzar al siguiente,
     * KEYED por requisito (key de InvoiceTransitionValidator::REQUIREMENT_FIELDS).
     * No incluye rejection ni doctype block — el coordinador los compone.
     *
     * @return array<string, string>
     */
    public function validateAdvance(object $invoice): array;
}

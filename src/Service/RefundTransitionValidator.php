<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineStateRegistry;

/**
 * Valida los requisitos de avance del pipeline de Reintegros delegando al State
 * del estado actual.
 *
 * Espejo (reducido) de InvoiceTransitionValidator: Reintegros no tiene
 * document_type ni rechazo de área, así que la validación de avance se reduce a
 * los requisitos de campos que declara cada State::validateAdvance(). La regla de
 * "al menos una factura agrupada" es una invariante de agrupación y vive en el
 * coordinador (RefundPipelineService), no aquí.
 */
final class RefundTransitionValidator
{
    /**
     * @param \App\Service\Pipeline\Refund\RefundPipelineStateRegistry $states Pipeline state registry.
     */
    public function __construct(
        private readonly RefundPipelineStateRegistry $states,
    ) {
    }

    /**
     * Errores que impiden avanzar desde el estado actual del registro.
     *
     * @return array<string>
     */
    public function validateAdvance(Refund $record): array
    {
        $statusEnum = PipelineStatus::tryFrom((string)$record->status);
        if ($statusEnum === null) {
            return ["Estado inválido: {$record->status}"];
        }

        return $this->states->get($statusEnum)->validateAdvance($record);
    }
}

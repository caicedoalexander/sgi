<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash;

use App\Constants\Domain\PettyCash\PipelineStatus;
use App\Model\Entity\PettyCashRecord;

/**
 * Polymorphic representation of one Petty Cash pipeline state.
 *
 * Cada State conoce su transición natural (next/previous) y los requisitos
 * de campos específicos para avanzar al siguiente. Los chequeos cross-state
 * (autorización por rol, presencia de pago, facturas agrupadas) los compone
 * el coordinador (PettyCashPipelineService).
 */
interface PettyCashPipelineState
{
    /** Estado canónico tipado. */
    public function getStatus(): PipelineStatus;

    /** Estado siguiente "natural"; null si terminal. */
    public function getNextStatus(): ?PipelineStatus;

    /** Estado anterior; null si es el primero o si la regresión está bloqueada. */
    public function getPreviousStatus(): ?PipelineStatus;

    /**
     * Errores de requirement de este estado para avanzar al siguiente.
     * No incluye chequeos como "tener al menos una factura agrupada" que
     * son invariantes globales del coordinador.
     *
     * @return array<string>
     */
    public function validateAdvance(PettyCashRecord $record): array;
}

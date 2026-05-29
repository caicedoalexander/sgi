<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Model\Entity\Refund;

/**
 * Polymorphic representation of one Refund pipeline state.
 *
 * Each State knows its base transitions (next/previous) y un método:
 * - validateAdvance: errores que impiden avanzar al siguiente estado.
 *
 * Los States son puros: los bloqueos de regresión viven en RefundLockPolicy y los
 * cross-cutting checks (RBAC, transacciones, propagación a hijas, history) son
 * responsabilidad del coordinador (RefundPipelineService).
 */
interface RefundPipelineState
{
    /** Estado canónico tipado. */
    public function getStatus(): PipelineStatus;

    /** Estado siguiente; null si terminal. */
    public function getNextStatus(): ?PipelineStatus;

    /** Estado anterior; null si es el primero o regresión intrínsecamente bloqueada. */
    public function getPreviousStatus(): ?PipelineStatus;

    /**
     * Errores que impiden avanzar al siguiente estado.
     * Si el avance no aplica desde este estado (terminal, gestionado por otro flujo, etc.)
     * retornar un error explicativo (no array vacío).
     *
     * @param \App\Model\Entity\Refund $record Record.
     * @return array<string>
     */
    public function validateAdvance(Refund $record): array;
}

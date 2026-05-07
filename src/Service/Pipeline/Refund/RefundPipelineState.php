<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Model\Entity\Refund;

/**
 * Polymorphic representation of one Refund pipeline state.
 *
 * Each State knows its base transitions (next/previous) y dos métodos:
 * - validateAdvance: errores que impiden avanzar al siguiente estado.
 * - getRegressionLockMessage: mensaje de bloqueo si la regresión NO procede,
 *   null si la regresión está permitida desde este estado.
 *
 * Cross-cutting checks (RBAC, transacciones, propagación a hijas, history)
 * son responsabilidad del coordinador (RefundService).
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

    /**
     * Mensaje de bloqueo si la regresión NO procede; null si la regresión está
     * permitida. Solo `TesoreriaState` lo implementa con la regla del pago pendiente.
     *
     * @param \App\Model\Entity\Refund $record Record.
     */
    public function getRegressionLockMessage(Refund $record): ?string;
}

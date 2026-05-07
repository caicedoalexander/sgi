<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance;

use App\Constants\Domain\Advance\PipelineStatus;
use App\Model\Entity\AdvanceLegalization;

/**
 * Polymorphic representation of one Advance Legalization pipeline state.
 *
 * Cada State conoce su transición lineal (next/previous) y los requisitos
 * de campos para avanzar al siguiente. Las bifurcaciones (caso exacto/
 * faltante/sobrante en Contabilidad, ramas de Tesorería) NO se modelan
 * acá — viven como acciones discretas en el coordinador, gobernadas por
 * los predicates state-only de la entidad.
 *
 * Audit MA-010 / SU-001 — espejo del patrón ya implementado en Petty Cash.
 */
interface AdvanceLegalizationPipelineState
{
    /** Estado canónico tipado. */
    public function getStatus(): PipelineStatus;

    /** Estado siguiente "natural" en el avance lineal; null si terminal o bifurcante. */
    public function getNextStatus(): ?PipelineStatus;

    /** Estado anterior; null si es el primero o si la regresión está bloqueada. */
    public function getPreviousStatus(): ?PipelineStatus;

    /**
     * Errores de requirement de este estado para avanzar al siguiente.
     *
     * @return array<string>
     */
    public function validateAdvance(AdvanceLegalization $leg): array;
}

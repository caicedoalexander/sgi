<?php
declare(strict_types=1);

namespace App\ViewModel\Support;

/**
 * Pre-cálculo de visibilidad y permisos de edición por estado para los
 * formularios de edición con secciones agrupacion → contabilidad → tesoreria
 * (RefundEditViewModel, PettyCashEditViewModel). Centraliza la regla
 * "una sección es visible cuando ya pasamos por su estado".
 */
final readonly class PipelineEditFlags
{
    /**
     * @param int $statusIndex Posición del estado actual en el orden del pipeline.
     * @param bool $showAccounting Muestra la sección de contabilidad.
     * @param bool $showTreasury Muestra la sección de tesorería.
     * @param bool $canEditAccounting Permite editar la sección de contabilidad.
     * @param bool $canEditTreasury Permite editar la sección de tesorería.
     * @param bool $canSave Permite guardar el formulario en el estado actual.
     */
    public function __construct(
        public int $statusIndex,
        public bool $showAccounting,
        public bool $showTreasury,
        public bool $canEditAccounting,
        public bool $canEditTreasury,
        public bool $canSave,
    ) {
    }

    /**
     * @param array<int,string> $statuses Orden canónico del pipeline.
     */
    public static function fromRecord(
        string $currentStatus,
        array $statuses,
        bool $isAgrupacion,
        bool $isContabilidad,
        bool $isTesoreria,
    ): self {
        $idx = array_search($currentStatus, $statuses, true);
        $statusIndex = $idx === false ? 0 : (int)$idx;

        // Slugs canónicos del pipeline (español sin acentos), compartidos por todos los módulos.
        $contabilidadIdx = array_search('contabilidad', $statuses, true);
        $tesoreriaIdx = array_search('tesoreria', $statuses, true);

        return new self(
            statusIndex: $statusIndex,
            showAccounting: $contabilidadIdx !== false && $statusIndex >= $contabilidadIdx,
            showTreasury: $tesoreriaIdx !== false && $statusIndex >= $tesoreriaIdx,
            canEditAccounting: $isContabilidad,
            canEditTreasury: $isTesoreria,
            canSave: $isAgrupacion || $isContabilidad || $isTesoreria,
        );
    }
}

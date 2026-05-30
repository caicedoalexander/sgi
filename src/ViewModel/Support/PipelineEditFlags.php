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

        return new self(
            statusIndex: $statusIndex,
            showAccounting: $statusIndex >= 1,
            showTreasury: $statusIndex >= 2,
            canEditAccounting: $isContabilidad,
            canEditTreasury: $isTesoreria,
            canSave: $isAgrupacion || $isContabilidad || $isTesoreria,
        );
    }
}

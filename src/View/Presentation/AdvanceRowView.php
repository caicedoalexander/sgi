<?php
declare(strict_types=1);

namespace App\View\Presentation;

/**
 * DTO inmutable con los datos de presentación de una fila del listado de
 * anticipos (Advances/index). Producido por AdvancePresentation::forRow().
 *
 * Cada fila tiene DOS pipelines: el de pago (factura) y el de legalización
 * (opcional, sólo cuando ya hay legalización iniciada).
 */
final readonly class AdvanceRowView
{
    /**
     * @param string $idLabel Etiqueta identificadora de la fila.
     * @param string|null $beneficiaryName Nombre del beneficiario.
     * @param string|null $operationCenterName Nombre del centro de operación.
     * @param float $amount Monto del anticipo.
     * @param bool $isPaid El anticipo está pagado.
     * @param int $pipelineIdx Índice del paso actual en el pipeline de pago.
     * @param int $pipelineLength Cantidad de pasos del pipeline de pago.
     * @param string $statusLabel Etiqueta ES del estado de pago.
     * @param string $statusBadgeClass Clase de badge del estado de pago.
     * @param string $pipelineVariant Variante visual del pipeline de pago.
     * @param bool $hasLegalization Existe una legalización iniciada.
     * @param int $legalizationIdx Índice del paso actual en el pipeline de legalización.
     * @param int $legalizationLength Cantidad de pasos del pipeline de legalización.
     * @param string $legalizationLabel Etiqueta ES del estado de legalización.
     * @param string $legalizationBadgeClass Clase de badge del estado de legalización.
     * @param string $legalizationVariant Variante visual del pipeline de legalización.
     */
    public function __construct(
        // Pipeline de pago (factura).
        public string $idLabel,
        public ?string $beneficiaryName,
        public ?string $operationCenterName,
        public float $amount,
        public bool $isPaid,
        public int $pipelineIdx,
        public int $pipelineLength,
        public string $statusLabel,
        public string $statusBadgeClass,
        public string $pipelineVariant,
        // Pipeline de legalización (null si no hay legalización iniciada).
        public bool $hasLegalization,
        public int $legalizationIdx,
        public int $legalizationLength,
        public string $legalizationLabel,
        public string $legalizationBadgeClass,
        public string $legalizationVariant,
    ) {
    }
}

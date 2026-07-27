<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\AssetConstants;
use App\Model\Entity\Asset;

/**
 * Diccionario UI estático de activos. Fuente única del mapeo estado→pill/icono
 * y estado-acta→pill. Los templates lo consumen vía forRow() o las consts;
 * nunca redeclaran mapas inline (anti-drift).
 *
 * Dirección de dependencia: VM → Presentation (esta clase nunca importa un VM).
 */
final class AssetPresentation
{
    /**
     * Mapeo estado → clase pill del Sistema de Diseño.
     *
     * @var array<string, string>
     */
    public const STATUS_BADGES = [
        AssetConstants::STATUS_DISPONIBLE => 'pill-accent-soft',
        AssetConstants::STATUS_ASIGNADO => 'pill-info-soft',
        AssetConstants::STATUS_PRESTADO => 'pill-orange-soft',
        AssetConstants::STATUS_EN_REPARACION => 'pill-warning-soft',
        AssetConstants::STATUS_DADO_DE_BAJA => 'pill-secondary-soft',
    ];

    /**
     * Mapeo estado → icono Bootstrap Icons.
     *
     * @var array<string, string>
     */
    public const STATUS_ICONS = [
        AssetConstants::STATUS_DISPONIBLE => 'bi-box-seam',
        AssetConstants::STATUS_ASIGNADO => 'bi-person-check',
        AssetConstants::STATUS_PRESTADO => 'bi-arrow-left-right',
        AssetConstants::STATUS_EN_REPARACION => 'bi-tools',
        AssetConstants::STATUS_DADO_DE_BAJA => 'bi-x-octagon',
    ];

    /**
     * Mapeo estado de acta → clase pill del Sistema de Diseño.
     *
     * @var array<string, string>
     */
    public const ACTA_BADGES = [
        AssetConstants::ACTA_PENDIENTE => 'pill-warning-soft',
        AssetConstants::ACTA_CARGADA => 'pill-info-soft',
        AssetConstants::ACTA_VALIDADA => 'pill-accent-soft',
        AssetConstants::ACTA_RECHAZADA => 'pill-danger-soft',
    ];

    /**
     * Construye el DTO de fila para Assets/index. Encapsula las derivaciones
     * de estado, etiquetas y asociaciones que de otro modo vivirían inline en
     * el template.
     */
    public static function forRow(Asset $asset): AssetRowView
    {
        $status = $asset->status ?? '';

        return new AssetRowView(
            statusLabel: AssetConstants::STATUS_LABELS[$status] ?? $status,
            statusBadgeClass: self::STATUS_BADGES[$status] ?? 'pill-secondary-soft',
            statusIcon: self::STATUS_ICONS[$status] ?? 'bi-box',
            categoryName: $asset->asset_category->name ?? '—',
            responsibleName: $asset->responsible_employee->full_name ?? '—',
            operationCenterName: $asset->operation_center->name ?? '—',
        );
    }
}

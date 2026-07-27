<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Model\Entity\Consumable;

final class ConsumablePresentation
{
    /**
     * @return array{0:string, 1:string} [label, pillClass]
     */
    public static function stockBadge(Consumable $consumable): array
    {
        return $consumable->isLowStock()
            ? ['Stock bajo', 'pill-danger-soft']
            : ['Disponible', 'pill-accent-soft'];
    }

    /**
     * Construye el DTO de fila para Consumables/index.
     */
    public static function forRow(Consumable $consumable): ConsumableRowView
    {
        [$label, $class] = self::stockBadge($consumable);

        return new ConsumableRowView(
            stockLabel: $label,
            stockBadgeClass: $class,
            operationCenterName: $consumable->operation_center->name ?? '—',
        );
    }
}

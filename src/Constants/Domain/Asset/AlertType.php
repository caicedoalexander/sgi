<?php
declare(strict_types=1);

namespace App\Constants\Domain\Asset;

enum AlertType: string
{
    case STOCK_BAJO = 'stock_bajo';
    case ACTA_PENDIENTE = 'acta_pendiente';
    case ACTIVO_SIN_RESPONSABLE = 'activo_sin_responsable';
    case REGISTRO_INCOMPLETO = 'registro_incompleto';
    case MOVIMIENTO_SIN_CERRAR = 'movimiento_sin_cerrar';

    /**
     * Returns the human-readable label for this alert type.
     */
    public function label(): string
    {
        return match ($this) {
            self::STOCK_BAJO => 'Stock bajo',
            self::ACTA_PENDIENTE => 'Acta pendiente',
            self::ACTIVO_SIN_RESPONSABLE => 'Activo sin responsable',
            self::REGISTRO_INCOMPLETO => 'Registro incompleto',
            self::MOVIMIENTO_SIN_CERRAR => 'Movimiento sin cerrar',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }
}

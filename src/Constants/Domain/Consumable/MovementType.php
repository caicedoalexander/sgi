<?php
declare(strict_types=1);

namespace App\Constants\Domain\Consumable;

enum MovementType: string
{
    case INGRESO = 'ingreso';
    case SALIDA = 'salida';
    case AJUSTE = 'ajuste';

    /**
     * Returns the human-readable label for this movement type.
     */
    public function label(): string
    {
        return match ($this) {
            self::INGRESO => 'Ingreso',
            self::SALIDA => 'Salida',
            self::AJUSTE => 'Ajuste',
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

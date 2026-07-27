<?php
declare(strict_types=1);

namespace App\Constants\Domain\Asset;

enum ActaStatus: string
{
    case PENDIENTE = 'pendiente';
    case CARGADA = 'cargada';
    case VALIDADA = 'validada';
    case RECHAZADA = 'rechazada';

    /**
     * Returns the human-readable label for this acta status.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDIENTE => 'Pendiente',
            self::CARGADA => 'Cargada',
            self::VALIDADA => 'Validada',
            self::RECHAZADA => 'Rechazada',
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

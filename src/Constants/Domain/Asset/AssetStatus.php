<?php
declare(strict_types=1);

namespace App\Constants\Domain\Asset;

enum AssetStatus: string
{
    case DISPONIBLE = 'disponible';
    case ASIGNADO = 'asignado';
    case PRESTADO = 'prestado';
    case EN_REPARACION = 'en_reparacion';
    case DADO_DE_BAJA = 'dado_de_baja';

    /**
     * Returns the human-readable label for this asset status.
     */
    public function label(): string
    {
        return match ($this) {
            self::DISPONIBLE => 'Disponible',
            self::ASIGNADO => 'Asignado',
            self::PRESTADO => 'Prestado',
            self::EN_REPARACION => 'En reparación',
            self::DADO_DE_BAJA => 'Dado de baja',
        };
    }

    /**
     * Checks if this status is a terminal state.
     */
    public function isTerminal(): bool
    {
        return $this === self::DADO_DE_BAJA;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }
}

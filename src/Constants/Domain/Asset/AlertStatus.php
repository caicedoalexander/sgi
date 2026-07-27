<?php
declare(strict_types=1);

namespace App\Constants\Domain\Asset;

enum AlertStatus: string
{
    case ABIERTA = 'abierta';
    case RESUELTA = 'resuelta';
    case VENCIDA = 'vencida';

    /**
     * Returns the human-readable label for this alert status.
     */
    public function label(): string
    {
        return match ($this) {
            self::ABIERTA => 'Abierta',
            self::RESUELTA => 'Resuelta',
            self::VENCIDA => 'Vencida',
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

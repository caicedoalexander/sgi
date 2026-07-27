<?php
declare(strict_types=1);

namespace App\Constants\Domain\Asset;

enum AlertPriority: string
{
    case ALTA = 'alta';
    case MEDIA = 'media';
    case BAJA = 'baja';

    /**
     * Returns the human-readable label for this alert priority.
     */
    public function label(): string
    {
        return match ($this) {
            self::ALTA => 'Alta',
            self::MEDIA => 'Media',
            self::BAJA => 'Baja',
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

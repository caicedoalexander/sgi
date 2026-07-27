<?php
declare(strict_types=1);

namespace App\Constants\Domain\Asset;

enum MovementSource: string
{
    case WEB = 'web';
    case AGENT = 'agent';

    /**
     * Returns the human-readable label for this movement source.
     */
    public function label(): string
    {
        return match ($this) {
            self::WEB => 'Web',
            self::AGENT => 'Agente',
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

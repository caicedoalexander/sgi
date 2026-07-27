<?php
declare(strict_types=1);

namespace App\Constants\Domain\Asset;

enum DocumentType: string
{
    case ACTA = 'acta';
    case FACTURA_COMPRA = 'factura_compra';
    case FOTO = 'foto';
    case SOPORTE_MANTENIMIENTO = 'soporte_mantenimiento';
    case OTRO = 'otro';

    /**
     * Returns the human-readable label for this document type.
     */
    public function label(): string
    {
        return match ($this) {
            self::ACTA => 'Acta',
            self::FACTURA_COMPRA => 'Factura de compra',
            self::FOTO => 'Foto',
            self::SOPORTE_MANTENIMIENTO => 'Soporte de mantenimiento',
            self::OTRO => 'Otro',
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

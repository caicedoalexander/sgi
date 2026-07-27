<?php
declare(strict_types=1);

namespace App\Constants\Domain\Asset;

enum MovementType: string
{
    case INGRESO = 'ingreso';
    case ENTREGA = 'entrega';
    case DEVOLUCION = 'devolucion';
    case TRASLADO = 'traslado';
    case PRESTAMO = 'prestamo';
    case BAJA = 'baja';
    case AJUSTE = 'ajuste';

    /**
     * Returns the human-readable label for this movement type.
     */
    public function label(): string
    {
        return match ($this) {
            self::INGRESO => 'Ingreso',
            self::ENTREGA => 'Entrega',
            self::DEVOLUCION => 'Devolución',
            self::TRASLADO => 'Traslado',
            self::PRESTAMO => 'Préstamo',
            self::BAJA => 'Baja',
            self::AJUSTE => 'Ajuste',
        };
    }

    /**
     * Entrega, préstamo, devolución y baja requieren acta firmada (RN-05).
     */
    public function requiresActa(): bool
    {
        return match ($this) {
            self::ENTREGA, self::PRESTAMO, self::DEVOLUCION, self::BAJA => true,
            self::INGRESO, self::TRASLADO, self::AJUSTE => false,
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

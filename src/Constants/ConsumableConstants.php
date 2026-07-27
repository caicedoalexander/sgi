<?php
declare(strict_types=1);

namespace App\Constants;

use App\Constants\Domain\Consumable\MovementType;

final class ConsumableConstants
{
    public const MOVEMENT_INGRESO = MovementType::INGRESO->value;
    public const MOVEMENT_SALIDA = MovementType::SALIDA->value;
    public const MOVEMENT_AJUSTE = MovementType::AJUSTE->value;

    /** @var array<int, string> */
    public const MOVEMENT_TYPES = [
        self::MOVEMENT_INGRESO,
        self::MOVEMENT_SALIDA,
        self::MOVEMENT_AJUSTE,
    ];

    /** @var array<string, string> */
    public const MOVEMENT_LABELS = [
        self::MOVEMENT_INGRESO => 'Ingreso',
        self::MOVEMENT_SALIDA => 'Salida',
        self::MOVEMENT_AJUSTE => 'Ajuste',
    ];
}

<?php
declare(strict_types=1);

namespace App\Constants;

final class EmployeeStatusConstants
{
    public const ACTIVO = 'activo';
    public const RETIRADO = 'retirado';

    public const STATUSES = [
        self::ACTIVO,
        self::RETIRADO,
    ];

    public const STATUS_LABELS = [
        self::ACTIVO => 'Activo',
        self::RETIRADO => 'Retirado',
    ];
}

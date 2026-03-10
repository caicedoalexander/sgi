<?php
declare(strict_types=1);

namespace App\Constants;

final class ContractTypeConstants
{
    public const FIJO = 'FIJO';
    public const INDEFINIDO = 'INDEFINIDO';
    public const OBRA_LABOR = 'OBRA O LABOR DETERMINADA';

    public const ALL = [self::FIJO, self::INDEFINIDO, self::OBRA_LABOR];

    public const LABELS = [
        self::FIJO => 'FIJO',
        self::INDEFINIDO => 'INDEFINIDO',
        self::OBRA_LABOR => 'OBRA O LABOR DETERMINADA',
    ];
}

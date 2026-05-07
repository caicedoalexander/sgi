<?php
declare(strict_types=1);

namespace App\Constants;

final class GenderConstants
{
    public const MASCULINO = 'Masculino';
    public const FEMENINO = 'Femenino';
    public const OTRO = 'Otro';

    public const ALL = [self::MASCULINO, self::FEMENINO, self::OTRO];

    public const LABELS = [
        self::MASCULINO => 'Masculino',
        self::FEMENINO => 'Femenino',
        self::OTRO => 'Otro',
    ];
}

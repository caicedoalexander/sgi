<?php
declare(strict_types=1);

namespace App\Constants;

final class DocumentTypeConstants
{
    public const CC = 'CC';
    public const CE = 'CE';
    public const TI = 'TI';
    public const PP = 'PP';
    public const NIT = 'NIT';

    public const ALL = [self::CC, self::CE, self::TI, self::PP, self::NIT];

    public const LABELS = [
        self::CC => 'CC',
        self::CE => 'CE',
        self::TI => 'TI',
        self::PP => 'Pasaporte',
        self::NIT => 'NIT',
    ];
}

<?php
declare(strict_types=1);

namespace App\Constants;

final class ProviderConstants
{
    public const DOCUMENT_TYPE_NIT = 'NIT';
    public const DOCUMENT_TYPE_CC = 'CC';
    public const DOCUMENT_TYPE_OTHER = 'Otro';

    public const DOCUMENT_TYPES = [
        self::DOCUMENT_TYPE_NIT,
        self::DOCUMENT_TYPE_CC,
        self::DOCUMENT_TYPE_OTHER,
    ];
}

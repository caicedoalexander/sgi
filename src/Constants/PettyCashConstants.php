<?php
declare(strict_types=1);

namespace App\Constants;

final class PettyCashConstants
{
    public const STATUS_AGRUPACION = 'agrupacion';
    public const STATUS_CONTABILIDAD = 'contabilidad';
    public const STATUS_TESORERIA = 'tesoreria';
    public const STATUS_PAGADO = 'pagado';

    public const STATUSES = [
        self::STATUS_AGRUPACION,
        self::STATUS_CONTABILIDAD,
        self::STATUS_TESORERIA,
        self::STATUS_PAGADO,
    ];

    public const STATUS_LABELS = [
        'agrupacion' => 'Agrupación',
        'contabilidad' => 'Contabilidad',
        'tesoreria' => 'Tesorería',
        'pagado' => 'Pagado',
    ];

    public const STATUS_ICONS = [
        'agrupacion' => 'bi-collection',
        'contabilidad' => 'bi-calculator',
        'tesoreria' => 'bi-bank',
        'pagado' => 'bi-cash-coin',
    ];

    public const TRANSITIONS = [
        'agrupacion' => 'contabilidad',
        'contabilidad' => 'tesoreria',
        'tesoreria' => 'pagado',
        'pagado' => null,
    ];

    public const CODE_PREFIX = 'CM';
}

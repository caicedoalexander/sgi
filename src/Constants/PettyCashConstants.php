<?php
declare(strict_types=1);

namespace App\Constants;

use App\Constants\Concerns\GroupingPipelineConstantsTrait;

final class PettyCashConstants
{
    use GroupingPipelineConstantsTrait;

    public const CODE_PREFIX = 'CM';

    public const STATUS_BADGES = [
        self::STATUS_AGRUPACION => 'bg-info text-dark',
        self::STATUS_CONTABILIDAD => 'bg-primary',
        self::STATUS_TESORERIA => 'bg-warning text-dark',
        self::STATUS_AUTORIZACION_PAGO => 'bg-info',
        self::STATUS_PAGADA => 'bg-success',
    ];
}

<?php
declare(strict_types=1);

namespace App\Constants;

use App\Constants\Concerns\GroupingPipelineConstantsTrait;

final class RefundConstants
{
    use GroupingPipelineConstantsTrait;

    public const CODE_PREFIX = 'REI';

    // Beneficiary types (alineado con InvoiceConstants::HOLDER_TYPE_*, sin manual)
    public const BENEFICIARY_TYPE_EMPLOYEE = 'employee';
    public const BENEFICIARY_TYPE_PROVIDER = 'provider';

    public const BENEFICIARY_TYPES = [
        self::BENEFICIARY_TYPE_EMPLOYEE,
        self::BENEFICIARY_TYPE_PROVIDER,
    ];

    public const BENEFICIARY_TYPES_LABELS = [
        self::BENEFICIARY_TYPE_EMPLOYEE => 'Empleado',
        self::BENEFICIARY_TYPE_PROVIDER => 'Proveedor',
    ];
}

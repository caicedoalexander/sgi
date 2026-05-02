<?php
declare(strict_types=1);

namespace App\Constants;

final class RefundConstants
{
    public const STATUS_AGRUPACION = 'agrupacion';
    public const STATUS_CONTABILIDAD = 'contabilidad';
    public const STATUS_TESORERIA = 'tesoreria';
    public const STATUS_AUT_PAGO = 'aut_pago';
    public const STATUS_PAGADO = 'pagado';

    public const STATUSES = [
        self::STATUS_AGRUPACION,
        self::STATUS_CONTABILIDAD,
        self::STATUS_TESORERIA,
        self::STATUS_AUT_PAGO,
        self::STATUS_PAGADO,
    ];

    public const STATUS_LABELS = [
        'agrupacion' => 'Agrupación',
        'contabilidad' => 'Contabilidad',
        'tesoreria' => 'Tesorería',
        'aut_pago' => 'Aut. Pago',
        'pagado' => 'Pagado',
    ];

    public const STATUS_ICONS = [
        'agrupacion' => 'bi-collection',
        'contabilidad' => 'bi-calculator',
        'tesoreria' => 'bi-bank',
        'aut_pago' => 'bi-shield-check',
        'pagado' => 'bi-cash-coin',
    ];

    public const TRANSITIONS = [
        'agrupacion' => 'contabilidad',
        'contabilidad' => 'tesoreria',
        'tesoreria' => 'aut_pago',
        'aut_pago' => 'pagado',
        'pagado' => null,
    ];

    public const BACKWARD_TRANSITIONS = [
        self::STATUS_AGRUPACION => null,
        self::STATUS_CONTABILIDAD => self::STATUS_AGRUPACION,
        self::STATUS_TESORERIA => self::STATUS_CONTABILIDAD,
        self::STATUS_AUT_PAGO => self::STATUS_TESORERIA,
        self::STATUS_PAGADO => null,
    ];

    /**
     * @deprecated Migrado a pipeline_permissions a partir del plan
     *   2026-05-02-pipeline-permissions. Conservado solo por referencia
     *   histórica; no se consulta desde el código.
     */
    public const REGRESS_ROLE_BY_STATUS = [
        self::STATUS_CONTABILIDAD => [RoleConstants::CONTABILIDAD],
        self::STATUS_TESORERIA => [RoleConstants::TESORERIA],
        self::STATUS_AUT_PAGO => [RoleConstants::CONTADOR],
    ];

    public const OBSERVATION_TYPE_GENERAL = 'general';
    public const OBSERVATION_TYPE_REGRESSION = 'regression';

    public const OBSERVATION_TYPES = [
        self::OBSERVATION_TYPE_GENERAL,
        self::OBSERVATION_TYPE_REGRESSION,
    ];

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

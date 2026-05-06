<?php
declare(strict_types=1);

namespace App\Constants\Concerns;

use App\Constants\ObservationConstants;

/**
 * Constantes comunes de pipelines de "agrupación de pagos" (PettyCash, Refund).
 *
 * Flujo: agrupacion → contabilidad → tesoreria → autorizacion_pago → pagada.
 * `pagada` es terminal; la regresión hacia atrás se permite hasta `tesoreria`
 * (no desde `pagada`, porque la autorización ya materializó pagos en las
 * facturas hijas).
 */
trait GroupingPipelineConstantsTrait
{
    public const STATUS_AGRUPACION = 'agrupacion';
    public const STATUS_CONTABILIDAD = 'contabilidad';
    public const STATUS_TESORERIA = 'tesoreria';
    public const STATUS_AUTORIZACION_PAGO = 'autorizacion_pago';
    public const STATUS_PAGADA = 'pagada';

    public const STATUSES = [
        self::STATUS_AGRUPACION,
        self::STATUS_CONTABILIDAD,
        self::STATUS_TESORERIA,
        self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_PAGADA,
    ];

    public const STATUS_LABELS = [
        self::STATUS_AGRUPACION => 'Agrupación',
        self::STATUS_CONTABILIDAD => 'Contabilidad',
        self::STATUS_TESORERIA => 'Tesorería',
        self::STATUS_AUTORIZACION_PAGO => 'Autorización de pago',
        self::STATUS_PAGADA => 'Pagada',
    ];

    public const TRANSITIONS = [
        self::STATUS_AGRUPACION => self::STATUS_CONTABILIDAD,
        self::STATUS_CONTABILIDAD => self::STATUS_TESORERIA,
        self::STATUS_TESORERIA => self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_AUTORIZACION_PAGO => self::STATUS_PAGADA,
        self::STATUS_PAGADA => null,
    ];

    public const BACKWARD_TRANSITIONS = [
        self::STATUS_AGRUPACION => null,
        self::STATUS_CONTABILIDAD => self::STATUS_AGRUPACION,
        self::STATUS_TESORERIA => self::STATUS_CONTABILIDAD,
        self::STATUS_AUTORIZACION_PAGO => self::STATUS_TESORERIA,
        self::STATUS_PAGADA => null,
    ];

    public const OBSERVATION_TYPE_GENERAL = ObservationConstants::TYPE_GENERAL;
    public const OBSERVATION_TYPE_REGRESSION = ObservationConstants::TYPE_REGRESSION;

    public const OBSERVATION_TYPES = ObservationConstants::TYPES;
}

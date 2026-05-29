<?php
declare(strict_types=1);

namespace App\Constants;

use App\Constants\Domain\PettyCash\PipelineStatus;

final class PettyCashConstants
{
    // Pipeline statuses — fuente única en App\Constants\Domain\PettyCash\PipelineStatus.
    // Flujo: agrupacion → contabilidad → tesoreria → autorizacion_pago → verificacion_pago → pagada.
    public const STATUS_AGRUPACION = PipelineStatus::AGRUPACION->value;
    public const STATUS_CONTABILIDAD = PipelineStatus::CONTABILIDAD->value;
    public const STATUS_TESORERIA = PipelineStatus::TESORERIA->value;
    public const STATUS_AUTORIZACION_PAGO = PipelineStatus::AUTORIZACION_PAGO->value;
    public const STATUS_VERIFICACION_PAGO = PipelineStatus::VERIFICACION_PAGO->value;
    public const STATUS_PAGADA = PipelineStatus::PAGADA->value;

    public const STATUSES = [
        self::STATUS_AGRUPACION,
        self::STATUS_CONTABILIDAD,
        self::STATUS_TESORERIA,
        self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_VERIFICACION_PAGO,
        self::STATUS_PAGADA,
    ];

    public const STATUS_LABELS = [
        self::STATUS_AGRUPACION => 'Agrupación',
        self::STATUS_CONTABILIDAD => 'Contabilidad',
        self::STATUS_TESORERIA => 'Tesorería',
        self::STATUS_AUTORIZACION_PAGO => 'Autorización de pago',
        self::STATUS_VERIFICACION_PAGO => 'Verificación de pago',
        self::STATUS_PAGADA => 'Pagada',
    ];

    public const TRANSITIONS = [
        self::STATUS_AGRUPACION => self::STATUS_CONTABILIDAD,
        self::STATUS_CONTABILIDAD => self::STATUS_TESORERIA,
        self::STATUS_TESORERIA => self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_AUTORIZACION_PAGO => self::STATUS_VERIFICACION_PAGO,
        self::STATUS_VERIFICACION_PAGO => self::STATUS_PAGADA,
        self::STATUS_PAGADA => null,
    ];

    // `pagada` es terminal; la regresión se permite hasta `tesoreria` (no desde
    // `pagada`, porque la autorización ya materializó pagos en las facturas hijas).
    public const BACKWARD_TRANSITIONS = [
        self::STATUS_AGRUPACION => null,
        self::STATUS_CONTABILIDAD => self::STATUS_AGRUPACION,
        self::STATUS_TESORERIA => self::STATUS_CONTABILIDAD,
        self::STATUS_AUTORIZACION_PAGO => self::STATUS_TESORERIA,
        self::STATUS_VERIFICACION_PAGO => self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_PAGADA => null,
    ];

    public const OBSERVATION_TYPE_GENERAL = ObservationConstants::TYPE_GENERAL;
    public const OBSERVATION_TYPE_REGRESSION = ObservationConstants::TYPE_REGRESSION;
    public const OBSERVATION_TYPES = ObservationConstants::TYPES;

    public const CODE_PREFIX = 'CM';
}

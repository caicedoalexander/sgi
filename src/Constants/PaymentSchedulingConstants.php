<?php
declare(strict_types=1);

namespace App\Constants;

use App\Constants\Domain\PaymentScheduling\PipelineStatus;

final class PaymentSchedulingConstants
{
    // Pipeline statuses — fuente única en App\Constants\Domain\PaymentScheduling\PipelineStatus.
    public const STATUS_BORRADOR = PipelineStatus::BORRADOR->value;
    public const STATUS_TESORERIA = PipelineStatus::TESORERIA->value;
    public const STATUS_AUTORIZACION_PAGO = PipelineStatus::AUTORIZACION_PAGO->value;
    public const STATUS_VERIFICACION_PAGO = PipelineStatus::VERIFICACION_PAGO->value;
    public const STATUS_PAGADA = PipelineStatus::PAGADA->value;

    public const PIPELINE_STATUSES = [
        self::STATUS_BORRADOR,
        self::STATUS_TESORERIA,
        self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_VERIFICACION_PAGO,
        self::STATUS_PAGADA,
    ];

    public const STATUS_LABELS = [
        self::STATUS_BORRADOR => 'Borrador',
        self::STATUS_TESORERIA => 'Tesorería',
        self::STATUS_AUTORIZACION_PAGO => 'Autorización de pago',
        self::STATUS_VERIFICACION_PAGO => 'Verificación de pago',
        self::STATUS_PAGADA => 'Pagada',
    ];

    // Code prefix
    public const CODE_PREFIX = 'PRO';

    // El avance (next) y la regresión (previous) son responsabilidad del enum
    // App\Constants\Domain\PaymentScheduling\PipelineStatus (next()/previous()),
    // consumido por PaymentSchedulingPipelineService::getNextStatus/getPreviousStatus.

    // Target status when Contador rejects from autorizacion_pago.
    // Espeja PipelineStatus::rejectionTarget(); se conserva como const porque las
    // expresiones constantes no pueden invocar métodos del enum.
    public const REJECTION_TARGET = self::STATUS_TESORERIA;

    // Tipos de observación — definidos en ObservationConstants.
    public const OBSERVATION_TYPE_GENERAL = ObservationConstants::TYPE_GENERAL;
    public const OBSERVATION_TYPE_REGRESSION = ObservationConstants::TYPE_REGRESSION;

    public const OBSERVATION_TYPES = ObservationConstants::TYPES;
}

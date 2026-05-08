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

    // Backward transitions for the regress operation.
    // Excluida `pagada` por irreversibilidad de invoice_payments creados.
    public const BACKWARD_TRANSITIONS = [
        self::STATUS_BORRADOR => null,
        self::STATUS_TESORERIA => self::STATUS_BORRADOR,
        self::STATUS_AUTORIZACION_PAGO => self::STATUS_TESORERIA,
        self::STATUS_VERIFICACION_PAGO => self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_PAGADA => null,
    ];

    // Forward transitions (extracted from PaymentSchedulingPipelineService::TRANSITIONS).
    public const FORWARD_TRANSITIONS = [
        self::STATUS_BORRADOR => self::STATUS_TESORERIA,
        self::STATUS_TESORERIA => self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_AUTORIZACION_PAGO => self::STATUS_VERIFICACION_PAGO,
        self::STATUS_VERIFICACION_PAGO => self::STATUS_PAGADA,
        self::STATUS_PAGADA => null,
    ];

    // Target status when Contador rejects from autorizacion_pago.
    public const REJECTION_TARGET = self::STATUS_TESORERIA;

    // Tipos de observación — definidos en ObservationConstants.
    public const OBSERVATION_TYPE_GENERAL = ObservationConstants::TYPE_GENERAL;
    public const OBSERVATION_TYPE_REGRESSION = ObservationConstants::TYPE_REGRESSION;

    public const OBSERVATION_TYPES = ObservationConstants::TYPES;
}

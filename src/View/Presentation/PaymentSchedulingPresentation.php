<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\PaymentSchedulingConstants;

/**
 * Configuración de presentación (badges Bootstrap, iconos) para el pipeline
 * de programación de pagos.
 */
final class PaymentSchedulingPresentation
{
    public const STATUS_BADGES = [
        PaymentSchedulingConstants::STATUS_BORRADOR          => 'bg-secondary',
        PaymentSchedulingConstants::STATUS_TESORERIA         => 'bg-info',
        PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO => 'bg-info',
        PaymentSchedulingConstants::STATUS_PAGADA            => 'bg-success',
    ];

    public const STATUS_ICONS = [
        PaymentSchedulingConstants::STATUS_BORRADOR          => 'bi-pencil',
        PaymentSchedulingConstants::STATUS_TESORERIA         => 'bi-bank',
        PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO => 'bi-shield-check',
        PaymentSchedulingConstants::STATUS_PAGADA            => 'bi-cash-coin',
    ];
}

<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\PaymentSchedulingConstants;

/**
 * Configuración de presentación (clases pill del Sistema de Diseño, iconos)
 * para el pipeline de programación de pagos.
 */
final class PaymentSchedulingPresentation
{
    public const STATUS_BADGES = [
        PaymentSchedulingConstants::STATUS_BORRADOR          => 'pill-muted',
        PaymentSchedulingConstants::STATUS_TESORERIA         => 'pill-info-soft',
        PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO => 'pill-info-soft',
        PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO => 'pill-warning-soft',
        PaymentSchedulingConstants::STATUS_PAGADA            => 'pill-primary-soft',
    ];

    public const STATUS_ICONS = [
        PaymentSchedulingConstants::STATUS_BORRADOR          => 'bi-pencil',
        PaymentSchedulingConstants::STATUS_TESORERIA         => 'bi-bank',
        PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO => 'bi-shield-check',
        PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO => 'bi-hourglass-split',
        PaymentSchedulingConstants::STATUS_PAGADA            => 'bi-cash-coin',
    ];
}

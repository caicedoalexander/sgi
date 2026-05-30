<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\PaymentSchedulingConstants;

/**
 * Configuración de presentación (clases pill del Sistema de Diseño)
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
}

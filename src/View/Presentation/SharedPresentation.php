<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\InvoiceConstants;

/**
 * Configuración de presentación compartida entre múltiples pipelines o
 * vistas (formato de fechas, badges de "Listo para pago").
 */
final class SharedPresentation
{
    public const DATE_FORMAT = 'd/m/Y H:i';

    public const READY_FOR_PAYMENT_BADGES = [
        InvoiceConstants::READY_FOR_PAYMENT_SI          => 'pill-primary-soft',
        InvoiceConstants::READY_FOR_PAYMENT_PRIORITARIO => 'pill-danger-soft',
        InvoiceConstants::READY_FOR_PAYMENT_PSE         => 'pill-muted',
    ];
}

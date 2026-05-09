<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\InvoiceConstants;

/**
 * Configuración de presentación (badges Bootstrap, iconos) para el pipeline
 * de facturas. Datos puros de UI — no contiene reglas de dominio.
 */
final class InvoicePresentation
{
    public const STATUS_BADGES = [
        InvoiceConstants::STATUS_APROBACION        => 'bg-warning text-dark',
        InvoiceConstants::STATUS_CONTABILIDAD      => 'bg-secondary',
        InvoiceConstants::STATUS_TESORERIA         => 'bg-info',
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'bg-warning text-dark',
        InvoiceConstants::STATUS_VERIFICACION_PAGO => 'bg-warning',
        InvoiceConstants::STATUS_PAGADA            => 'bg-success',
        InvoiceConstants::STATUS_LEGALIZADA        => 'bg-success',
    ];

    public const STATUS_ICONS = [
        InvoiceConstants::STATUS_APROBACION        => 'bi-check-circle',
        InvoiceConstants::STATUS_CONTABILIDAD      => 'bi-calculator',
        InvoiceConstants::STATUS_TESORERIA         => 'bi-bank',
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'bi-shield-check',
        InvoiceConstants::STATUS_VERIFICACION_PAGO => 'bi-hourglass-split',
        InvoiceConstants::STATUS_PAGADA            => 'bi-cash-coin',
        InvoiceConstants::STATUS_LEGALIZADA        => 'bi-cash-coin',
    ];
}

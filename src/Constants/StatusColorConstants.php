<?php
declare(strict_types=1);

namespace App\Constants;

final class StatusColorConstants
{
    public const PIPELINE_STATUS_BADGES = [
        'aprobacion' => 'bg-warning text-dark',
        'contabilidad' => 'bg-primary',
        'tesoreria' => 'bg-info',
        'autorizacion_pago' => 'bg-info',
        'pagada' => 'bg-success',
        'legalizada' => 'bg-success',
        'agrupacion' => 'bg-secondary',
        'borrador' => 'bg-secondary',
        'registro' => 'bg-light text-dark',
        'rrhh' => 'bg-purple',
        'revision_firmas' => 'bg-warning text-dark',
        'gdp' => 'bg-dark',
        'rechazada' => 'bg-danger',
    ];

    public const READY_FOR_PAYMENT_BADGES = [
        InvoiceConstants::READY_FOR_PAYMENT_SI => 'bg-success',
        'No' => 'bg-secondary',
        'Anticipo Empleado' => 'bg-info text-dark',
        'Anticipo Proveedor' => 'bg-primary',
        InvoiceConstants::READY_FOR_PAYMENT_PRIORITARIO => 'bg-danger',
        InvoiceConstants::READY_FOR_PAYMENT_PSE => 'bg-dark',
        'No Legalización' => 'bg-warning text-dark',
        'Reintegro' => 'bg-secondary',
    ];
}

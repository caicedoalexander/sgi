<?php
declare(strict_types=1);

namespace App\Constants;

final class InvoiceConstants
{
    // Tipos de documento
    public const DOCUMENT_TYPES = [
        'Factura', 'Nota Debito', 'Caja menor', 'Tarjeta de Crédito',
        'Reintegro', 'Legalización', 'Recibo', 'Anticipo',
    ];

    // Estados de aprobacion de area
    public const APPROVAL_PENDING = 'Pendiente';
    public const APPROVAL_APPROVED = 'Aprobada';
    public const APPROVAL_REJECTED = 'Rechazada';
    public const APPROVAL_STATUSES = [self::APPROVAL_PENDING, self::APPROVAL_APPROVED, self::APPROVAL_REJECTED];

    // Validacion DIAN
    public const DIAN_PENDING = 'Pendiente';
    public const DIAN_APPROVED = 'Aprobada';
    public const DIAN_REJECTED = 'Rechazado';
    public const DIAN_STATUSES = [self::DIAN_PENDING, self::DIAN_APPROVED, self::DIAN_REJECTED];

    // Estados de pago
    public const PAYMENT_FULL = 'Pago total';
    public const PAYMENT_PARTIAL = 'Pago Parcial';
    public const PAYMENT_STATUSES = [self::PAYMENT_FULL, self::PAYMENT_PARTIAL];

    // Lista para pago
    public const READY_FOR_PAYMENT_OPTIONS = [
        'Si', 'No', 'Anticipo Empleado', 'Anticipo Proveedor',
        'Pago prioritario', 'Pago PSE', 'No Legalización', 'Reintegro',
    ];

    // Token de aprobacion (horas de validez)
    public const APPROVAL_TOKEN_HOURS = 48;
}

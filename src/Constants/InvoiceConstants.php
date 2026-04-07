<?php
declare(strict_types=1);

namespace App\Constants;

final class InvoiceConstants
{
    // Tipos de documento
    public const DOCUMENT_TYPES = [
        self::DOCTYPE_FACTURA,
        self::DOCTYPE_NOTA_DEBITO,
        self::DOCTYPE_CAJA_MENOR,
        self::DOCTYPE_TARJETA_CREDITO,
        self::DOCTYPE_REINTEGRO,
        self::DOCTYPE_LEGALIZACION,
        self::DOCTYPE_RECIBO,
        self::DOCTYPE_ANTICIPO,
    ];

    // Estados de aprobacion de area
    public const APPROVAL_PENDING = 'Pendiente';
    public const APPROVAL_APPROVED = 'Aprobada';
    public const APPROVAL_REJECTED = 'Rechazada';
    public const APPROVAL_STATUSES = [self::APPROVAL_PENDING, self::APPROVAL_APPROVED, self::APPROVAL_REJECTED];

    // Individual approver statuses (for invoice_approvals table)
    public const APPROVER_STATUS_PENDING = 'Pendiente';
    public const APPROVER_STATUS_APPROVED = 'Aprobada';
    public const APPROVER_STATUS_REJECTED = 'Rechazada';

    public const APPROVER_STATUSES = [
        self::APPROVER_STATUS_PENDING,
        self::APPROVER_STATUS_APPROVED,
        self::APPROVER_STATUS_REJECTED,
    ];

    // Validacion DIAN
    public const DIAN_PENDING = 'Pendiente';
    public const DIAN_APPROVED = 'Aprobada';
    public const DIAN_REJECTED = 'Rechazado';
    public const DIAN_STATUSES = [self::DIAN_PENDING, self::DIAN_APPROVED, self::DIAN_REJECTED];

    // Pipeline statuses
    public const STATUS_APROBACION = 'aprobacion';
    public const STATUS_CONTABILIDAD = 'contabilidad';
    public const STATUS_TESORERIA = 'tesoreria';
    public const STATUS_AUTORIZACION_PAGO = 'autorizacion_pago';
    public const STATUS_PAGADA = 'pagada';

    public const PIPELINE_STATUSES = [
        self::STATUS_APROBACION,
        self::STATUS_CONTABILIDAD,
        self::STATUS_TESORERIA,
        self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_PAGADA,
    ];

    // Document types
    public const DOCTYPE_FACTURA = 'Factura';
    public const DOCTYPE_NOTA_DEBITO = 'Nota Debito';
    public const DOCTYPE_CAJA_MENOR = 'Caja menor';
    public const DOCTYPE_TARJETA_CREDITO = 'Tarjeta de Crédito';
    public const DOCTYPE_REINTEGRO = 'Reintegro';
    public const DOCTYPE_LEGALIZACION = 'Legalización';
    public const DOCTYPE_RECIBO = 'Recibo';
    public const DOCTYPE_ANTICIPO = 'Anticipo';

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

    // Documento Equivalente - tipos de titular
    public const HOLDER_TYPE_PROVIDER = 'provider';
    public const HOLDER_TYPE_EMPLOYEE = 'employee';
    public const HOLDER_TYPE_MANUAL = 'manual';
    public const HOLDER_TYPES = [self::HOLDER_TYPE_PROVIDER, self::HOLDER_TYPE_EMPLOYEE, self::HOLDER_TYPE_MANUAL];
}

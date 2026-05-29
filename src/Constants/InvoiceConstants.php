<?php
declare(strict_types=1);

namespace App\Constants;

use App\Constants\Domain\Invoice\PipelineStatus;

final class InvoiceConstants
{
    // Document types
    public const DOCTYPE_FACTURA = 'Factura';
    public const DOCTYPE_NOTA_DEBITO = 'Nota Debito';
    public const DOCTYPE_CAJA_MENOR = 'Caja menor';
    public const DOCTYPE_TARJETA_CREDITO = 'Tarjeta de Crédito';
    public const DOCTYPE_REINTEGRO = 'Reintegro';
    public const DOCTYPE_LEGALIZACION = 'Legalización';
    public const DOCTYPE_RECIBO = 'Recibo';
    public const DOCTYPE_RECIBO_CAJA = 'Recibo de Caja';
    public const DOCTYPE_ANTICIPO = 'Anticipo';

    public const DOCUMENT_TYPES = [
        self::DOCTYPE_FACTURA,
        self::DOCTYPE_NOTA_DEBITO,
        self::DOCTYPE_CAJA_MENOR,
        self::DOCTYPE_TARJETA_CREDITO,
        self::DOCTYPE_REINTEGRO,
        self::DOCTYPE_LEGALIZACION,
        self::DOCTYPE_RECIBO,
        self::DOCTYPE_RECIBO_CAJA,
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
    public const APPROVER_STATUS_SUPERSEDED = 'Reemplazada';

    public const APPROVER_STATUSES_ACTIVE = [
        self::APPROVER_STATUS_PENDING,
        self::APPROVER_STATUS_APPROVED,
        self::APPROVER_STATUS_REJECTED,
    ];

    // Validacion DIAN
    public const DIAN_PENDING = 'Pendiente';
    public const DIAN_APPROVED = 'Aprobada';
    // OJO: 'Rechazado' (masculino) — divergencia de género DELIBERADA frente a
    // APPROVAL_REJECTED = 'Rechazada' (femenino). NO unificar: son labels visibles
    // distintos (estado DIAN del documento vs aprobación de área) y romperían los
    // datos ya persistidos / los matches por string.
    public const DIAN_REJECTED = 'Rechazado';
    public const DIAN_STATUSES = [self::DIAN_PENDING, self::DIAN_APPROVED, self::DIAN_REJECTED];

    // Pipeline statuses — fuente única en App\Constants\Domain\Invoice\PipelineStatus.
    // Estas constantes delegan al enum y se preservan para retrocompatibilidad
    // con call-sites que aún consumen strings.
    public const STATUS_APROBACION = PipelineStatus::APROBACION->value;
    public const STATUS_CONTABILIDAD = PipelineStatus::CONTABILIDAD->value;
    public const STATUS_TESORERIA = PipelineStatus::TESORERIA->value;
    public const STATUS_AUTORIZACION_PAGO = PipelineStatus::AUTORIZACION_PAGO->value;
    public const STATUS_VERIFICACION_PAGO = PipelineStatus::VERIFICACION_PAGO->value;
    public const STATUS_PAGADA = PipelineStatus::PAGADA->value;
    // Estado terminal exclusivo para document_type = Legalización.
    // No participa en PIPELINE_STATUSES (flujo normal). Ver self::ALL_STATUSES.
    public const STATUS_LEGALIZADA = PipelineStatus::LEGALIZADA->value;

    public const PIPELINE_STATUSES = [
        self::STATUS_APROBACION,
        self::STATUS_CONTABILIDAD,
        self::STATUS_TESORERIA,
        self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_VERIFICACION_PAGO,
        self::STATUS_PAGADA,
    ];

    // Conjunto completo de estados válidos para invoices.pipeline_status (incluye STATUS_LEGALIZADA,
    // estado terminal de las legalizaciones que no aparece en PIPELINE_STATUSES).
    public const ALL_STATUSES = [
        self::STATUS_APROBACION,
        self::STATUS_CONTABILIDAD,
        self::STATUS_TESORERIA,
        self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_VERIFICACION_PAGO,
        self::STATUS_PAGADA,
        self::STATUS_LEGALIZADA,
    ];

    // Pipeline visual exclusivo de Legalizaciones: 3 pasos.
    public const PIPELINE_STATUSES_LEGALIZACION = [
        self::STATUS_APROBACION,
        self::STATUS_CONTABILIDAD,
        self::STATUS_LEGALIZADA,
    ];

    public const STATUS_LABELS = [
        self::STATUS_APROBACION        => 'Aprobación',
        self::STATUS_CONTABILIDAD      => 'Contabilidad',
        self::STATUS_TESORERIA         => 'Tesorería',
        self::STATUS_AUTORIZACION_PAGO => 'Autorización de pago',
        self::STATUS_VERIFICACION_PAGO => 'Verificación de pago',
        self::STATUS_PAGADA            => 'Pagada',
        self::STATUS_LEGALIZADA        => 'Legalizada',
    ];

    // Estados de pago
    public const PAYMENT_FULL = 'Pago total';
    public const PAYMENT_PARTIAL = 'Pago Parcial';
    public const PAYMENT_STATUSES = [self::PAYMENT_FULL, self::PAYMENT_PARTIAL];

    // Estado de cada registro de pago (invoice_payments.status).
    // Slugs en inglés por convención de estados técnicos internos (ver CLAUDE.md
    // "Slug language convention"). Los estados del pipeline de la factura usan español.
    public const PAYMENT_RECORD_PENDING = 'pending';
    public const PAYMENT_RECORD_AUTHORIZED = 'authorized';
    public const PAYMENT_RECORD_REJECTED = 'rejected';

    // Lista para pago
    public const READY_FOR_PAYMENT_SI = 'Si';
    public const READY_FOR_PAYMENT_PSE = 'Pago PSE';
    public const READY_FOR_PAYMENT_PRIORITARIO = 'Pago prioritario';

    public const READY_FOR_PAYMENT_OPTIONS = [
        self::READY_FOR_PAYMENT_SI,
        self::READY_FOR_PAYMENT_PSE,
        self::READY_FOR_PAYMENT_PRIORITARIO,
    ];

    // Token de aprobacion (horas de validez)
    public const APPROVAL_TOKEN_HOURS = 48;

    // Documento Equivalente - tipos de titular
    public const HOLDER_TYPE_PROVIDER = 'provider';
    public const HOLDER_TYPE_EMPLOYEE = 'employee';
    public const HOLDER_TYPE_MANUAL = 'manual';
    public const HOLDER_TYPES = [self::HOLDER_TYPE_PROVIDER, self::HOLDER_TYPE_EMPLOYEE, self::HOLDER_TYPE_MANUAL];

    // Tipos de observación (invoice_observations.type) — definidos en ObservationConstants.
    public const OBSERVATION_TYPE_GENERAL = ObservationConstants::TYPE_GENERAL;
    public const OBSERVATION_TYPE_REGRESSION = ObservationConstants::TYPE_REGRESSION;
    public const OBSERVATION_TYPE_EXTERNAL_APPROVAL = ObservationConstants::TYPE_EXTERNAL_APPROVAL;

    public const OBSERVATION_TYPES = ObservationConstants::TYPES;
}

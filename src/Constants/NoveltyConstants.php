<?php
declare(strict_types=1);

namespace App\Constants;

final class NoveltyConstants
{
    // Pipeline statuses (ordered)
    public const STATUS_REGISTRO = 'registro';
    public const STATUS_APROBACION = 'aprobacion';
    public const STATUS_RRHH = 'rrhh';
    public const STATUS_CONTABILIDAD = 'contabilidad';
    public const STATUS_REVISION_FIRMAS = 'revision_firmas';
    public const STATUS_GDP = 'gdp';
    public const STATUS_TESORERIA = 'tesoreria';
    public const STATUS_AUT_PAGO = 'aut_pago';
    public const STATUS_PAGADA = 'pagada';
    public const STATUS_RECHAZADA = 'rechazada';

    // Backward compat for renamed status
    public const STATUS_FIRMAS_APROBACION = self::STATUS_REVISION_FIRMAS;

    public const PIPELINE_STATUSES = [
        self::STATUS_APROBACION,
        self::STATUS_RRHH,
        self::STATUS_CONTABILIDAD,
        self::STATUS_REVISION_FIRMAS,
        self::STATUS_GDP,
        self::STATUS_TESORERIA,
        self::STATUS_AUT_PAGO,
        self::STATUS_PAGADA,
    ];

    // Novelty individual pipeline (before liquidation doc takes over)
    public const NOVELTY_STATUSES = [
        self::STATUS_APROBACION,
        self::STATUS_RRHH,
        self::STATUS_CONTABILIDAD,
    ];

    // Statuses considered "active" (approved and processed)
    public const ACTIVE_STATUSES = [
        self::STATUS_RRHH,
        self::STATUS_CONTABILIDAD,
        self::STATUS_REVISION_FIRMAS,
        self::STATUS_GDP,
        self::STATUS_TESORERIA,
        self::STATUS_PAGADA,
    ];

    public const ALL_STATUSES = [
        self::STATUS_REGISTRO,
        self::STATUS_APROBACION,
        self::STATUS_RRHH,
        self::STATUS_CONTABILIDAD,
        self::STATUS_REVISION_FIRMAS,
        self::STATUS_GDP,
        self::STATUS_TESORERIA,
        self::STATUS_AUT_PAGO,
        self::STATUS_PAGADA,
        self::STATUS_RECHAZADA,
    ];

    public const STATUS_LABELS = [
        self::STATUS_REGISTRO => 'Registro',
        self::STATUS_APROBACION => 'Aprobación',
        self::STATUS_RRHH => 'RRHH',
        self::STATUS_CONTABILIDAD => 'Contabilidad',
        self::STATUS_REVISION_FIRMAS => 'Revisión y Firmas de documentos',
        self::STATUS_GDP => 'GDP',
        self::STATUS_TESORERIA => 'Tesorería',
        self::STATUS_AUT_PAGO => 'Aut. Pago',
        self::STATUS_PAGADA => 'Pagada',
        self::STATUS_RECHAZADA => 'Rechazada',
    ];

    public const STATUS_ICONS = [
        self::STATUS_REGISTRO => 'bi-pencil-square',
        self::STATUS_APROBACION => 'bi-person-check',
        self::STATUS_RRHH => 'bi-people',
        self::STATUS_CONTABILIDAD => 'bi-calculator',
        self::STATUS_REVISION_FIRMAS => 'bi-pen',
        self::STATUS_GDP => 'bi-clipboard-check',
        self::STATUS_TESORERIA => 'bi-bank',
        self::STATUS_AUT_PAGO => 'bi-shield-check',
        self::STATUS_PAGADA => 'bi-cash-coin',
    ];

    // Linear transitions
    public const TRANSITIONS = [
        self::STATUS_APROBACION => self::STATUS_RRHH,
        self::STATUS_RRHH => self::STATUS_CONTABILIDAD,
        self::STATUS_CONTABILIDAD => self::STATUS_REVISION_FIRMAS,
        self::STATUS_REVISION_FIRMAS => self::STATUS_GDP,
        self::STATUS_GDP => self::STATUS_TESORERIA,
        self::STATUS_TESORERIA => self::STATUS_AUT_PAGO,
        self::STATUS_AUT_PAGO => self::STATUS_PAGADA,
        self::STATUS_PAGADA => null,
    ];

    // Schedule types
    public const SCHEDULE_DAYS = 'days';
    public const SCHEDULE_HOURS = 'hours';
    public const SCHEDULE_TYPES = [self::SCHEDULE_DAYS, self::SCHEDULE_HOURS];
    public const SCHEDULE_LABELS = [
        self::SCHEDULE_DAYS => 'Por días',
        self::SCHEDULE_HOURS => 'Por horas',
    ];

    // Period options (for liquidation docs)
    public const PERIOD_PRIMERA_QUINCENA = 'primera_quincena';
    public const PERIOD_SEGUNDA_QUINCENA = 'segunda_quincena';
    public const PERIOD_CIERRE_NOMINA = 'cierre_nomina';
    public const PERIODS = [
        self::PERIOD_PRIMERA_QUINCENA,
        self::PERIOD_SEGUNDA_QUINCENA,
        self::PERIOD_CIERRE_NOMINA,
    ];
    public const PERIOD_LABELS = [
        self::PERIOD_PRIMERA_QUINCENA => 'Primera Quincena',
        self::PERIOD_SEGUNDA_QUINCENA => 'Segunda Quincena',
        self::PERIOD_CIERRE_NOMINA => 'Cierre de Nómina',
    ];

    // Payment statuses (for liquidation docs)
    public const PAYMENT_PAGADO = 'pagado';
    public const PAYMENT_PENDIENTE = 'pendiente';
    public const PAYMENT_NA = 'na';
    public const PAYMENT_STATUSES = [
        self::PAYMENT_PAGADO,
        self::PAYMENT_PENDIENTE,
        self::PAYMENT_NA,
    ];
    public const PAYMENT_LABELS = [
        self::PAYMENT_PAGADO => 'Pagado',
        self::PAYMENT_PENDIENTE => 'Pendiente',
        self::PAYMENT_NA => 'N/A',
    ];

    // Document types (for novelty_documents)
    public const DOC_TYPE_SUPPORT = 'support';
    public const DOC_TYPE_LIQUIDATION = 'liquidation_document';

    // Signer types (for liquidation doc signatures) — jefe_inmediato removed
    public const SIGNER_CONTADOR = 'contador';
    public const SIGNER_COORDINADOR_ADMIN = 'coordinador_admin';
    public const SIGNER_TRABAJADOR = 'trabajador';
    public const SIGNER_TYPES = [
        self::SIGNER_CONTADOR,
        self::SIGNER_COORDINADOR_ADMIN,
        self::SIGNER_TRABAJADOR,
    ];
    public const SIGNER_LABELS = [
        self::SIGNER_CONTADOR => 'Contador',
        self::SIGNER_COORDINADOR_ADMIN => 'Coordinador Administrativo',
        self::SIGNER_TRABAJADOR => 'Trabajador',
    ];

    // Approval values (for area_approval field)
    public const APPROVAL_PENDING = 'Pendiente';
    public const APPROVAL_APPROVED = 'Aprobada';
    public const APPROVAL_REJECTED = 'Rechazada';

    // Calendar event colors by novelty type ID (cycles for IDs > count)
    public const CALENDAR_COLORS = [
        '#469D61', // green
        '#CD6A15', // orange
        '#3B82F6', // blue
        '#8B5CF6', // purple
        '#EF4444', // red
        '#F59E0B', // amber
        '#06B6D4', // cyan
        '#EC4899', // pink
        '#10B981', // emerald
        '#6366F1', // indigo
    ];

    // Backward compat
    public const STATUS_PENDING = self::STATUS_REGISTRO;
    public const STATUS_APPROVED = self::STATUS_PAGADA;
    public const STATUS_REJECTED = self::STATUS_RECHAZADA;
    public const STATUSES = self::ALL_STATUSES;
}

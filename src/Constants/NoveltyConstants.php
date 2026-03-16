<?php
declare(strict_types=1);

namespace App\Constants;

final class NoveltyConstants
{
    // Pipeline statuses (ordered)
    public const STATUS_REGISTRO = 'registro';
    public const STATUS_RRHH = 'rrhh';
    public const STATUS_CONTABILIDAD = 'contabilidad';
    public const STATUS_FIRMAS_APROBACION = 'firmas_aprobacion';
    public const STATUS_GDP = 'gdp';
    public const STATUS_TESORERIA = 'tesoreria';
    public const STATUS_PAGADA = 'pagada';
    public const STATUS_RECHAZADA = 'rechazada';

    public const PIPELINE_STATUSES = [
        self::STATUS_RRHH,
        self::STATUS_CONTABILIDAD,
        self::STATUS_FIRMAS_APROBACION,
        self::STATUS_GDP,
        self::STATUS_TESORERIA,
        self::STATUS_PAGADA,
    ];

    public const ALL_STATUSES = [
        self::STATUS_REGISTRO,
        self::STATUS_RRHH,
        self::STATUS_CONTABILIDAD,
        self::STATUS_FIRMAS_APROBACION,
        self::STATUS_GDP,
        self::STATUS_TESORERIA,
        self::STATUS_PAGADA,
        self::STATUS_RECHAZADA,
    ];

    public const STATUS_LABELS = [
        self::STATUS_REGISTRO => 'Registro',
        self::STATUS_RRHH => 'RRHH',
        self::STATUS_CONTABILIDAD => 'Contabilidad',
        self::STATUS_FIRMAS_APROBACION => 'Firmas y Aprobación',
        self::STATUS_GDP => 'GDP',
        self::STATUS_TESORERIA => 'Tesorería',
        self::STATUS_PAGADA => 'Pagada',
        self::STATUS_RECHAZADA => 'Rechazada',
    ];

    public const STATUS_ICONS = [
        self::STATUS_REGISTRO => 'bi-pencil-square',
        self::STATUS_RRHH => 'bi-people',
        self::STATUS_CONTABILIDAD => 'bi-calculator',
        self::STATUS_FIRMAS_APROBACION => 'bi-pen',
        self::STATUS_GDP => 'bi-clipboard-check',
        self::STATUS_TESORERIA => 'bi-bank',
        self::STATUS_PAGADA => 'bi-cash-coin',
    ];

    // Linear transitions
    public const TRANSITIONS = [
        self::STATUS_RRHH => self::STATUS_CONTABILIDAD,
        self::STATUS_CONTABILIDAD => self::STATUS_FIRMAS_APROBACION,
        self::STATUS_FIRMAS_APROBACION => self::STATUS_GDP,
        self::STATUS_GDP => self::STATUS_TESORERIA,
        self::STATUS_TESORERIA => self::STATUS_PAGADA,
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

    // Signer types (for liquidation doc signatures)
    public const SIGNER_CONTADOR = 'contador';
    public const SIGNER_COORDINADOR_ADMIN = 'coordinador_admin';
    public const SIGNER_JEFE_INMEDIATO = 'jefe_inmediato';
    public const SIGNER_TRABAJADOR = 'trabajador';
    public const SIGNER_TYPES = [
        self::SIGNER_CONTADOR,
        self::SIGNER_COORDINADOR_ADMIN,
        self::SIGNER_JEFE_INMEDIATO,
        self::SIGNER_TRABAJADOR,
    ];
    public const SIGNER_LABELS = [
        self::SIGNER_CONTADOR => 'Contador',
        self::SIGNER_COORDINADOR_ADMIN => 'Coordinador Administrativo',
        self::SIGNER_JEFE_INMEDIATO => 'Jefe Inmediato',
        self::SIGNER_TRABAJADOR => 'Trabajador',
    ];

    // Backward compat — old statuses mapping
    public const STATUS_PENDING = self::STATUS_REGISTRO;
    public const STATUS_APPROVED = self::STATUS_PAGADA;
    public const STATUS_REJECTED = self::STATUS_RECHAZADA;
    public const STATUSES = self::ALL_STATUSES;
}

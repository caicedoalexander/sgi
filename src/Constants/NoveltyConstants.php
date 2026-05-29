<?php
declare(strict_types=1);

namespace App\Constants;

use App\Constants\Domain\Novelty\PipelineStatus;

final class NoveltyConstants
{
    // Pipeline statuses (ordered) — fuente única en App\Constants\Domain\Novelty\PipelineStatus.
    public const STATUS_REGISTRO = PipelineStatus::REGISTRO->value;
    public const STATUS_APROBACION = PipelineStatus::APROBACION->value;
    public const STATUS_RRHH = PipelineStatus::RRHH->value;
    public const STATUS_CONTABILIDAD = PipelineStatus::CONTABILIDAD->value;
    public const STATUS_REVISION_FIRMAS = PipelineStatus::REVISION_FIRMAS->value;
    public const STATUS_GDP = PipelineStatus::GDP->value;
    public const STATUS_TESORERIA = PipelineStatus::TESORERIA->value;
    public const STATUS_AUTORIZACION_PAGO = PipelineStatus::AUTORIZACION_PAGO->value;
    public const STATUS_VERIFICACION_PAGO = PipelineStatus::VERIFICACION_PAGO->value;
    public const STATUS_PAGADA = PipelineStatus::PAGADA->value;
    public const STATUS_RECHAZADA = PipelineStatus::RECHAZADA->value;

    public const PIPELINE_STATUSES = [
        self::STATUS_APROBACION,
        self::STATUS_RRHH,
        self::STATUS_CONTABILIDAD,
        self::STATUS_REVISION_FIRMAS,
        self::STATUS_GDP,
        self::STATUS_TESORERIA,
        self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_VERIFICACION_PAGO,
        self::STATUS_PAGADA,
    ];

    // Novelty individual pipeline (before liquidation doc takes over)
    public const NOVELTY_STATUSES = [
        self::STATUS_APROBACION,
        self::STATUS_RRHH,
        self::STATUS_CONTABILIDAD,
    ];

    /**
     * Estados considerados "activos" para conteos del sidebar, filtros del listado
     * de empleados y estadísticas de novedades.
     *
     * Excluye intencionalmente:
     *  - STATUS_REGISTRO, STATUS_APROBACION: la novedad aún no fue procesada por RRHH.
     *  - STATUS_AUTORIZACION_PAGO: estado transitorio de autorización del Contador;
     *    se considera "en flujo de pago", no "activa" en el sentido operativo.
     *  - STATUS_VERIFICACION_PAGO: estado transitorio de confirmación por Tesorería;
     *    también "en flujo de pago", se mantiene fuera de ACTIVE_STATUSES por coherencia.
     *  - STATUS_RECHAZADA: terminal, no cuenta como activa.
     *
     * Si la semántica de "activa" cambia (p.ej. incluir AUTORIZACION_PAGO),
     * revisar los 3 call-sites: EmployeeNoveltiesController::index,
     * SidebarCounterService, EmployeeStatisticsService.
     */
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
        self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_VERIFICACION_PAGO,
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
        self::STATUS_AUTORIZACION_PAGO => 'Autorización de pago',
        self::STATUS_VERIFICACION_PAGO => 'Verificación de pago',
        self::STATUS_PAGADA => 'Pagada',
        self::STATUS_RECHAZADA => 'Rechazada',
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
    public const DOC_TYPE_LIQUIDATION = 'liquidation_document';

    // pipeline_status persistido en novelty_documents para el doc de liquidación.
    // Valor literal con punto y espacio — es dato persistido, NO un estado del
    // pipeline de novedades (no delega a enum).
    public const DOC_STATUS_LIQUIDACION = 'd. liquidacion';

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

}

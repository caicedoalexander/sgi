<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * Catálogo declarativo de los pasos del pipeline configurables vía
 * `pipeline_permissions`. Sirve como única fuente de verdad para:
 *  - validar input del POST en RolesController::edit (defensa contra POST manipulado),
 *  - iterar la matriz de permisos en la UI,
 *  - traducir slugs a etiquetas en español.
 */
final class PipelineStepConstants
{
    public const PIPELINE_INVOICES = 'invoices';
    public const PIPELINE_NOVELTIES = 'novelties';
    public const PIPELINE_PAYMENT_SCHEDULINGS = 'payment_schedulings';
    public const PIPELINE_REFUNDS = 'refunds';
    public const PIPELINE_PETTY_CASH = 'petty_cash';
    public const PIPELINE_LEGALIZATIONS = 'legalizations';
    public const PIPELINE_LIQUIDATION_DOCS = 'liquidation_docs';

    public const PIPELINE_LABELS = [
        self::PIPELINE_INVOICES => 'Facturas',
        self::PIPELINE_NOVELTIES => 'Novedades',
        self::PIPELINE_PAYMENT_SCHEDULINGS => 'Programación de pagos',
        self::PIPELINE_REFUNDS => 'Reintegros',
        self::PIPELINE_PETTY_CASH => 'Caja menor',
        self::PIPELINE_LEGALIZATIONS => 'Legalizaciones',
        self::PIPELINE_LIQUIDATION_DOCS => 'Documentos de liquidación',
    ];

    /**
     * Mapa pipeline → módulo CRUD (`permissions.module` / AuthorizationService::MODULES)
     * cuya bandeja muestra los registros de ese pipeline.
     *
     * Fuente única del vínculo entre las dos tablas de permisos: un rol que
     * puede operar pasos de un pipeline necesita `can_view` del módulo mapeado,
     * de lo contrario el link del sidebar no se renderiza y su bandeja queda
     * invisible. Nótese que el slug del pipeline NO siempre coincide con el del
     * módulo (legalizations→advances, novelties→employee_novelties,
     * liquidation_docs→novelty_liquidation_docs) — ver "Split de naming Advance"
     * y la convención de slugs en CLAUDE.md.
     *
     * @var array<string, string>
     */
    public const MODULE_BY_PIPELINE = [
        self::PIPELINE_INVOICES => 'invoices',
        self::PIPELINE_NOVELTIES => 'employee_novelties',
        self::PIPELINE_PAYMENT_SCHEDULINGS => 'payment_schedulings',
        self::PIPELINE_REFUNDS => 'refunds',
        self::PIPELINE_PETTY_CASH => 'petty_cash',
        self::PIPELINE_LEGALIZATIONS => 'advances',
        self::PIPELINE_LIQUIDATION_DOCS => 'novelty_liquidation_docs',
    ];

    /**
     * Pasos válidos por pipeline. La lista debe coincidir con los estados que
     * los services de cada dominio usan para autorización (no necesariamente
     * con todos los estados del pipeline — se excluyen estados terminales sin
     * autorización configurable, como 'pagada' en facturas).
     */
    public const STEPS_BY_PIPELINE = [
        self::PIPELINE_INVOICES => [
            InvoiceConstants::STATUS_APROBACION,
            InvoiceConstants::STATUS_CONTABILIDAD,
            InvoiceConstants::STATUS_TESORERIA,
            InvoiceConstants::STATUS_AUTORIZACION_PAGO,
            InvoiceConstants::STATUS_VERIFICACION_PAGO,
        ],
        self::PIPELINE_NOVELTIES => [
            NoveltyConstants::STATUS_APROBACION,
            NoveltyConstants::STATUS_RRHH,
            NoveltyConstants::STATUS_CONTABILIDAD,
            NoveltyConstants::STATUS_REVISION_FIRMAS,
            NoveltyConstants::STATUS_GDP,
            NoveltyConstants::STATUS_TESORERIA,
            NoveltyConstants::STATUS_AUTORIZACION_PAGO,
            NoveltyConstants::STATUS_VERIFICACION_PAGO,
        ],
        self::PIPELINE_PAYMENT_SCHEDULINGS => [
            PaymentSchedulingConstants::STATUS_BORRADOR,
            PaymentSchedulingConstants::STATUS_TESORERIA,
            PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO,
            PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO,
        ],
        self::PIPELINE_REFUNDS => [
            RefundConstants::STATUS_AGRUPACION,
            RefundConstants::STATUS_APROBACION,
            RefundConstants::STATUS_CONTABILIDAD,
            RefundConstants::STATUS_TESORERIA,
            RefundConstants::STATUS_AUTORIZACION_PAGO,
            RefundConstants::STATUS_VERIFICACION_PAGO,
        ],
        self::PIPELINE_PETTY_CASH => [
            PettyCashConstants::STATUS_AGRUPACION,
            PettyCashConstants::STATUS_CONTABILIDAD,
            PettyCashConstants::STATUS_TESORERIA,
            PettyCashConstants::STATUS_AUTORIZACION_PAGO,
            PettyCashConstants::STATUS_VERIFICACION_PAGO,
        ],
        self::PIPELINE_LEGALIZATIONS => [
            AdvanceConstants::STATUS_VALIDACION,
            AdvanceConstants::STATUS_APROBACION,
            AdvanceConstants::STATUS_REVISION_FIRMAS,
            AdvanceConstants::STATUS_CONTABILIDAD,
            AdvanceConstants::STATUS_TESORERIA,
            AdvanceConstants::STATUS_AUTORIZACION_PAGO,
            AdvanceConstants::STATUS_VERIFICACION_PAGO,
        ],
        self::PIPELINE_LIQUIDATION_DOCS => [
            NoveltyConstants::STATUS_CONTABILIDAD,
            NoveltyConstants::STATUS_REVISION_FIRMAS,
            NoveltyConstants::STATUS_GDP,
            NoveltyConstants::STATUS_TESORERIA,
            NoveltyConstants::STATUS_AUTORIZACION_PAGO,
            NoveltyConstants::STATUS_VERIFICACION_PAGO,
        ],
    ];

    /**
     * Etiquetas en español para mostrar en la UI de configuración.
     *
     * Las entradas referencian `STATUS_LABELS` de cada `*Constants` cuando el
     * label coincide; las 2 excepciones documentadas (Revisión y Firmas en
     * pipelines de novedades) se conservan como literal por divergencia
     * intencional con `NoveltyConstants::STATUS_LABELS`.
     */
    public const STEP_LABELS = [
        self::PIPELINE_INVOICES => [
            InvoiceConstants::STATUS_APROBACION => InvoiceConstants::STATUS_LABELS[InvoiceConstants::STATUS_APROBACION],
            InvoiceConstants::STATUS_CONTABILIDAD => InvoiceConstants::STATUS_LABELS[InvoiceConstants::STATUS_CONTABILIDAD],
            InvoiceConstants::STATUS_TESORERIA => InvoiceConstants::STATUS_LABELS[InvoiceConstants::STATUS_TESORERIA],
            InvoiceConstants::STATUS_AUTORIZACION_PAGO => InvoiceConstants::STATUS_LABELS[InvoiceConstants::STATUS_AUTORIZACION_PAGO],
            InvoiceConstants::STATUS_VERIFICACION_PAGO => InvoiceConstants::STATUS_LABELS[InvoiceConstants::STATUS_VERIFICACION_PAGO],
        ],
        self::PIPELINE_NOVELTIES => [
            NoveltyConstants::STATUS_APROBACION => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_APROBACION],
            NoveltyConstants::STATUS_RRHH => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_RRHH],
            NoveltyConstants::STATUS_CONTABILIDAD => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_CONTABILIDAD],
            // Divergencia intencional: NoveltyConstants dice 'Revisión y Firmas de documentos',
            // aquí se usa el label corto por espacio en la UI de matriz de permisos.
            NoveltyConstants::STATUS_REVISION_FIRMAS => 'Revisión y Firmas',
            NoveltyConstants::STATUS_GDP => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_GDP],
            NoveltyConstants::STATUS_TESORERIA => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_TESORERIA],
            NoveltyConstants::STATUS_AUTORIZACION_PAGO => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_AUTORIZACION_PAGO],
            NoveltyConstants::STATUS_VERIFICACION_PAGO => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_VERIFICACION_PAGO],
        ],
        self::PIPELINE_PAYMENT_SCHEDULINGS => [
            PaymentSchedulingConstants::STATUS_BORRADOR => PaymentSchedulingConstants::STATUS_LABELS[PaymentSchedulingConstants::STATUS_BORRADOR],
            PaymentSchedulingConstants::STATUS_TESORERIA => PaymentSchedulingConstants::STATUS_LABELS[PaymentSchedulingConstants::STATUS_TESORERIA],
            PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO => PaymentSchedulingConstants::STATUS_LABELS[PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO],
            PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO => PaymentSchedulingConstants::STATUS_LABELS[PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO],
        ],
        self::PIPELINE_REFUNDS => [
            RefundConstants::STATUS_AGRUPACION => RefundConstants::STATUS_LABELS[RefundConstants::STATUS_AGRUPACION],
            RefundConstants::STATUS_APROBACION => RefundConstants::STATUS_LABELS[RefundConstants::STATUS_APROBACION],
            RefundConstants::STATUS_CONTABILIDAD => RefundConstants::STATUS_LABELS[RefundConstants::STATUS_CONTABILIDAD],
            RefundConstants::STATUS_TESORERIA => RefundConstants::STATUS_LABELS[RefundConstants::STATUS_TESORERIA],
            RefundConstants::STATUS_AUTORIZACION_PAGO => RefundConstants::STATUS_LABELS[RefundConstants::STATUS_AUTORIZACION_PAGO],
            RefundConstants::STATUS_VERIFICACION_PAGO => RefundConstants::STATUS_LABELS[RefundConstants::STATUS_VERIFICACION_PAGO],
        ],
        self::PIPELINE_PETTY_CASH => [
            PettyCashConstants::STATUS_AGRUPACION => PettyCashConstants::STATUS_LABELS[PettyCashConstants::STATUS_AGRUPACION],
            PettyCashConstants::STATUS_CONTABILIDAD => PettyCashConstants::STATUS_LABELS[PettyCashConstants::STATUS_CONTABILIDAD],
            PettyCashConstants::STATUS_TESORERIA => PettyCashConstants::STATUS_LABELS[PettyCashConstants::STATUS_TESORERIA],
            PettyCashConstants::STATUS_AUTORIZACION_PAGO => PettyCashConstants::STATUS_LABELS[PettyCashConstants::STATUS_AUTORIZACION_PAGO],
            PettyCashConstants::STATUS_VERIFICACION_PAGO => PettyCashConstants::STATUS_LABELS[PettyCashConstants::STATUS_VERIFICACION_PAGO],
        ],
        self::PIPELINE_LEGALIZATIONS => [
            AdvanceConstants::STATUS_VALIDACION => AdvanceConstants::STATUS_LABELS[AdvanceConstants::STATUS_VALIDACION],
            AdvanceConstants::STATUS_APROBACION => AdvanceConstants::STATUS_LABELS[AdvanceConstants::STATUS_APROBACION],
            AdvanceConstants::STATUS_REVISION_FIRMAS => AdvanceConstants::STATUS_LABELS[AdvanceConstants::STATUS_REVISION_FIRMAS],
            AdvanceConstants::STATUS_CONTABILIDAD => AdvanceConstants::STATUS_LABELS[AdvanceConstants::STATUS_CONTABILIDAD],
            AdvanceConstants::STATUS_TESORERIA => AdvanceConstants::STATUS_LABELS[AdvanceConstants::STATUS_TESORERIA],
            AdvanceConstants::STATUS_AUTORIZACION_PAGO => AdvanceConstants::STATUS_LABELS[AdvanceConstants::STATUS_AUTORIZACION_PAGO],
            AdvanceConstants::STATUS_VERIFICACION_PAGO => AdvanceConstants::STATUS_LABELS[AdvanceConstants::STATUS_VERIFICACION_PAGO],
        ],
        self::PIPELINE_LIQUIDATION_DOCS => [
            NoveltyConstants::STATUS_CONTABILIDAD => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_CONTABILIDAD],
            // Divergencia intencional (idem PIPELINE_NOVELTIES).
            NoveltyConstants::STATUS_REVISION_FIRMAS => 'Revisión y Firmas',
            NoveltyConstants::STATUS_GDP => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_GDP],
            NoveltyConstants::STATUS_TESORERIA => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_TESORERIA],
            NoveltyConstants::STATUS_AUTORIZACION_PAGO => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_AUTORIZACION_PAGO],
            NoveltyConstants::STATUS_VERIFICACION_PAGO => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_VERIFICACION_PAGO],
        ],
    ];

    /**
     * @return bool true si el par (pipeline, step) está declarado.
     */
    public static function isValid(string $pipeline, string $step): bool
    {
        return in_array($step, self::STEPS_BY_PIPELINE[$pipeline] ?? [], true);
    }
}

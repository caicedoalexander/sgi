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

    public const PIPELINE_LABELS = [
        self::PIPELINE_INVOICES => 'Facturas',
        self::PIPELINE_NOVELTIES => 'Novedades',
        self::PIPELINE_PAYMENT_SCHEDULINGS => 'Programación de pagos',
        self::PIPELINE_REFUNDS => 'Reintegros',
        self::PIPELINE_PETTY_CASH => 'Caja menor',
        self::PIPELINE_LEGALIZATIONS => 'Legalizaciones',
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
        ],
        self::PIPELINE_NOVELTIES => [
            NoveltyConstants::STATUS_APROBACION,
            NoveltyConstants::STATUS_RRHH,
            NoveltyConstants::STATUS_CONTABILIDAD,
            NoveltyConstants::STATUS_REVISION_FIRMAS,
            NoveltyConstants::STATUS_GDP,
            NoveltyConstants::STATUS_TESORERIA,
            NoveltyConstants::STATUS_AUT_PAGO,
        ],
        self::PIPELINE_PAYMENT_SCHEDULINGS => [
            PaymentSchedulingConstants::STATUS_BORRADOR,
            PaymentSchedulingConstants::STATUS_TESORERIA,
            PaymentSchedulingConstants::STATUS_AUT_PAGO,
        ],
        self::PIPELINE_REFUNDS => [
            RefundConstants::STATUS_AGRUPACION,
            RefundConstants::STATUS_CONTABILIDAD,
            RefundConstants::STATUS_TESORERIA,
            RefundConstants::STATUS_AUT_PAGO,
        ],
        self::PIPELINE_PETTY_CASH => [
            PettyCashConstants::STATUS_AGRUPACION,
            PettyCashConstants::STATUS_CONTABILIDAD,
            PettyCashConstants::STATUS_TESORERIA,
            PettyCashConstants::STATUS_AUT_PAGO,
        ],
        self::PIPELINE_LEGALIZATIONS => [
            AdvanceConstants::STATUS_VALIDACION,
            AdvanceConstants::STATUS_REVISION_FIRMAS,
            AdvanceConstants::STATUS_CONTABILIDAD,
            AdvanceConstants::STATUS_TESORERIA,
            AdvanceConstants::STATUS_AUTORIZACION_PAGO,
        ],
    ];

    /**
     * Etiquetas en español para mostrar en la UI de configuración.
     */
    public const STEP_LABELS = [
        self::PIPELINE_INVOICES => [
            InvoiceConstants::STATUS_APROBACION => 'Aprobación',
            InvoiceConstants::STATUS_CONTABILIDAD => 'Contabilidad',
            InvoiceConstants::STATUS_TESORERIA => 'Tesorería',
            InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'Autorización de pago',
        ],
        self::PIPELINE_NOVELTIES => [
            NoveltyConstants::STATUS_APROBACION => 'Aprobación',
            NoveltyConstants::STATUS_RRHH => 'RRHH',
            NoveltyConstants::STATUS_CONTABILIDAD => 'Contabilidad',
            NoveltyConstants::STATUS_REVISION_FIRMAS => 'Revisión y Firmas',
            NoveltyConstants::STATUS_GDP => 'GDP',
            NoveltyConstants::STATUS_TESORERIA => 'Tesorería',
            NoveltyConstants::STATUS_AUT_PAGO => 'Autorización de pago',
        ],
        self::PIPELINE_PAYMENT_SCHEDULINGS => [
            PaymentSchedulingConstants::STATUS_BORRADOR => 'Borrador',
            PaymentSchedulingConstants::STATUS_TESORERIA => 'Tesorería',
            PaymentSchedulingConstants::STATUS_AUT_PAGO => 'Autorización de pago',
        ],
        self::PIPELINE_REFUNDS => [
            RefundConstants::STATUS_AGRUPACION => 'Agrupación',
            RefundConstants::STATUS_CONTABILIDAD => 'Contabilidad',
            RefundConstants::STATUS_TESORERIA => 'Tesorería',
            RefundConstants::STATUS_AUT_PAGO => 'Autorización de pago',
        ],
        self::PIPELINE_PETTY_CASH => [
            PettyCashConstants::STATUS_AGRUPACION => 'Agrupación',
            PettyCashConstants::STATUS_CONTABILIDAD => 'Contabilidad',
            PettyCashConstants::STATUS_TESORERIA => 'Tesorería',
            PettyCashConstants::STATUS_AUT_PAGO => 'Autorización de pago',
        ],
        self::PIPELINE_LEGALIZATIONS => [
            AdvanceConstants::STATUS_VALIDACION => 'Validación',
            AdvanceConstants::STATUS_REVISION_FIRMAS => 'Revisión y Firmas',
            AdvanceConstants::STATUS_CONTABILIDAD => 'Contabilidad',
            AdvanceConstants::STATUS_TESORERIA => 'Tesorería',
            AdvanceConstants::STATUS_AUTORIZACION_PAGO => 'Autorización de pago',
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

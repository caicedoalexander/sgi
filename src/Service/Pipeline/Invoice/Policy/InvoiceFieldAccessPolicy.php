<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\Policy;

use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Service\Pipeline\PipelineFieldPolicy;

/**
 * Calcula qué campos puede editar un usuario en una factura y qué secciones
 * del formulario debe ver, dado su rol y el estado actual del pipeline.
 *
 * Audit PA-008 — antes implementaba todo inline; ahora extiende
 * `PipelineFieldPolicy` y aporta solo el mapeo específico de Invoice. El shape
 * de secciones pasó de `step => 'section'` a `step => ['section', ...]` para
 * unificar con Novelty/PettyCash/Refund.
 */
class InvoiceFieldAccessPolicy extends PipelineFieldPolicy
{
    /**
     * Campos editables por paso del pipeline (sin acoplamiento a rol).
     */
    private const FIELDS_BY_STEP = [
        InvoiceConstants::STATUS_APROBACION => [
            'invoice_number', 'issue_date', 'due_date',
            'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
            'detail', 'amount', 'expense_type_id', 'cost_center_id',
            'confirmed_by',
            'dian_validation',
        ],
        InvoiceConstants::STATUS_CONTABILIDAD => [
            'accrued', 'accrual_date', 'ready_for_payment',
        ],
        InvoiceConstants::STATUS_TESORERIA => [],
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => [],
        // Verificación de pago es read-only: la transición a Pagada se hace
        // exclusivamente vía InvoicePaymentService::confirmPayment.
        InvoiceConstants::STATUS_VERIFICACION_PAGO => [],
    ];

    /**
     * Secciones del formulario asociadas a cada paso (shape unificado con
     * Novelty/PettyCash/Refund — array, no string).
     */
    private const SECTIONS_BY_STEP = [
        InvoiceConstants::STATUS_APROBACION => ['revision'],
        InvoiceConstants::STATUS_CONTABILIDAD => ['accounting'],
        InvoiceConstants::STATUS_TESORERIA => ['treasury'],
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => ['payment_authorization'],
        // Verificación de pago reusa la sección de autorización (read-only),
        // donde aparece el botón "Pasar a Pagada".
        InvoiceConstants::STATUS_VERIFICACION_PAGO => ['payment_authorization'],
    ];

    /**
     * @return array<string, array<int, string>>
     */
    protected static function fieldsByStep(): array
    {
        return self::FIELDS_BY_STEP;
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected static function sectionsByStep(): array
    {
        return self::SECTIONS_BY_STEP;
    }

    /**
     * @return string
     */
    protected static function pipelineKey(): string
    {
        return PipelineStepConstants::PIPELINE_INVOICES;
    }

    /**
     * @return array<int, string>
     */
    protected static function alwaysVisibleSections(): array
    {
        return ['ledger'];
    }
}

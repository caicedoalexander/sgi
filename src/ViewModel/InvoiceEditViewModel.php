<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\View\Presentation\InvoicePresentation;
use App\ViewModel\Support\PaymentOptions;
use App\ViewModel\Support\SubmitButton;

/**
 * Datos inmutables de vista para InvoicesController::edit().
 * El controller construye este objeto; la vista accede via get_object_vars().
 */
final class InvoiceEditViewModel
{
    // ── Propiedades derivadas (calculadas en el constructor) ────────────
    /** @var array<string,string> */
    public readonly array $documentTypes;
    /** @var array<string,string> */
    public readonly array $approvalOptions;
    /** @var array<string,string> */
    public readonly array $dianOptions;
    /** @var array<string,string> */
    public readonly array $readyForPaymentOptions;
    /** @var array<string,string> */
    public readonly array $paymentStatusOptions;

    public readonly bool $isAdvance;
    public readonly string $pageTitle;

    /** @var array{0:string,1:string} Pareja [label, class] para el currentStatus. */
    public readonly array $currentStatusBadge;

    /** @var array<string,string[]> Sección → campos editables que la habilitan. */
    public readonly array $sectionFieldMap;
    /** @var string[] */
    public readonly array $editableSectionKeys;
    /** @var string[] */
    public readonly array $readOnlySectionKeys;
    /** @var string[] Render order: non-collapsible editable → collapsible editable → read-only. */
    public readonly array $renderOrder;

    public readonly string $submitButtonLabel;
    public readonly string $submitButtonClass;

    public function __construct(
        // Entidad principal
        public readonly Invoice $invoice,
        public readonly string $currentStatus,
        public readonly string $roleName,

        // Permisos y estado del pipeline
        public readonly array $editableFields,
        public readonly bool $canAdvance,
        public readonly bool $canDeleteDocuments,
        public readonly bool $canRegress,
        public readonly bool $canConfirmPayment,
        public readonly bool $canRegisterPayment,
        public readonly bool $canAuthorizePayment,
        public readonly bool $isRejected,
        public readonly bool $isApproved,

        // Secciones visibles
        public readonly array $visibleSections,
        public readonly array $collapsibleSections,

        // Avance / retroceso
        public readonly array $advanceErrors,
        public readonly ?string $nextStatus,
        public readonly ?string $previousStatus,
        public readonly ?string $regressLockMessage,

        // Pipeline visual
        public readonly array $pipelineStatuses,
        public readonly array $pipelineLabels,

        // Multi-aprobador
        public readonly array $currentApprovals,
        public readonly bool $hasPendingApprovals,
        public readonly bool $canSendLinks,
        public readonly bool $canModifyApprovers,

        // Pagos
        public readonly float $paymentsTotal,

        // Dropdowns del formulario
        public readonly mixed $providers,
        public readonly mixed $operationCenters,
        public readonly mixed $expenseTypes,
        public readonly mixed $costCenters,
        public readonly mixed $approvers,
        public readonly mixed $employees,
        public readonly mixed $bankingEntities,

        // Email logs
        public readonly array $emailLogs,
    ) {
        // ── Tipo de documento ────────────────────────────────────────────
        $this->isAdvance = ($invoice->document_type ?? null) === InvoiceConstants::DOCTYPE_ANTICIPO;

        $idLabel = $invoice->invoice_number ?? ('#' . $invoice->id);
        $this->pageTitle = $this->isAdvance
            ? ('Editar Anticipo #' . $invoice->id)
            : ('Editar Factura ' . $idLabel);

        // ── Opciones de dropdowns derivadas de constantes ────────────────
        $this->documentTypes   = array_combine(InvoiceConstants::DOCUMENT_TYPES, InvoiceConstants::DOCUMENT_TYPES);
        $this->approvalOptions = array_combine(InvoiceConstants::APPROVAL_STATUSES, InvoiceConstants::APPROVAL_STATUSES);
        $this->dianOptions     = array_combine(InvoiceConstants::DIAN_STATUSES, InvoiceConstants::DIAN_STATUSES);

        $this->readyForPaymentOptions = PaymentOptions::readyForPayment();
        $this->paymentStatusOptions   = PaymentOptions::paymentStatus();

        // ── Badge del estado actual del pipeline ─────────────────────────
        $this->currentStatusBadge = [
            $pipelineLabels[$currentStatus] ?? 'Desconocido',
            InvoicePresentation::STATUS_BADGES[$currentStatus] ?? 'bg-dark',
        ];

        // ── Render order de las secciones del formulario ─────────────────
        // Las secciones funcionales (treasury, payment_authorization) tienen su
        // propia lógica de permisos y no aparecen en este mapa — nunca caen en
        // read-only.
        $this->sectionFieldMap = [
            'general'        => ['invoice_number', 'document_type', 'purchase_order', 'provider_id'],
            'dates'          => ['issue_date', 'due_date'],
            'classification' => ['operation_center_id', 'expense_type_id', 'cost_center_id', 'amount', 'detail'],
            'revision'       => ['approver_id', 'dian_validation'],
            'accounting'     => ['accrued', 'ready_for_payment'],
        ];

        $functionalSections = ['treasury', 'payment_authorization'];

        $editable = [];
        $readOnly = [];
        foreach ($visibleSections as $s) {
            $isFunctional = in_array($s, $functionalSections, true);
            $hasEditableField = !empty(array_intersect($this->sectionFieldMap[$s] ?? [], $editableFields));
            if ($isFunctional || $hasEditableField) {
                $editable[] = $s;
            } else {
                $readOnly[] = $s;
            }
        }
        $this->editableSectionKeys = $editable;
        $this->readOnlySectionKeys = $readOnly;

        $nonCollapsibleEditable = array_values(array_filter(
            $editable,
            fn($s) => !in_array($s, $collapsibleSections, true),
        ));
        $collapsibleEditable = array_values(array_filter(
            $editable,
            fn($s) => in_array($s, $collapsibleSections, true),
        ));
        $this->renderOrder = array_merge(
            $nonCollapsibleEditable,
            $collapsibleEditable,
            $readOnly,
        );

        // ── Botón de submit ──────────────────────────────────────────────
        $this->submitButtonClass = 'btn btn-primary';
        $this->submitButtonLabel = SubmitButton::decide(
            canAdvance: !$isRejected && $canAdvance,
            advanceErrors: $advanceErrors,
            nextStatusLabel: $nextStatus !== null ? ($pipelineLabels[$nextStatus] ?? $nextStatus) : null,
        );
    }

    public function isReadOnlySection(string $section): bool
    {
        return in_array($section, $this->readOnlySectionKeys, true);
    }

    public function isCollapsibleSection(string $section): bool
    {
        return in_array($section, $this->collapsibleSections, true);
    }

    public function canEditField(string $field): bool
    {
        return in_array($field, $this->editableFields, true);
    }
}

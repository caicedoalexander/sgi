<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\View\Presentation\InvoicePresentation;
use App\ViewModel\Invoice\InvoiceApprovalState;
use App\ViewModel\Invoice\InvoiceEditPermissions;
use App\ViewModel\Invoice\InvoiceFormDropdowns;
use App\ViewModel\Support\PaymentOptions;
use App\ViewModel\Support\SubmitButton;

/**
 * Datos inmutables de vista para InvoicesController::edit().
 * El controller construye este objeto; la vista accede via get_object_vars().
 */
final readonly class InvoiceEditViewModel implements EditViewModelInterface
{
    // ── Propiedades derivadas (calculadas en el constructor) ────────────
    /**
     * @var array<string,string>
     */
    public array $documentTypes;
    /**
     * @var array<string,string>
     */
    public array $approvalOptions;
    /**
     * @var array<string,string>
     */
    public array $dianOptions;
    /**
     * @var array<string,string>
     */
    public array $readyForPaymentOptions;
    /**
     * @var array<string,string>
     */
    public array $paymentStatusOptions;

    public bool $isAdvance;
    public string $pageTitle;

    /**
     * @var array{0:string,1:string} Pareja [label, class] para el currentStatus.
     */
    public array $currentStatusBadge;

    /**
     * @var array<string,string[]> Sección → campos editables que la habilitan.
     */
    public array $sectionFieldMap;
    /**
     * @var array<string>
     */
    public array $editableSectionKeys;
    /**
     * @var array<string>
     */
    public array $readOnlySectionKeys;
    /**
     * @var array<string>  Render order: non-collapsible editable → collapsible editable → read-only.
     */
    public array $renderOrder;

    /**
     * HTML pre-renderizado (ícono + texto). El template lo imprime raw.
     */
    public string $submitButtonHtml;
    public string $submitButtonClass;

    // ── Propiedades desempacadas de DTOs (preservan API del template) ───
    public bool $canAdvance;
    public bool $canDeleteDocuments;
    public bool $canRegress;
    public bool $canConfirmPayment;
    public bool $canRegisterPayment;
    public bool $canAuthorizePayment;
    public bool $isRejected;
    public bool $isApproved;

    /**
     * @var array<int, mixed>
     */
    public array $currentApprovals;
    public bool $hasPendingApprovals;
    public bool $canSendLinks;
    public bool $canModifyApprovers;

    public mixed $providers;
    public mixed $operationCenters;
    public mixed $expenseTypes;
    public mixed $costCenters;
    public mixed $approvers;
    public mixed $employees;
    public mixed $bankingEntities;

    /**
     * @param \App\Model\Entity\Invoice $invoice Factura a editar.
     * @param string $currentStatus Estado actual del pipeline.
     * @param string $roleName Rol del usuario actual.
     * @param array $editableFields Campos editables en el paso actual.
     * @param array $visibleSections Secciones visibles del formulario.
     * @param array $advanceErrors Errores que bloquean el avance.
     * @param string|null $nextStatus Siguiente estado del pipeline, o null.
     * @param string|null $previousStatus Estado anterior del pipeline, o null.
     * @param string|null $regressLockMessage Motivo por el que no se puede regresar, o null.
     * @param array $pipelineStatuses Estados del pipeline visual.
     * @param array $pipelineLabels Etiquetas ES por estado del pipeline.
     * @param float $paymentsTotal Total pagado acumulado.
     * @param array $emailLogs Registro de correos enviados.
     * @param \App\ViewModel\Invoice\InvoiceEditPermissions $permissions Bundle de capacidades rol×estado.
     * @param \App\ViewModel\Invoice\InvoiceApprovalState $approvalState Estado de aprobación de área.
     * @param \App\ViewModel\Invoice\InvoiceFormDropdowns $dropdowns Listados para los <select> del form.
     */
    public function __construct(
        // Entidad principal
        public Invoice $invoice,
        public string $currentStatus,
        public string $roleName,
        // Campos editables y secciones
        public array $editableFields,
        public array $visibleSections,
        // Avance / retroceso
        public array $advanceErrors,
        public ?string $nextStatus,
        public ?string $previousStatus,
        public ?string $regressLockMessage,
        // Pipeline visual
        public array $pipelineStatuses,
        public array $pipelineLabels,
        // Pagos
        public float $paymentsTotal,
        // Email logs
        public array $emailLogs,
        // Bundles de DTOs
        InvoiceEditPermissions $permissions,
        InvoiceApprovalState $approvalState,
        InvoiceFormDropdowns $dropdowns,
    ) {
        // ── Desempaque de DTOs a propiedades planas ──────────────────────
        $this->canAdvance          = $permissions->canAdvance;
        $this->canDeleteDocuments  = $permissions->canDeleteDocuments;
        $this->canRegress          = $permissions->canRegress;
        $this->canConfirmPayment   = $permissions->canConfirmPayment;
        $this->canRegisterPayment  = $permissions->canRegisterPayment;
        $this->canAuthorizePayment = $permissions->canAuthorizePayment;
        $this->isRejected          = $permissions->isRejected;
        $this->isApproved          = $permissions->isApproved;

        $this->currentApprovals    = $approvalState->currentApprovals;
        $this->hasPendingApprovals = $approvalState->hasPendingApprovals;
        $this->canSendLinks        = $approvalState->canSendLinks;
        $this->canModifyApprovers  = $approvalState->canModifyApprovers;

        $this->providers        = $dropdowns->providers;
        $this->operationCenters = $dropdowns->operationCenters;
        $this->expenseTypes     = $dropdowns->expenseTypes;
        $this->costCenters      = $dropdowns->costCenters;
        $this->approvers        = $dropdowns->approvers;
        $this->employees        = $dropdowns->employees;
        $this->bankingEntities  = $dropdowns->bankingEntities;

        // ── Tipo de documento ────────────────────────────────────────────
        $this->isAdvance = ($invoice->document_type ?? null) === InvoiceConstants::DOCTYPE_ANTICIPO;

        $idLabel = $invoice->invoice_number ?? '#' . $invoice->id;
        $this->pageTitle = $this->isAdvance
            ? 'Editar Anticipo #' . $invoice->id
            : 'Editar Factura ' . $idLabel;

        // ── Opciones de dropdowns derivadas de constantes ────────────────
        $this->documentTypes   = array_combine(InvoiceConstants::DOCUMENT_TYPES, InvoiceConstants::DOCUMENT_TYPES);
        $this->approvalOptions = array_combine(InvoiceConstants::APPROVAL_STATUSES, InvoiceConstants::APPROVAL_STATUSES);
        $this->dianOptions     = array_combine(InvoiceConstants::DIAN_STATUSES, InvoiceConstants::DIAN_STATUSES);

        $this->readyForPaymentOptions = PaymentOptions::readyForPayment();
        $this->paymentStatusOptions   = PaymentOptions::paymentStatus();

        // ── Badge del estado actual del pipeline ─────────────────────────
        $this->currentStatusBadge = [
            $pipelineLabels[$currentStatus] ?? 'Desconocido',
            InvoicePresentation::STATUS_BADGES[$currentStatus] ?? 'pill-muted',
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

        $this->renderOrder = array_merge($editable, $readOnly);

        // ── Botón de submit ──────────────────────────────────────────────
        $this->submitButtonClass = 'btn btn-primary';
        $this->submitButtonHtml = SubmitButton::decide(
            canAdvance: !$this->isRejected && $this->canAdvance,
            advanceErrors: $advanceErrors,
            nextStatusLabel: $nextStatus !== null ? ($pipelineLabels[$nextStatus] ?? $nextStatus) : null,
        );
    }

    /**
     * @param string $section Clave de la sección del formulario.
     * @return bool true si la sección se renderiza en modo solo lectura.
     */
    public function isReadOnlySection(string $section): bool
    {
        return in_array($section, $this->readOnlySectionKeys, true);
    }

    /**
     * @param string $field Nombre del campo del formulario.
     * @return bool true si el campo es editable en el paso actual.
     */
    public function canEditField(string $field): bool
    {
        return in_array($field, $this->editableFields, true);
    }
}

<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Model\Entity\Invoice;
use App\Model\Entity\InvoicePayment;
use App\Service\Dto\GroupReadinessReport;
use App\View\Presentation\AdvancePresentation;
use App\ViewModel\Support\LegalizationSummary;
use App\ViewModel\Support\PaymentOptions;

/**
 * Datos pre-calculados que el template `templates/Advances/legalization.php` necesita.
 *
 * Reemplaza el método privado `_buildLegalizationViewModel` que vivía en
 * `AdvancesController` (audit MI-005). Centraliza linked invoices, separación
 * de signature activa vs historial, totales, diff, banking entities y surplus
 * payment para mantener la action delgada.
 *
 * Los datos crudos (linkedInvoices, bankingEntities, surplusPayment) los carga
 * el controller y se inyectan vía constructor — alinea con el patrón uniforme
 * de los otros 5 edit ViewModels: el VM solo deriva, no consulta (audit CR-102).
 */
final readonly class AdvanceLegalizationViewModel implements EditViewModelInterface
{
    /**
     * Título de la página (header del browser y de la vista).
     */
    public string $pageTitle;

    /**
     * Slug del estado actual del pipeline de la legalización.
     */
    public string $currentStatus;

    /**
     * @var array{0:string,1:string} Pareja [label, clase-pill] del estado actual.
     */
    public array $currentStatusBadge;

    /**
     * True cuando la legalización está en el paso `aprobacion` — gatea el
     * panel de aprobadores en el template.
     */
    public bool $isAprobacion;

    /**
     * @param \App\Model\Entity\Invoice $invoice Anticipo invoice.
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización en curso.
     * @param string $roleName Rol del usuario actual (para ocultar/mostrar acciones).
     * @param iterable $linkedInvoices Facturas tipo Legalización vinculadas al anticipo.
     * @param array<int,string> $bankingEntities Lista [id => name].
     * @param \App\Model\Entity\InvoicePayment|null $surplusPayment Pago del sobrante (caso CASE_SOBRANTE) si existe.
     * @param bool $canRegisterRefund Pre-computado por el controller vía AdvanceLegalizationActionPolicy.
     * @param bool $canAuthorizeRefundPayment Pre-computado por el controller vía AdvanceLegalizationActionPolicy.
     * @param bool $canConfirmRefundPayment Pre-computado por el controller vía AdvanceLegalizationActionPolicy.
     * @param array<int, \App\Model\Entity\AdvanceLegalizationApproval> $approvals Aprobaciones activas del estado `aprobacion`.
     * @param array{total:int,approved:int,rejected:int,pending:int} $approvalSummary Resumen de aprobaciones.
     * @param bool $canManageApprovers Pre-computado por el controller vía AdvanceLegalizationActionPolicy::canConsolidateApproval.
     * @param array<int,string> $approvers Lista [id => nombre] de usuarios activos asignables como aprobadores.
     * @param bool $canOperateCurrentStep Pre-computado vía AdvanceLegalizationActionPolicy. Niega el banner de solo lectura.
     * @param bool $canLinkInvoices Pre-computado. Gatea `editable` de _linked_invoices y el modal de vinculación.
     * @param bool $canUploadRelationDocument Pre-computado. Gatea `editable` de _soportes.
     * @param bool $canMoveToAprobacion Pre-computado. Gatea la card de acción en `validacion`.
     * @param bool $canMarkSigned Pre-computado. Gatea el botón "Marcar como firmado".
     * @param bool $canReturnToAprobacion Pre-computado. Gatea "Devolver a Aprobación" y su modal.
     * @param bool $canMarkExact Pre-computado. Gatea la rama de caso exacto en `contabilidad`.
     * @param bool $canRegisterShortage Pre-computado. Gatea la rama de faltante.
     * @param bool $canRegisterSurplus Pre-computado. Gatea la rama de sobrante.
     * @param bool $canConfirmShortage Pre-computado. Gatea la card de Tesorería, caso faltante.
     * @param bool $canManageDocuments Pre-computado vía AdvanceLegalizationActionPolicy::canManageDocuments. Gatea el cajón general de Soportes.
     * @param \App\Service\Dto\GroupReadinessReport|null $childReadiness Requisitos DIAN/soporte pendientes de las hijas (checklist inline).
     * @param bool $canResolveDianChildren Pre-computado. Pinta el select DIAN inline en las hijas en `aprobacion`.
     * @param bool $canUploadChildSupport Pre-computado. Pinta el botón de subir soporte inline en las hijas.
     *
     * Dos de los 15 predicados del policy no se pasan, y no es un olvido:
     * - `canUnlinkInvoice`: predicado idéntico a `canLinkInvoices` (ambos exigen
     *   `validacion`), y el element usa un único `$editable` para ambos controles.
     * - `canReturnFromAprobacion`: su botón vive en `_approval_panel` y ya está
     *   gateado por `canManageApprovers` (= `canConsolidateApproval`). Ambos
     *   predicados son equivalentes: exigen `status === 'aprobacion'` y
     *   `canOperate($roleId, 'aprobacion')`.
     */
    public function __construct(
        public Invoice $invoice,
        public AdvanceLegalization $leg,
        public string $roleName,
        public iterable $linkedInvoices,
        public array $bankingEntities,
        public ?InvoicePayment $surplusPayment,
        public bool $canRegisterRefund = false,
        public bool $canAuthorizeRefundPayment = false,
        public bool $canConfirmRefundPayment = false,
        public array $approvals = [],
        public array $approvalSummary = ['total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0],
        public bool $canManageApprovers = false,
        public array $approvers = [],
        public bool $canOperateCurrentStep = false,
        public bool $canLinkInvoices = false,
        public bool $canUploadRelationDocument = false,
        public bool $canMoveToAprobacion = false,
        public bool $canMarkSigned = false,
        public bool $canReturnToAprobacion = false,
        public bool $canMarkExact = false,
        public bool $canRegisterShortage = false,
        public bool $canRegisterSurplus = false,
        public bool $canConfirmShortage = false,
        public bool $canManageDocuments = false,
        public ?GroupReadinessReport $childReadiness = null,
        public bool $canResolveDianChildren = false,
        public bool $canUploadChildSupport = false,
    ) {
        // Contrato EditViewModelInterface.
        $this->pageTitle = 'Legalización ' . ($invoice->invoice_number ?? '#' . $invoice->id);
        $this->currentStatus = (string)$leg->status;
        $this->currentStatusBadge = [
            AdvanceConstants::STATUS_LABELS[$leg->status] ?? 'Desconocido',
            AdvancePresentation::STATUS_BADGES[$leg->status] ?? 'pill-muted',
        ];
        $this->isAprobacion = ((string)$leg->status === AdvanceConstants::STATUS_APROBACION);
    }

    /**
     * Construye el set completo de variables para el template.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $summary = new LegalizationSummary(
            $this->leg,
            (float)$this->invoice->amount,
            $this->linkedInvoices,
        );

        // ── Derivaciones de presentación (antes inline en el template) ──
        $legPipelineLabels = AdvanceConstants::STATUS_LABELS;

        $beneficiary = $this->invoice->provider->name ?? ($this->invoice->employee->full_name ?? '—');
        $beneficiaryDoc = $this->invoice->provider->document_number
            ?? ($this->invoice->employee->document_number ?? null);
        $beneficiaryDocType = $this->invoice->provider_id
            ? ($this->invoice->provider->document_type ?? '')
            : ($this->invoice->employee_id ? ($this->invoice->employee->document_type ?? '') : '');
        $beneficiaryKind = $this->invoice->provider_id
            ? 'Proveedor'
            : ($this->invoice->employee_id ? 'Empleado' : '—');

        return [
            'invoice' => $this->invoice,
            'leg' => $this->leg,
            'linkedInvoices' => $this->linkedInvoices,
            'linkedTotal' => $summary->linkedTotal,
            'advanceTotal' => $summary->advanceTotal,
            'diff' => $summary->diff,
            'relationDocument' => $summary->relationDocument,
            'signatureHistory' => $summary->signatureHistory,
            'bankingEntities' => $this->bankingEntities,
            'surplusPayment' => $this->surplusPayment,
            'roleName' => $this->roleName,
            'canRegisterRefund' => $this->canRegisterRefund,
            'canAuthorizeRefundPayment' => $this->canAuthorizeRefundPayment,
            'canConfirmRefundPayment' => $this->canConfirmRefundPayment,
            'approvals' => $this->approvals,
            'approvalSummary' => $this->approvalSummary,
            'canManageApprovers' => $this->canManageApprovers,
            'isAprobacion' => $this->isAprobacion,
            'approvers' => $this->approvers,
            'canOperateCurrentStep' => $this->canOperateCurrentStep,
            'canLinkInvoices' => $this->canLinkInvoices,
            'canUploadRelationDocument' => $this->canUploadRelationDocument,
            'canMoveToAprobacion' => $this->canMoveToAprobacion,
            'canMarkSigned' => $this->canMarkSigned,
            'canReturnToAprobacion' => $this->canReturnToAprobacion,
            'canMarkExact' => $this->canMarkExact,
            'canRegisterShortage' => $this->canRegisterShortage,
            'canRegisterSurplus' => $this->canRegisterSurplus,
            'canConfirmShortage' => $this->canConfirmShortage,
            'canManageDocuments' => $this->canManageDocuments,
            'childReadiness' => $this->childReadiness,
            'canResolveDianChildren' => $this->canResolveDianChildren,
            'canUploadChildSupport' => $this->canUploadChildSupport,
            'documents' => $summary->documents,
            'totalDocs' => $summary->totalDocs,
            // Derivaciones de presentación.
            'pageTitle' => $this->pageTitle,
            'legPipelineLabels' => $legPipelineLabels,
            'beneficiary' => $beneficiary,
            'beneficiaryDoc' => $beneficiaryDoc,
            'beneficiaryDocType' => $beneficiaryDocType,
            'beneficiaryKind' => $beneficiaryKind,
            'ps' => $this->currentStatusBadge,
            'linkedCount' => $summary->linkedCount,
            'diffBadgeClass' => $summary->diffBadgeClass,
            'caseLabels' => LegalizationSummary::CASE_LABELS,
            'readyForPaymentOptions' => PaymentOptions::readyForPayment(),
            'showAccountingCard' => $this->leg->accrual_date !== null
                && (string)$this->leg->status !== AdvanceConstants::STATUS_CONTABILIDAD,
        ];
    }
}

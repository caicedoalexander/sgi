<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Model\Entity\Invoice;
use App\Model\Entity\InvoicePayment;
use App\View\Presentation\AdvancePresentation;

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
     * @param \App\Model\Entity\Invoice $invoice Anticipo invoice.
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización en curso.
     * @param string $roleName Rol del usuario actual (para ocultar/mostrar acciones).
     * @param iterable $linkedInvoices Facturas tipo Legalización vinculadas al anticipo.
     * @param array<int,string> $bankingEntities Lista [id => name].
     * @param \App\Model\Entity\InvoicePayment|null $surplusPayment Pago del sobrante (caso CASE_SOBRANTE) si existe.
     * @param bool $canRegisterRefund Pre-computado por el controller vía AdvanceLegalizationActionPolicy.
     * @param bool $canAuthorizeRefundPayment Pre-computado por el controller vía AdvanceLegalizationActionPolicy.
     * @param bool $canConfirmRefundPayment Pre-computado por el controller vía AdvanceLegalizationActionPolicy.
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
    ) {
        // Contrato EditViewModelInterface.
        $this->pageTitle = 'Legalización ' . ($invoice->invoice_number ?? '#' . $invoice->id);
        $this->currentStatus = (string)$leg->status;
        $this->currentStatusBadge = [
            AdvanceConstants::STATUS_LABELS[$leg->status] ?? 'Desconocido',
            AdvancePresentation::STATUS_BADGES[$leg->status] ?? 'pill-muted',
        ];
    }

    /**
     * Construye el set completo de variables para el template.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $linkedTotal = 0.0;
        foreach ($this->linkedInvoices as $li) {
            $linkedTotal += (float)$li->amount;
        }
        $advanceTotal = (float)$this->invoice->amount;
        $diff = $advanceTotal - $linkedTotal;

        $relationDocument = null;
        $signatureHistory = [];
        if ($this->leg->advance_legalization_signatures) {
            $sigs = $this->leg->advance_legalization_signatures;
            usort($sigs, fn($a, $b) => $b->id <=> $a->id);
            foreach ($sigs as $sig) {
                if ($relationDocument === null && ($sig->isPending() || $sig->isSigned())) {
                    $relationDocument = $sig;
                } else {
                    $signatureHistory[] = $sig;
                }
            }
        }

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

        $linkedCount = is_countable($this->linkedInvoices)
            ? count($this->linkedInvoices)
            : iterator_count($this->linkedInvoices);

        $diffBadgeClass = abs($diff) < 0.005
            ? 'pill-primary-soft'
            : ($diff > 0 ? 'pill-warning-soft' : 'pill-danger-soft');

        $caseLabels = [
            AdvanceConstants::CASE_EXACTO => 'Exacto',
            AdvanceConstants::CASE_FALTANTE => 'Faltante',
            AdvanceConstants::CASE_SOBRANTE => 'Sobrante',
        ];

        return [
            'invoice' => $this->invoice,
            'leg' => $this->leg,
            'linkedInvoices' => $this->linkedInvoices,
            'linkedTotal' => $linkedTotal,
            'advanceTotal' => $advanceTotal,
            'diff' => $diff,
            'relationDocument' => $relationDocument,
            'signatureHistory' => $signatureHistory,
            'bankingEntities' => $this->bankingEntities,
            'surplusPayment' => $this->surplusPayment,
            'roleName' => $this->roleName,
            'canRegisterRefund' => $this->canRegisterRefund,
            'canAuthorizeRefundPayment' => $this->canAuthorizeRefundPayment,
            'canConfirmRefundPayment' => $this->canConfirmRefundPayment,
            // Derivaciones de presentación.
            'pageTitle' => $this->pageTitle,
            'legPipelineLabels' => $legPipelineLabels,
            'beneficiary' => $beneficiary,
            'beneficiaryDoc' => $beneficiaryDoc,
            'beneficiaryDocType' => $beneficiaryDocType,
            'beneficiaryKind' => $beneficiaryKind,
            'ps' => $this->currentStatusBadge,
            'linkedCount' => $linkedCount,
            'diffBadgeClass' => $diffBadgeClass,
            'caseLabels' => $caseLabels,
        ];
    }
}

<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Model\Entity\Invoice;
use App\Model\Entity\InvoicePayment;
use App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy;
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
final readonly class AdvanceLegalizationViewModel
{
    /**
     * @param \App\Model\Entity\Invoice $invoice Anticipo invoice.
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización en curso.
     * @param string $roleName Rol del usuario actual (para ocultar/mostrar acciones).
     * @param iterable $linkedInvoices Facturas tipo Legalización vinculadas al anticipo.
     * @param array<int,string> $bankingEntities Lista [id => name].
     * @param \App\Model\Entity\InvoicePayment|null $surplusPayment Pago del sobrante (caso CASE_SOBRANTE) si existe.
     */
    public function __construct(
        public Invoice $invoice,
        public AdvanceLegalization $leg,
        public string $roleName,
        public iterable $linkedInvoices,
        public array $bankingEntities,
        public ?InvoicePayment $surplusPayment,
        public int $userId = 0,
        public ?AdvanceLegalizationActionPolicy $actionPolicy = null,
        public int $roleId = 0,
    ) {
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

        $canRegisterRefund = $this->actionPolicy !== null && $this->roleId > 0
            ? $this->actionPolicy->canRegisterRefund($this->leg, $this->roleId)
            : false;
        $canAuthorizeRefundPayment = $this->actionPolicy !== null && $this->roleId > 0
            ? $this->actionPolicy->canAuthorizeRefundPayment($this->leg, $this->roleId)
            : false;
        $canConfirmRefundPayment = $this->actionPolicy !== null && $this->roleId > 0
            ? $this->actionPolicy->canConfirmRefundPayment($this->leg, $this->roleId)
            : false;

        // ── Derivaciones de presentación (antes inline en el template) ──
        $pageTitle         = 'Legalización ' . ($this->invoice->invoice_number ?? '#' . $this->invoice->id);
        $legPipelineLabels = AdvanceConstants::STATUS_LABELS;

        $beneficiary        = $this->invoice->provider->name ?? ($this->invoice->employee->full_name ?? '—');
        $beneficiaryDoc     = $this->invoice->provider->document_number ?? ($this->invoice->employee->document_number ?? null);
        $beneficiaryDocType = $this->invoice->provider_id
            ? ($this->invoice->provider->document_type ?? '')
            : ($this->invoice->employee_id ? ($this->invoice->employee->document_type ?? '') : '');
        $beneficiaryKind = $this->invoice->provider_id
            ? 'Proveedor'
            : ($this->invoice->employee_id ? 'Empleado' : '—');

        $ps = [
            AdvanceConstants::STATUS_LABELS[$this->leg->status]        ?? 'Desconocido',
            AdvancePresentation::STATUS_BADGES[$this->leg->status]     ?? 'bg-dark',
        ];

        $linkedCount = is_countable($this->linkedInvoices)
            ? count($this->linkedInvoices)
            : iterator_count($this->linkedInvoices);

        $diffBadgeClass = abs($diff) < 0.005
            ? 'bg-success'
            : ($diff > 0 ? 'bg-warning text-dark' : 'bg-danger');

        $caseLabels = [
            AdvanceConstants::CASE_EXACTO   => 'Exacto',
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
            'canRegisterRefund' => $canRegisterRefund,
            'canAuthorizeRefundPayment' => $canAuthorizeRefundPayment,
            'canConfirmRefundPayment' => $canConfirmRefundPayment,
            // Derivaciones de presentación.
            'pageTitle' => $pageTitle,
            'legPipelineLabels' => $legPipelineLabels,
            'beneficiary' => $beneficiary,
            'beneficiaryDoc' => $beneficiaryDoc,
            'beneficiaryDocType' => $beneficiaryDocType,
            'beneficiaryKind' => $beneficiaryKind,
            'ps' => $ps,
            'linkedCount' => $linkedCount,
            'diffBadgeClass' => $diffBadgeClass,
            'caseLabels' => $caseLabels,
        ];
    }
}

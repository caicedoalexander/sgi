<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Model\Entity\Invoice;
use App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy;
use App\View\Presentation\AdvancePresentation;
use Cake\ORM\TableRegistry;

/**
 * Datos pre-calculados que el template `templates/Advances/legalization.php` necesita.
 *
 * Reemplaza el método privado `_buildLegalizationViewModel` que vivía en
 * `AdvancesController` (audit MI-005). Centraliza linked invoices, separación
 * de signature activa vs historial, totales, diff, banking entities y surplus
 * payment para mantener la action delgada.
 */
final readonly class AdvanceLegalizationViewModel
{
    /**
     * @param \App\Model\Entity\Invoice $invoice Anticipo invoice.
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización en curso.
     * @param string $roleName Rol del usuario actual (para ocultar/mostrar acciones).
     */
    public function __construct(
        public Invoice $invoice,
        public AdvanceLegalization $leg,
        public string $roleName,
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
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $linkedInvoices = $invoicesTable->find()
            ->where([
                'Invoices.document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
                'Invoices.advance_id' => $this->invoice->id,
            ])
            ->contain(['Providers', 'Employees'])
            ->orderBy(['Invoices.issue_date' => 'ASC'])
            ->all();

        $linkedTotal = 0.0;
        foreach ($linkedInvoices as $li) {
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

        $bankingEntities = TableRegistry::getTableLocator()->get('BankingEntities')
            ->find('list')
            ->all()
            ->toArray();

        $surplusPayment = null;
        if ($this->leg->surplus_payment_id) {
            $surplusPayment = TableRegistry::getTableLocator()->get('InvoicePayments')->get(
                $this->leg->surplus_payment_id,
                contain: ['BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers'],
            );
        }

        $canRegisterRefund = $this->actionPolicy !== null && $this->roleId > 0
            ? $this->actionPolicy->canRegisterRefund($this->leg, $this->roleId, $this->roleName)
            : false;
        $canAuthorizeRefundPayment = $this->actionPolicy !== null && $this->roleId > 0
            ? $this->actionPolicy->canAuthorizeRefundPayment($this->leg, $this->roleId, $this->roleName)
            : false;
        $canConfirmRefundPayment = $this->actionPolicy !== null && $this->roleId > 0
            ? $this->actionPolicy->canConfirmRefundPayment($this->leg, $this->roleId, $this->roleName)
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

        $linkedCount = $linkedInvoices->count();

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
            'linkedInvoices' => $linkedInvoices,
            'linkedTotal' => $linkedTotal,
            'advanceTotal' => $advanceTotal,
            'diff' => $diff,
            'relationDocument' => $relationDocument,
            'signatureHistory' => $signatureHistory,
            'bankingEntities' => $bankingEntities,
            'surplusPayment' => $surplusPayment,
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

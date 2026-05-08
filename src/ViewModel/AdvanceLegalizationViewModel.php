<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\InvoiceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Model\Entity\Invoice;
use App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy;
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

        $canConfirmRefundPayment = $this->actionPolicy !== null && $this->userId > 0
            ? $this->actionPolicy->canConfirmRefundPayment($this->leg, $this->userId, $this->roleName)
            : false;

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
            'canConfirmRefundPayment' => $canConfirmRefundPayment,
        ];
    }
}

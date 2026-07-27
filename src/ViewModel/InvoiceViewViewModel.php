<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\View\Presentation\InvoicePresentation;

/**
 * Datos inmutables de presentación para InvoicesController::view().
 * Absorbe la derivación escalar de estado/montos/conteos que vivía inline en
 * templates/Invoices/view.php (Ola 2 — C1).
 *
 * NO absorbe los bloques acoplados a helpers de la vista ($this->Url / $this->Html:
 * heroExtraHtml, quickActionsHtml) ni el markup bespoke de pagos/observaciones/
 * soportes/historial — esos se quedan en el template.
 */
final readonly class InvoiceViewViewModel implements ViewViewModelInterface
{
    public string $pageTitle;
    /**
     * @var array{0:string,1:string}
     */
    public array $currentStatusBadge;
    public string $currentStatus;

    public string $statusLabel;
    public string $statusPill;
    public bool $isTerminal;

    /**
     * Pill/label del hero: resuelve la variante "Aprobada" cuando aplica.
     */
    public string $heroStatusPill;
    public string $heroStatusLabel;
    public ?string $heroExtraPill;

    public float $amount;
    public ?string $amountExtraHtml;

    public string $providerName;
    public bool $isReciboDeCaja;

    /**
     * @var list<string>
     */
    public array $approvedNames;

    public int $pagosCount;
    public float $pagosTotal;
    public int $totalDocs;
    public bool $isLinkedLegalization;

    /**
     * @param array<string,\App\Model\Entity\InvoiceDocument[]> $documentsByStatus
     */
    public function __construct(
        public Invoice $record,
        bool $isRejected,
        bool $isApproved,
        array $documentsByStatus = [],
    ) {
        $invoice = $record;
        $status  = $invoice->pipeline_status ?? '';

        $this->currentStatus = $status;
        $this->statusLabel   = InvoiceConstants::STATUS_LABELS[$status] ?? 'Desconocido';
        $this->statusPill    = InvoicePresentation::STATUS_BADGES[$status] ?? 'pill-muted';
        $this->pageTitle     = 'Factura ' . ($invoice->invoice_number ?? '#' . $invoice->id);
        $this->isTerminal    = in_array(
            $status,
            [InvoiceConstants::STATUS_PAGADA, InvoiceConstants::STATUS_LEGALIZADA],
            true,
        );
        $this->currentStatusBadge = [$this->statusLabel, $this->statusPill];

        // Total pagado.
        $pagosTotal = 0.0;
        foreach ($invoice->invoice_payments ?? [] as $p) {
            $pagosTotal += (float)$p->amount;
        }
        $this->pagosCount = is_array($invoice->invoice_payments ?? null) ? count($invoice->invoice_payments) : 0;
        $this->pagosTotal = $pagosTotal;

        $this->amount    = (float)$invoice->amount;
        $this->totalDocs = array_sum(array_map('count', $documentsByStatus));

        // Aprobadores que ya aprobaron.
        $approvedNames = [];
        foreach ($invoice->invoice_approvals ?? [] as $a) {
            if ($a->status === InvoiceConstants::APPROVER_STATUS_APPROVED && $a->hasValue('user')) {
                $approvedNames[] = $a->user->full_name ?? $a->user->username ?? 'Usuario #' . $a->user_id;
            }
        }
        $this->approvedNames = $approvedNames;

        // Titular (recibo de caja vs. factura común).
        $isReciboDeCaja = ($invoice->document_type ?? '') === InvoiceConstants::DOCTYPE_RECIBO_CAJA;
        if ($isReciboDeCaja && ($invoice->equivalent_holder_type ?? '') === 'employee') {
            $providerName = $invoice->hasValue('employee') ? $invoice->employee->full_name : '—';
        } elseif ($isReciboDeCaja && ($invoice->equivalent_holder_type ?? '') === 'manual') {
            $providerName = $invoice->manual_document_number ?? '—';
        } else {
            $providerName = $invoice->hasValue('provider') ? $invoice->provider->name : '—';
        }
        $this->isReciboDeCaja = $isReciboDeCaja;
        $this->providerName   = $providerName;

        // Línea pequeña bajo el monto (pago completo / parcial).
        $amountExtraHtml = null;
        if ($status === InvoiceConstants::STATUS_PAGADA && $invoice->full_payment_date) {
            $amountExtraHtml = '<div class="d-flex align-items-center gap-1" style="font-size:11px;color:var(--text-muted);margin-top:6px;">'
                . '<i class="bi bi-check-circle spi-fg-primary" aria-hidden="true" style="font-size:11px;"></i>'
                . '<span>Pagado · <span class="mono">' . h($invoice->full_payment_date->format('d/m/Y')) . '</span></span></div>';
        } elseif ($invoice->payment_status === InvoiceConstants::PAYMENT_PARTIAL && $this->pagosCount > 0) {
            $amountExtraHtml = '<div class="d-flex align-items-center gap-1" style="font-size:11px;color:var(--text-muted);margin-top:6px;">'
                . '<i class="bi bi-clock spi-fg-warning" aria-hidden="true" style="font-size:11px;"></i>'
                . '<span>Pago parcial · <span class="mono">$ ' . number_format($pagosTotal, 0, ',', '.') . '</span></span></div>';
        }
        $this->amountExtraHtml = $amountExtraHtml;

        // Pill extra del hero (Pago Parcial).
        $heroExtraPill = null;
        if (
            $status === InvoiceConstants::STATUS_TESORERIA
            && $invoice->payment_status === InvoiceConstants::PAYMENT_PARTIAL
        ) {
            $heroExtraPill = '<span class="pill pill-warning-soft">Pago Parcial</span>';
        }
        $this->heroExtraPill = $heroExtraPill;

        // Pill de estado del hero: resuelve la variante "Aprobada".
        $heroStatusPill  = $this->statusPill;
        $heroStatusLabel = $this->statusLabel;
        if (!$isRejected && $isApproved) {
            $heroStatusPill  = 'pill-primary-soft';
            $heroStatusLabel = 'Aprobada';
        }
        $this->heroStatusPill  = $heroStatusPill;
        $this->heroStatusLabel = $heroStatusLabel;

        $this->isLinkedLegalization = !empty($invoice->advance_id)
            && $invoice->usesLegalizationView();
    }
}

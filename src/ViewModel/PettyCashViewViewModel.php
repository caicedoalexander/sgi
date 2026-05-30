<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\PettyCashConstants;
use App\Model\Entity\PettyCashRecord;
use App\View\Presentation\PettyCashPresentation;

/**
 * Datos inmutables de presentación para PettyCashRecordsController::view().
 * Absorbe la derivación escalar de estado/conteos/montos que antes vivía inline
 * en templates/PettyCashRecords/view.php. El markup bespoke (payment card,
 * observaciones con $initialsOf, soportes con document_row) y los bloques
 * acoplados a helpers de la vista ($quickActionsHtml, $canEdit) se quedan en
 * el template.
 */
final class PettyCashViewViewModel implements ViewViewModelInterface
{
    public readonly string $pageTitle;
    /** @var array{0:string,1:string} */
    public readonly array $currentStatusBadge;
    public readonly string $currentStatus;

    public readonly bool   $isTerminal;
    public readonly int    $invoiceCount;
    /** @var list<array{doc:mixed,canDelete:bool,deleteUrl:?string,showBadge:bool}> */
    public readonly array  $documentRows;
    public readonly int    $totalDocs;
    public readonly int    $obsCount;
    public readonly float  $totalAmount;
    public readonly bool   $showPaymentCard;
    public readonly string $amountExtraHtml;
    /** @var list<string> */
    public readonly array $pipelineSteps;
    /** @var array<string,string> */
    public readonly array $pipelineLabels;

    public function __construct(public readonly PettyCashRecord $record)
    {
        $status = $record->status ?? '';
        $labels = PettyCashConstants::STATUS_LABELS;

        $this->currentStatus = $status;
        $this->pageTitle     = 'Caja Menor ' . $record->code;
        $this->currentStatusBadge = [
            $labels[$status] ?? $status,
            PettyCashPresentation::STATUS_BADGES[$status] ?? 'pill-muted',
        ];
        $this->isTerminal      = $status === PettyCashConstants::STATUS_PAGADA;
        $this->invoiceCount    = count($record->invoices ?? []);
        $rows = [];
        foreach ($record->petty_cash_documents ?? [] as $doc) {
            $rows[] = ['doc' => $doc, 'canDelete' => false, 'deleteUrl' => null, 'showBadge' => false];
        }
        $this->documentRows    = $rows;
        $this->totalDocs       = count($rows);
        $this->obsCount        = count($record->petty_cash_observations ?? []);
        $this->totalAmount     = (float)$record->total_amount;
        $this->showPaymentCard = $record->isAutorizacionPago() || $record->isVerificacionPago() || $record->isPagada();
        $this->pipelineSteps   = PettyCashConstants::STATUSES;
        $this->pipelineLabels  = $labels;

        $extra = '';
        if ($this->isTerminal && $record->payment_date) {
            $extra = '<div class="d-flex align-items-center gap-1" style="font-size:11px;color:var(--text-muted);margin-top:6px;">'
                . '<i class="bi bi-check-circle sgi-fg-primary" aria-hidden="true" style="font-size:11px;"></i>'
                . '<span>Pagado · <span class="mono">' . h($record->payment_date->format('d/m/Y')) . '</span></span></div>';
        }
        $this->amountExtraHtml = $extra;
    }
}

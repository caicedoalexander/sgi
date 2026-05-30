<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\View\Presentation\RefundPresentation;

/**
 * Datos inmutables de presentación para RefundsController::view().
 * Encapsula toda la derivación de estado/registro/documentos que antes vivía
 * inline en templates/Refunds/view.php (Ola 2 — C1).
 */
final class RefundViewViewModel implements ViewViewModelInterface
{
    public readonly string $pageTitle;
    /** @var array{0:string,1:string} */
    public readonly array $currentStatusBadge;
    public readonly string $currentStatus;

    public readonly string $code;
    public readonly bool $isTerminal;
    public readonly ?string $beneficiaryName;
    public readonly ?string $beneficiaryLabel;
    public readonly float $totalAmount;
    public readonly int $invoiceCount;
    /** @var array<int,array{icon:string,html:string}> */
    public readonly array $registryLines;
    /** @var list<array{doc:mixed,canDelete:bool,deleteUrl:null,showBadge:bool}> */
    public readonly array $documentRows;
    public readonly int $totalDocs;
    /** @var array<string,string> */
    public readonly array $pipelineLabels;
    /** @var list<string> */
    public readonly array $pipelineSteps;

    public function __construct(public readonly Refund $record)
    {
        $status = $record->status;
        $labels = RefundConstants::STATUS_LABELS;

        $this->currentStatus = $status;
        $this->code          = $record->code;
        $this->pageTitle     = 'Reintegro ' . $record->code;
        $this->currentStatusBadge = [
            $labels[$status] ?? $status,
            RefundPresentation::STATUS_BADGES[$status] ?? 'pill-muted',
        ];
        $this->isTerminal       = $status === RefundConstants::STATUS_PAGADA;
        $this->beneficiaryName  = $record->getBeneficiaryName() ?: null;
        $this->beneficiaryLabel = RefundConstants::BENEFICIARY_TYPES_LABELS[$record->beneficiary_type] ?? null;
        $this->totalAmount      = (float)$record->total_amount;
        $this->invoiceCount     = count($record->invoices ?? []);
        $this->pipelineLabels   = $labels;
        $this->pipelineSteps    = RefundConstants::STATUSES;

        $registry = [];
        if ($record->hasValue('created_by_user')) {
            $registry[] = ['icon' => 'bi-person', 'html' => 'Creado por ' . h($record->created_by_user->full_name)];
        }
        if ($record->created) {
            $registry[] = ['icon' => 'bi-calendar3', 'html' => 'Creado · <span class="mono">' . $record->created->format('d/m/Y H:i') . '</span>'];
        }
        if ($record->modified) {
            $registry[] = ['icon' => 'bi-pencil-square', 'html' => 'Modificado · <span class="mono">' . $record->modified->format('d/m/Y') . '</span>'];
        }
        $this->registryLines = $registry;

        $rows = [];
        foreach ($record->refund_documents ?? [] as $doc) {
            $rows[] = ['doc' => $doc, 'canDelete' => false, 'deleteUrl' => null, 'showBadge' => false];
        }
        $this->documentRows = $rows;
        $this->totalDocs    = count($rows);
    }
}

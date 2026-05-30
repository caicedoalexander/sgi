<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\PaymentSchedulingConstants;
use App\Model\Entity\PaymentScheduling;
use App\View\Presentation\PaymentSchedulingPresentation;

/**
 * Datos inmutables de presentación para PaymentSchedulingsController::view().
 * Encapsula la derivación de estado/registro/documentos que antes vivía inline
 * en templates/PaymentSchedulings/view.php.
 */
final class PaymentSchedulingViewViewModel implements ViewViewModelInterface
{
    public readonly string $pageTitle;
    /** @var array{0:string,1:string} */
    public readonly array $currentStatusBadge;
    public readonly string $currentStatus;

    public readonly string $code;
    public readonly bool   $isTerminal;
    public readonly int    $itemCount;
    public readonly float  $total;
    /** @var array<int,array{icon:string,html:string}> */
    public readonly array $registryLines;
    /** @var list<mixed> */
    public readonly array $documents;
    /** @var list<array{doc:mixed,canDelete:bool,deleteUrl:?string,showBadge:bool}> */
    public readonly array $documentRows;
    public readonly int   $totalDocs;
    /** @var array<string,string> */
    public readonly array $pipelineLabels;
    /** @var list<string> */
    public readonly array $pipelineSteps;

    public function __construct(public readonly PaymentScheduling $record, float $total)
    {
        $status = $record->pipeline_status;
        $labels = PaymentSchedulingConstants::STATUS_LABELS;

        $this->currentStatus = $status;
        $this->code          = $record->code;
        $this->pageTitle     = 'Programación ' . $record->code;
        $this->currentStatusBadge = [
            $labels[$status] ?? $status,
            PaymentSchedulingPresentation::STATUS_BADGES[$status] ?? 'pill-muted',
        ];
        $this->isTerminal     = $status === PaymentSchedulingConstants::STATUS_PAGADA;
        $this->itemCount      = count($record->payment_scheduling_items ?? []);
        $this->total          = $total;
        $this->documents      = $record->payment_scheduling_documents ?? [];
        $rows = [];
        foreach ($this->documents as $doc) {
            $rows[] = ['doc' => $doc, 'canDelete' => false, 'deleteUrl' => null, 'showBadge' => false];
        }
        $this->documentRows   = $rows;
        $this->totalDocs      = count($this->documents);
        $this->pipelineLabels = $labels;
        $this->pipelineSteps  = PaymentSchedulingConstants::PIPELINE_STATUSES;

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
    }
}

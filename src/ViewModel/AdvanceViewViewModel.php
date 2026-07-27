<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\InvoiceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Model\Entity\Invoice;
use App\View\Presentation\InvoicePresentation;
use App\ViewModel\Support\LegalizationSummary;

/**
 * Datos inmutables de presentación para AdvancesController::view().
 * El anticipo ES una factura (entidad Invoice); por eso el estado se deriva
 * sobre el pipeline de facturas (InvoicePresentation / InvoiceConstants).
 * Absorbe la derivación de estado/registro que antes vivía inline en
 * templates/Advances/view.php (Ola 2 — Advance).
 */
final readonly class AdvanceViewViewModel implements ViewViewModelInterface
{
    public string $pageTitle;
    /**
     * @var array{0:string,1:string}
     */
    public array $currentStatusBadge;
    public string $currentStatus;

    public string $idLabel;
    public bool $isTerminal;
    public string $beneficiary;
    public string $beneficiaryType;
    public float $amount;
    /**
     * @var array<int,array{icon:string,html:string}>
     */
    public array $registryLines;
    /**
     * @var array<string,string>
     */
    public array $pipelineLabels;
    /**
     * @var list<string>
     */
    public array $pipelineSteps;
    public bool $hasLegalization;
    public ?LegalizationSummary $legalizationSummary;
    /**
     * @var list<array{doc:mixed,canDelete:bool,showBadge:bool,badgeColors:array<string,string>,statusLabels:array<string,string>}>
     */
    public array $documentRows;
    public int $totalDocs;

    /**
     * @param \App\Model\Entity\Invoice $record Anticipo (factura tipo Anticipo).
     * @param \App\Model\Entity\AdvanceLegalization|null $legalization Legalización asociada si existe.
     * @param iterable $linkedInvoices Facturas de legalización vinculadas al anticipo.
     */
    public function __construct(
        public Invoice $record,
        public ?AdvanceLegalization $legalization = null,
        public iterable $linkedInvoices = [],
    ) {
        $status = $record->pipeline_status;
        $labels = InvoiceConstants::STATUS_LABELS;

        $this->currentStatus = $status;
        $this->idLabel       = $record->invoice_number ?? '#' . $record->id;
        $this->pageTitle     = $this->idLabel;
        $this->currentStatusBadge = [
            $labels[$status] ?? $status,
            InvoicePresentation::STATUS_BADGES[$status] ?? 'pill-muted',
        ];
        $this->isTerminal      = $status === InvoiceConstants::STATUS_PAGADA;
        $this->beneficiary     = $record->provider->name ?? ($record->employee->full_name ?? '—');
        $this->beneficiaryType = $record->provider_id ? 'Proveedor' : ($record->employee_id ? 'Empleado' : '—');
        $this->amount          = (float)$record->amount;
        $this->pipelineLabels  = $labels;
        $this->pipelineSteps   = InvoiceConstants::PIPELINE_STATUSES;

        $registry = [];
        if ($record->hasValue('registered_by_user')) {
            $registry[] = ['icon' => 'bi-person', 'html' => 'Registrado por ' . h($record->registered_by_user->full_name)];
        }
        if ($record->created) {
            $registry[] = ['icon' => 'bi-calendar3', 'html' => 'Creado · <span class="mono">' . $record->created->format('d/m/Y H:i') . '</span>'];
        }
        if ($record->modified) {
            $registry[] = ['icon' => 'bi-pencil-square', 'html' => 'Modificado · <span class="mono">' . $record->modified->format('d/m/Y') . '</span>'];
        }
        $this->registryLines = $registry;

        $this->hasLegalization = $this->legalization !== null;
        $this->legalizationSummary = $this->legalization !== null
            ? new LegalizationSummary($this->legalization, (float)$record->amount, $this->linkedInvoices)
            : null;

        $docRows = [];
        foreach ($record->invoice_documents ?? [] as $doc) {
            $docRows[] = [
                'doc' => $doc,
                'canDelete' => false,
                'showBadge' => true,
                'badgeColors' => InvoicePresentation::STATUS_BADGES,
                'statusLabels' => InvoiceConstants::STATUS_LABELS,
            ];
        }
        $this->documentRows = $docRows;
        $this->totalDocs = count($docRows);
    }
}

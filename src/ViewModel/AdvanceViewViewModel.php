<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\View\Presentation\InvoicePresentation;

/**
 * Datos inmutables de presentación para AdvancesController::view().
 * El anticipo ES una factura (entidad Invoice); por eso el estado se deriva
 * sobre el pipeline de facturas (InvoicePresentation / InvoiceConstants).
 * Absorbe la derivación de estado/registro que antes vivía inline en
 * templates/Advances/view.php (Ola 2 — Advance).
 */
final class AdvanceViewViewModel implements ViewViewModelInterface
{
    public readonly string $pageTitle;
    /** @var array{0:string,1:string} */
    public readonly array $currentStatusBadge;
    public readonly string $currentStatus;

    public readonly string $idLabel;
    public readonly bool $isTerminal;
    public readonly string $beneficiary;
    public readonly string $beneficiaryType;
    public readonly float $amount;
    /** @var array<int,array{icon:string,html:string}> */
    public readonly array $registryLines;
    /** @var array<string,string> */
    public readonly array $pipelineLabels;
    /** @var list<string> */
    public readonly array $pipelineSteps;

    public function __construct(public readonly Invoice $record)
    {
        $status = $record->pipeline_status;
        $labels = InvoiceConstants::STATUS_LABELS;

        $this->currentStatus = $status;
        $this->idLabel       = $record->invoice_number ?? ('#' . $record->id);
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
    }
}

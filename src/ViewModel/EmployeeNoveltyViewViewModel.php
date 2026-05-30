<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\View\Presentation\NoveltyPresentation;

/**
 * Datos inmutables de presentación para EmployeeNoveltiesController::view().
 * Absorbe la derivación escalar de estado/registro que antes vivía inline en
 * templates/EmployeeNovelties/view.php (Ola 2 — Novelty).
 *
 * NOTA: los pasos del pipeline (effectiveStatuses/noveltyStatuses) los calcula
 * NoveltyPipelineService y se pasan por separado al template — no derivan de la
 * entidad, así que NO viven aquí.
 */
final readonly class EmployeeNoveltyViewViewModel implements ViewViewModelInterface
{
    public string $pageTitle;
    /** @var array{0:string,1:string} */
    public array $currentStatusBadge;
    public string $currentStatus;

    public bool   $isRejected;
    public bool   $isTerminal;
    public string $noveltyName;
    /** @var array<string,string> */
    public array $pipelineLabels;
    /** @var array<int,array{icon:string,html:string}> */
    public array $registryLines;

    public function __construct(public EmployeeNovelty $record)
    {
        $status = (string)$record->pipeline_status;
        $labels = NoveltyConstants::STATUS_LABELS;

        $this->currentStatus = $status;
        $this->pageTitle     = 'Novedad #' . $record->id;
        $this->isRejected    = $record->isRejected();
        $this->isTerminal    = $status === NoveltyConstants::STATUS_PAGADA;
        $this->noveltyName   = $record->custom_name
            ?: ($record->employee->full_name ?? ('Novedad #' . $record->id));

        $this->currentStatusBadge = $this->isRejected
            ? ['Rechazada', 'pill-danger-soft']
            : [
                $labels[$status] ?? $status,
                NoveltyPresentation::STATUS_BADGES[$status] ?? 'pill-muted',
            ];

        // El pipeline de novedades reetiqueta "Contabilidad" → "Paso a Nómina".
        $pipelineLabels = $labels;
        $pipelineLabels[NoveltyConstants::STATUS_CONTABILIDAD] = 'Paso a Nómina';
        $this->pipelineLabels = $pipelineLabels;

        $registry = [];
        if ($record->registered_by_user) {
            $registry[] = ['icon' => 'bi-person', 'html' => 'Registrado por ' . h($record->registered_by_user->full_name)];
        }
        if ($record->filing_date) {
            $registry[] = ['icon' => 'bi-calendar3', 'html' => 'Diligenciada · <span class="mono">' . $record->filing_date->format('d/m/Y') . '</span>'];
        }
        if ($record->modified) {
            $registry[] = ['icon' => 'bi-pencil-square', 'html' => 'Modificada · <span class="mono">' . $record->modified->format('d/m/Y') . '</span>'];
        }
        $this->registryLines = $registry;
    }
}

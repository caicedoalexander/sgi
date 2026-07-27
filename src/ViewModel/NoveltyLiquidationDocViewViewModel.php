<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\NoveltyConstants;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\View\Presentation\NoveltyPresentation;

/**
 * Datos inmutables de presentación para NoveltyLiquidationDocsController::view().
 * Absorbe la derivación escalar de estado/registro/pills extra que antes vivía
 * inline en templates/NoveltyLiquidationDocs/view.php (Ola 2 — Novelty).
 *
 * NOTA: effectiveStatuses (pasos del pipeline) lo calcula NoveltyPipelineService
 * y se pasa por separado — no deriva de la entidad, no vive aquí.
 */
final readonly class NoveltyLiquidationDocViewViewModel implements ViewViewModelInterface
{
    public string $pageTitle;
    /**
     * @var array{0:string,1:string}
     */
    public array $currentStatusBadge;
    public string $currentStatus;

    public bool $isRejected;
    public bool $isTerminal;
    public string $periodLabel;
    public int $noveltyCount;
    public string $extraPillHtml;
    /**
     * @var array<int,array{icon:string,html:string}>
     */
    public array $registryLines;

    /**
     * @param \App\Model\Entity\NoveltyLiquidationDoc $record Documento de liquidación a presentar.
     */
    public function __construct(public NoveltyLiquidationDoc $record)
    {
        $status        = (string)$record->pipeline_status;
        $labels        = NoveltyConstants::STATUS_LABELS;
        $paymentLabels = NoveltyConstants::PAYMENT_LABELS;

        $this->currentStatus = $status;
        $this->pageTitle     = 'Liquidación: ' . $record->liquidation_number;
        $this->isRejected    = $status === NoveltyConstants::STATUS_RECHAZADA;
        $this->isTerminal    = $status === NoveltyConstants::STATUS_PAGADA;
        $this->periodLabel   = NoveltyConstants::PERIOD_LABELS[$record->period] ?? (string)$record->period;
        $this->noveltyCount  = count($record->employee_novelties ?? []);

        $this->currentStatusBadge = $this->isRejected
            ? ['Rechazada', 'pill-danger-soft']
            : [
                $labels[$status] ?? $status,
                NoveltyPresentation::STATUS_BADGES[$status] ?? 'pill-muted',
            ];

        $extraPills = [];
        if ($record->passes_for_payment === true) {
            $extraPills[] = '<span class="pill pill-primary-soft">Pasa a pago</span>';
        } elseif ($record->passes_for_payment === false) {
            $extraPills[] = '<span class="pill pill-muted">No pasa a pago</span>';
        }
        if ($record->payment_status) {
            $extraPills[] = '<span class="pill pill-info-soft">' . h($paymentLabels[$record->payment_status] ?? $record->payment_status) . '</span>';
        }
        $this->extraPillHtml = implode(' ', $extraPills);

        $registry = [];
        if ($record->performed_by_user) {
            $registry[] = ['icon' => 'bi-person', 'html' => 'Elaborado por ' . h($record->performed_by_user->full_name)];
        }
        if ($record->created) {
            $registry[] = ['icon' => 'bi-calendar3', 'html' => 'Creado · <span class="mono">' . $record->created->format('d/m/Y') . '</span>'];
        }
        if ($record->modified) {
            $registry[] = ['icon' => 'bi-pencil-square', 'html' => 'Modificado · <span class="mono">' . $record->modified->format('d/m/Y') . '</span>'];
        }
        if ($record->payment_date) {
            $registry[] = ['icon' => 'bi-cash-coin', 'html' => 'Pagado · <span class="mono">' . $record->payment_date->format('d/m/Y') . '</span>'];
        }
        $this->registryLines = $registry;
    }
}

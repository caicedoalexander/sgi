<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\View\Presentation\NoveltyPresentation;

/**
 * Datos inmutables de vista para EmployeeNoveltiesController::edit().
 * El controller construye este objeto; la vista accede via get_object_vars().
 */
final class EmployeeNoveltyEditViewModel implements EditViewModelInterface
{
    // ── Propiedades derivadas (calculadas en el constructor) ────────────
    public readonly string $pageTitle;
    /** @var array<string,string> */
    public readonly array $statusLabels;
    /** @var array<string,string> */
    public readonly array $statusIcons;
    /** @var array<string,string> */
    public readonly array $scheduleLabels;
    /** @var array<string,string> */
    public readonly array $statusBadgeMap;
    /** @var array{0:string,1:string} */
    public readonly array $currentStatusBadge;
    /** @var array<string,string> Badge colors del módulo, expuestos al template. */
    public readonly array $badgeColors;
    /** @var array<string> Secciones del formulario a renderizar. */
    public readonly array $sections;
    public readonly bool $isRejected;
    public readonly string $currentStatus;
    public readonly bool $showUploadSection;
    public readonly int $totalDocs;

    /**
     * @param array<string> $editableFields
     * @param array<string> $visibleSections
     * @param array<string> $effectiveStatuses
     * @param array<string> $noveltyStatuses
     * @param array<string> $transitionErrors
     * @param array<int, string> $approversList
     * @param array<string, mixed> $documentsByStatus
     * @param array<int, string> $liquidationDocs
     * @param iterable<int, mixed> $emailLogs
     */
    public function __construct(
        public readonly EmployeeNovelty $novelty,
        public readonly string $roleName,
        public readonly array $editableFields,
        public readonly array $visibleSections,
        public readonly array $effectiveStatuses,
        public readonly array $noveltyStatuses,
        public readonly ?string $nextStatus,
        public readonly array $transitionErrors,
        public readonly bool $canAdvance,
        public readonly bool $isApprovalRejected,
        public readonly array $approversList,
        public readonly array $documentsByStatus,
        public readonly array $liquidationDocs,
        public readonly iterable $emailLogs,
    ) {
        $this->pageTitle      = 'Editar Novedad #' . $novelty->id;
        $this->statusLabels   = NoveltyConstants::STATUS_LABELS;
        $this->statusIcons    = NoveltyPresentation::STATUS_ICONS;
        $this->scheduleLabels = NoveltyConstants::SCHEDULE_LABELS;
        $this->badgeColors    = NoveltyPresentation::STATUS_BADGES;

        $this->isRejected    = $novelty->isRejected();
        $this->currentStatus = $novelty->pipeline_status;
        $this->sections      = $visibleSections;

        $this->statusBadgeMap = NoveltyPresentation::STATUS_BADGES;
        $this->currentStatusBadge = [
            $this->statusLabels[$this->currentStatus]   ?? 'Desconocido',
            $this->statusBadgeMap[$this->currentStatus] ?? 'pill-muted',
        ];

        $this->showUploadSection = !$this->isRejected && !$novelty->isPaid();
        $this->totalDocs         = array_sum(array_map('count', $documentsByStatus));
    }
}

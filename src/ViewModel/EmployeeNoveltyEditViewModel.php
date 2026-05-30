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
final readonly class EmployeeNoveltyEditViewModel implements EditViewModelInterface
{
    // ── Propiedades derivadas (calculadas en el constructor) ────────────
    public string $pageTitle;
    /** @var array<string,string> */
    public array $statusLabels;
    /** @var array<string,string> */
    public array $scheduleLabels;
    /** @var array<string,string> */
    public array $statusBadgeMap;
    /** @var array{0:string,1:string} */
    public array $currentStatusBadge;
    /** @var array<string,string> Badge colors del módulo, expuestos al template. */
    public array $badgeColors;
    /** @var array<string> Secciones del formulario a renderizar. */
    public array $sections;
    public bool $isRejected;
    public string $currentStatus;
    public bool $showUploadSection;
    public int $totalDocs;

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
        public EmployeeNovelty $novelty,
        public string $roleName,
        public array $editableFields,
        public array $visibleSections,
        public array $effectiveStatuses,
        public array $noveltyStatuses,
        public ?string $nextStatus,
        public array $transitionErrors,
        public bool $canAdvance,
        public bool $isApprovalRejected,
        public array $approversList,
        public array $documentsByStatus,
        public array $liquidationDocs,
        public iterable $emailLogs,
    ) {
        $this->pageTitle      = 'Editar Novedad #' . $novelty->id;
        $this->statusLabels   = NoveltyConstants::STATUS_LABELS;
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

<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\NoveltyConstants;
use App\Model\Entity\NoveltyDocument;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Model\Entity\User;
use App\View\Presentation\NoveltyPresentation;

/**
 * Datos inmutables de vista para NoveltyLiquidationDocsController::edit().
 * El controller construye este objeto; la vista accede via get_object_vars().
 */
final readonly class NoveltyLiquidationDocEditViewModel implements EditViewModelInterface
{
    // ── Propiedades derivadas (calculadas en el constructor) ────────────
    public string $pageTitle;
    /**
     * @var array<string,string>
     */
    public array $statusLabels;
    /**
     * @var array<string,string>
     */
    public array $periodLabels;
    /**
     * @var array<string,string>
     */
    public array $signerLabels;
    /**
     * @var array<string,string>
     */
    public array $paymentLabels;
    /**
     * @var array<string,string>
     */
    public array $statusBadgeMap;
    /**
     * @var array{0:string,1:string}
     */
    public array $currentStatusBadge;
    /**
     * @var array<string,string>
     */
    public array $badgeColors;
    public bool $isRejected;
    public bool $isPaid;
    public bool $isFinal;
    public string $currentStatus;
    public bool $showUploadSection;
    public int $totalDocs;
    public int $noveltyCount;

    /**
     * @param array<string> $groupErrors
     * @param array<string> $effectiveStatuses
     * @param array<string, mixed> $documentsByStatus
     * @param array<int, string> $bankingEntities
     */
    public function __construct(
        public NoveltyLiquidationDoc $doc,
        public string $roleName,
        public array $groupErrors,
        public array $effectiveStatuses,
        public array $documentsByStatus,
        public ?NoveltyDocument $liquidationDocument,
        public User $currentUser,
        public bool $skipsGdp,
        public array $bankingEntities,
        public bool $canRegisterPayment,
        public bool $canAuthorizePayment,
        public bool $canConfirmPayment,
    ) {
        $this->pageTitle    = 'Editar Liquidación: ' . ($doc->liquidation_number ?? '#' . $doc->id);
        $this->statusLabels = NoveltyConstants::STATUS_LABELS;
        $this->periodLabels  = NoveltyConstants::PERIOD_LABELS;
        $this->signerLabels  = NoveltyConstants::SIGNER_LABELS;
        $this->paymentLabels = NoveltyConstants::PAYMENT_LABELS;
        $this->badgeColors   = NoveltyPresentation::STATUS_BADGES;

        $this->isRejected    = $doc->pipeline_status === NoveltyConstants::STATUS_RECHAZADA;
        $this->isPaid        = $doc->pipeline_status === NoveltyConstants::STATUS_PAGADA;
        $this->isFinal       = $this->isRejected || $this->isPaid;
        $this->currentStatus = $doc->pipeline_status;

        $this->statusBadgeMap = NoveltyPresentation::STATUS_BADGES;
        $this->currentStatusBadge = [
            $this->statusLabels[$this->currentStatus]   ?? 'Desconocido',
            $this->statusBadgeMap[$this->currentStatus] ?? 'pill-muted',
        ];

        $this->showUploadSection = !$this->isFinal;
        $this->totalDocs         = array_sum(array_map('count', $documentsByStatus));
        $this->noveltyCount      = count($doc->employee_novelties ?? []);
    }
}

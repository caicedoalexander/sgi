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
 */
final class NoveltyLiquidationDocEditViewModel
{
    // ── Propiedades derivadas (calculadas en el constructor) ────────────
    public readonly string $pageTitle;
    /** @var array<string,string> */
    public readonly array $statusLabels;
    /** @var array<string,string> */
    public readonly array $statusIcons;
    /** @var array<string,string> */
    public readonly array $periodLabels;
    /** @var array<string,string> */
    public readonly array $signerLabels;
    /** @var array<string,string> */
    public readonly array $paymentLabels;
    /** @var array<string,string> */
    public readonly array $statusBadgeMap;
    /** @var array{0:string,1:string} */
    public readonly array $currentStatusBadge;
    /** @var array<string,string> */
    public readonly array $badgeColors;
    public readonly bool $isRejected;
    public readonly bool $isPaid;
    public readonly bool $isFinal;
    public readonly string $currentStatus;
    public readonly bool $showUploadSection;
    public readonly int $totalDocs;
    public readonly int $noveltyCount;

    /**
     * @param array<string> $groupErrors
     * @param array<string> $effectiveStatuses
     * @param array<string, mixed> $documentsByStatus
     * @param array<int, string> $bankingEntities
     */
    public function __construct(
        public readonly NoveltyLiquidationDoc $doc,
        public readonly string $roleName,
        public readonly array $groupErrors,
        public readonly array $effectiveStatuses,
        public readonly array $documentsByStatus,
        public readonly ?NoveltyDocument $liquidationDocument,
        public readonly User $currentUser,
        public readonly bool $skipsGdp,
        public readonly array $bankingEntities,
        public readonly bool $canRegisterPayment,
        public readonly bool $canAuthorizePayment,
        public readonly bool $canConfirmPayment,
    ) {
        $this->pageTitle    = 'Editar Liquidación: ' . ($doc->liquidation_number ?? ('#' . $doc->id));
        $this->statusLabels = NoveltyConstants::STATUS_LABELS;
        $this->statusIcons  = NoveltyPresentation::STATUS_ICONS;
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
            $this->statusBadgeMap[$this->currentStatus] ?? 'bg-dark',
        ];

        $this->showUploadSection = !$this->isFinal;
        $this->totalDocs         = array_sum(array_map('count', $documentsByStatus));
        $this->noveltyCount      = count($doc->employee_novelties ?? []);
    }
}

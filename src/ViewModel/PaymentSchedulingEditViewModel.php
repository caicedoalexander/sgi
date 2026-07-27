<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\PaymentSchedulingConstants;
use App\Model\Entity\PaymentScheduling;
use App\View\Presentation\PaymentSchedulingPresentation;

/**
 * Datos inmutables de vista para PaymentSchedulingsController::edit().
 * El controller construye este objeto; la vista accede via get_object_vars().
 */
final readonly class PaymentSchedulingEditViewModel implements EditViewModelInterface
{
    // ── Propiedades derivadas (calculadas en el constructor) ────────────
    public string $pageTitle;
    /**
     * @var array{0:string,1:string} Pareja [label, class] para el currentStatus.
     */
    public array $currentStatusBadge;
    public int $itemCount;
    public bool $isBorrador;
    public bool $isPagada;

    /**
     * @param array<string> $advanceErrors
     * @param array<string, string> $pipelineLabels
     */
    public function __construct(
        public PaymentScheduling $record,
        public string $roleName,
        public string $currentStatus,
        public bool $canAdvance,
        public bool $canReject,
        public bool $canRegress,
        public bool $canConfirmPayment,
        public ?string $nextStatus,
        public ?string $previousStatus,
        public ?string $regressLockMessage,
        public array $advanceErrors,
        public float $total,
        public array $pipelineLabels,
        public mixed $bankingEntities,
    ) {
        $this->pageTitle = 'Programación ' . ($record->code ?? '#' . $record->id);

        $this->currentStatusBadge = [
            $pipelineLabels[$currentStatus] ?? 'Desconocido',
            PaymentSchedulingPresentation::STATUS_BADGES[$currentStatus] ?? 'pill-muted',
        ];

        $this->itemCount  = count($record->payment_scheduling_items ?? []);
        $this->isBorrador = $currentStatus === PaymentSchedulingConstants::STATUS_BORRADOR;
        $this->isPagada   = $currentStatus === PaymentSchedulingConstants::STATUS_PAGADA;
    }
}

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
final class PaymentSchedulingEditViewModel implements EditViewModelInterface
{
    // ── Propiedades derivadas (calculadas en el constructor) ────────────
    public readonly string $pageTitle;
    /** @var array{0:string,1:string} Pareja [label, class] para el currentStatus. */
    public readonly array $currentStatusBadge;
    public readonly int $itemCount;
    public readonly bool $isBorrador;
    public readonly bool $isPagada;

    /**
     * @param array<string> $advanceErrors
     * @param array<string, string> $pipelineLabels
     */
    public function __construct(
        public readonly PaymentScheduling $record,
        public readonly string $roleName,
        public readonly string $currentStatus,
        public readonly bool $canAdvance,
        public readonly bool $canReject,
        public readonly bool $canRegress,
        public readonly bool $canConfirmPayment,
        public readonly ?string $nextStatus,
        public readonly ?string $previousStatus,
        public readonly ?string $regressLockMessage,
        public readonly array $advanceErrors,
        public readonly float $total,
        public readonly array $pipelineLabels,
        public readonly mixed $bankingEntities,
    ) {
        $this->pageTitle = 'Programación ' . ($record->code ?? ('#' . $record->id));

        $this->currentStatusBadge = [
            $pipelineLabels[$currentStatus] ?? 'Desconocido',
            PaymentSchedulingPresentation::STATUS_BADGES[$currentStatus] ?? 'pill-muted',
        ];

        $this->itemCount  = count($record->payment_scheduling_items ?? []);
        $this->isBorrador = $currentStatus === PaymentSchedulingConstants::STATUS_BORRADOR;
        $this->isPagada   = $currentStatus === PaymentSchedulingConstants::STATUS_PAGADA;
    }
}

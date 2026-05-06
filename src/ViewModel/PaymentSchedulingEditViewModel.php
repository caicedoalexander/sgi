<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\PaymentScheduling;

/**
 * Datos inmutables de vista para PaymentSchedulingsController::edit().
 */
final class PaymentSchedulingEditViewModel
{
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
        public readonly ?string $nextStatus,
        public readonly ?string $previousStatus,
        public readonly ?string $regressLockMessage,
        public readonly array $advanceErrors,
        public readonly float $total,
        public readonly array $pipelineLabels,
        public readonly mixed $bankingEntities,
    ) {
    }
}

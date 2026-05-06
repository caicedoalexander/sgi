<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\EmployeeNovelty;

/**
 * Datos inmutables de vista para EmployeeNoveltiesController::edit().
 */
final class EmployeeNoveltyEditViewModel
{
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
    }
}

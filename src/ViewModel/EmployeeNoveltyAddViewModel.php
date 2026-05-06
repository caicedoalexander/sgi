<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\EmployeeNovelty;

/**
 * Datos inmutables de vista para EmployeeNoveltiesController::add() (GET).
 */
final class EmployeeNoveltyAddViewModel
{
    /**
     * @param iterable<int, mixed> $employees
     * @param array<int, array<int, string>> $noveltyTypes
     * @param array<int, string> $approversList
     */
    public function __construct(
        public readonly EmployeeNovelty $novelty,
        public readonly iterable $employees,
        public readonly array $noveltyTypes,
        public readonly array $approversList,
        public readonly ?int $preselectedEmployee,
    ) {
    }
}

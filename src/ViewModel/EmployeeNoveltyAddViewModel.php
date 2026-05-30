<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\EmployeeNovelty;

/**
 * Datos inmutables de vista para EmployeeNoveltiesController::add() (GET).
 */
final readonly class EmployeeNoveltyAddViewModel
{
    /**
     * @param iterable<int, mixed> $employees
     * @param array<int, array<int, string>> $noveltyTypes
     * @param array<int, string> $approversList
     */
    public function __construct(
        public EmployeeNovelty $novelty,
        public iterable $employees,
        public array $noveltyTypes,
        public array $approversList,
        public ?int $preselectedEmployee,
    ) {
    }
}

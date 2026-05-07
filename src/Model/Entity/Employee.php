<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\ContractTypeConstants;
use App\Constants\EmployeeStatusConstants;
use App\Constants\NoveltyConstants;
use Cake\Chronos\Chronos;
use Cake\Chronos\ChronosDate;
use Cake\ORM\Entity;

class Employee extends Entity
{
    protected array $_accessible = [
        'document_type' => true,
        'document_number' => true,
        'first_name' => true,
        'last_name1' => true,
        'last_name2' => true,
        'birth_date' => true,
        'gender' => true,
        'marital_status_id' => true,
        'education_level_id' => true,
        'email' => true,
        'phone' => true,
        'address' => true,
        'city' => true,
        'status' => true,
        'position_id' => true,
        'supervisor_position_id' => true,
        'operation_center_id' => true,
        'cost_center_id' => true,
        'hire_date' => true,
        'termination_date' => true,
        'salary' => true,
        'contract_type' => true,
        'temporary_organization_id' => true,
        'vest_number' => true,
        'eps' => true,
        'pension_fund' => true,
        'arl' => true,
        'severance_fund' => true,
        'profile_image' => true,
    ];

    protected function _getFullName(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name1 ?? '') . ' ' . ($this->last_name2 ?? ''));
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === EmployeeStatusConstants::ACTIVO;
    }

    /**
     * @return bool
     */
    public function isRetired(): bool
    {
        return $this->status === EmployeeStatusConstants::RETIRADO;
    }

    /**
     * @return bool
     */
    public function requiresTemporaryOrg(): bool
    {
        return $this->contract_type === ContractTypeConstants::OBRA_LABOR;
    }

    /**
     * @return bool
     */
    public function hasActiveNoveltyToday(): bool
    {
        return $this->_getCurrentNovelty() !== null;
    }

    /**
     * Get the first active novelty for today, filtering in memory.
     *
     * Independiente del orden y filtrado del finder. Funciona tanto si
     * employee_novelties fue cargado por findWithCurrentNovelty (1 fila ya
     * filtrada) como por contain plano (historial completo).
     */
    protected function _getCurrentNovelty(): ?EmployeeNovelty
    {
        $novelties = $this->employee_novelties ?? [];
        if ($novelties === []) {
            return null;
        }

        $today = date('Y-m-d');

        foreach ($novelties as $novelty) {
            if (!$this->_isNoveltyActiveOn($novelty, $today)) {
                continue;
            }

            return $novelty;
        }

        return null;
    }

    private function _isNoveltyActiveOn(EmployeeNovelty $novelty, string $today): bool
    {
        if ($novelty->pipeline_status === NoveltyConstants::STATUS_RECHAZADA) {
            return false;
        }

        $start = $novelty->start_date !== null ? (string)$novelty->start_date : null;
        $end = $novelty->end_date !== null ? (string)$novelty->end_date : null;
        $permission = $novelty->permission_date !== null ? (string)$novelty->permission_date : null;

        // Single-day permission: permission_date == today AND no range
        if ($start === null && $permission === $today) {
            return true;
        }

        // Multi-day range: today within [start_date, end_date]
        if ($start !== null && $end !== null && $start <= $today && $today <= $end) {
            return true;
        }

        return false;
    }

    protected function _getAge(): ?int
    {
        $birthDate = $this->birth_date;
        if (!$birthDate) {
            return null;
        }
        if ($birthDate instanceof ChronosDate) {
            return (int)$birthDate->diffInYears(new ChronosDate('today'));
        }
        if ($birthDate instanceof Chronos) {
            return (int)$birthDate->diffInYears(Chronos::now());
        }

        return null;
    }
}

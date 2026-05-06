<?php
declare(strict_types=1);

namespace App\Model\Entity;

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
     * Get the first active novelty for today (loaded via conditional contain).
     */
    protected function _getCurrentNovelty(): ?EmployeeNovelty
    {
        $novelties = $this->employee_novelties ?? [];

        return !empty($novelties) ? $novelties[0] : null;
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

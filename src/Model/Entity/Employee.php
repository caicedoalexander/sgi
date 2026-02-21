<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class Employee extends Entity
{
    protected array $_accessible = [
        'document_type' => true,
        'document_number' => true,
        'first_name' => true,
        'last_name' => true,
        'birth_date' => true,
        'gender' => true,
        'marital_status_id' => true,
        'education_level_id' => true,
        'email' => true,
        'phone' => true,
        'address' => true,
        'city' => true,
        'employee_status_id' => true,
        'position_id' => true,
        'supervisor_position_id' => true,
        'operation_center_id' => true,
        'cost_center_id' => true,
        'hire_date' => true,
        'termination_date' => true,
        'salary' => true,
        'eps' => true,
        'pension_fund' => true,
        'arl' => true,
        'severance_fund' => true,
        'notes' => true,
        'profile_image' => true,
        'active' => true,
    ];

    protected function _getFullName(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }
}

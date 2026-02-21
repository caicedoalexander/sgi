<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class EmployeeLeave extends Entity
{
    protected array $_accessible = [
        'employee_id' => true,
        'leave_type_id' => true,
        'start_date' => true,
        'end_date' => true,
        'status' => true,
        'observations' => true,
        'approved_by' => true,
        'approved_at' => true,
        'requested_by' => true,
    ];
}

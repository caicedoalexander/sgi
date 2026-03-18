<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class EmployeeObservation extends Entity
{
    protected array $_accessible = [
        'employee_id' => true,
        'user_id' => true,
        'message' => true,
    ];
}

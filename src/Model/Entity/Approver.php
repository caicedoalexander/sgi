<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class Approver extends Entity
{
    protected array $_accessible = [
        'user_id' => true,
        'operation_center_id' => true,
        'active' => true,
    ];
}

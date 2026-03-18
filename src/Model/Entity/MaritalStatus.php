<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class MaritalStatus extends Entity
{
    protected array $_accessible = [
        'name' => true,
    ];
}

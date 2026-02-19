<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class OperationCenter extends Entity
{
    protected array $_accessible = [
        'code' => true,
        'name' => true,
    ];
}

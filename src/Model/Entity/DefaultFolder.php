<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class DefaultFolder extends Entity
{
    protected array $_accessible = [
        'name' => true,
        'sort_order' => true,
    ];
}

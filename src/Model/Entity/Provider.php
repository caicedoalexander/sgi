<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class Provider extends Entity
{
    protected array $_accessible = [
        'document_type' => true,
        'document_number' => true,
        'name' => true,
        'active' => true,
    ];
}

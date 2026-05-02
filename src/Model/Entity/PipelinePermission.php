<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class PipelinePermission extends Entity
{
    protected array $_accessible = [
        'role_id' => true,
        'pipeline' => true,
        'step' => true,
        'can_operate' => true,
        'created' => true,
        'modified' => true,
        'role' => true,
    ];
}

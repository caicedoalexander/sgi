<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class SystemSetting extends Entity
{
    protected array $_accessible = [
        'setting_key' => true,
        'setting_value' => true,
        'setting_group' => true,
    ];
}

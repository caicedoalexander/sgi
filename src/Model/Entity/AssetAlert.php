<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class AssetAlert extends Entity
{
    protected array $_accessible = [
        'alert_type' => true,
        'priority' => true,
        'asset_id' => true,
        'consumable_id' => true,
        'asset_movement_id' => true,
        'message' => true,
        'status' => false,
        'notified_at' => false,
        'resolved_at' => false,
        'created' => false,
        'modified' => false,
    ];
}

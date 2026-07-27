<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class ConsumableMovement extends Entity
{
    protected array $_accessible = [
        'consumable_id' => true,
        'movement_type' => true,
        'quantity' => true,
        'balance_after' => true,
        'reason' => true,
        'related_asset_id' => true,
        'movement_date' => true,
        'performed_by_user_id' => true,
        'requested_by_phone' => true,
        'source' => true,
        'created' => false,
    ];
}

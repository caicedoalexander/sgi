<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class PaymentSchedulingHistory extends Entity
{
    protected array $_accessible = [
        'payment_scheduling_id' => true,
        'user_id' => true,
        'field_changed' => true,
        'old_value' => true,
        'new_value' => true,
    ];
}

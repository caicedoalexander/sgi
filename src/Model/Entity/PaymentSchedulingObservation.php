<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class PaymentSchedulingObservation extends Entity
{
    protected array $_accessible = [
        'payment_scheduling_id' => true,
        'user_id' => true,
        'message' => true,
        'type' => true,
        'metadata' => true,
    ];
}

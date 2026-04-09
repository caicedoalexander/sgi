<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class PaymentSchedulingItem extends Entity
{
    protected array $_accessible = [
        'payment_scheduling_id' => true,
        'invoice_id' => true,
        'banking_entity_id' => true,
        'amount' => true,
    ];
}

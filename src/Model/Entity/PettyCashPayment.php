<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class PettyCashPayment extends Entity
{
    protected array $_accessible = [
        'petty_cash_record_id' => true,
        'banking_entity_id' => true,
        'amount' => true,
        'payment_date' => true,
        'authorized' => true,
        'authorized_by' => true,
        'authorized_date' => true,
        'created_by' => true,
    ];
}

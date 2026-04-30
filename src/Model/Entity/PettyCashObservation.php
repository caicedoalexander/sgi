<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class PettyCashObservation extends Entity
{
    protected array $_accessible = [
        'petty_cash_record_id' => true,
        'user_id' => true,
        'message' => true,
        'type' => true,
        'metadata' => true,
    ];
}

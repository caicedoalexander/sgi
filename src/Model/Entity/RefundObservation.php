<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class RefundObservation extends Entity
{
    protected array $_accessible = [
        'refund_id' => true,
        'user_id' => true,
        'type' => true,
        'message' => true,
        'metadata' => true,
        'created' => true,
        'refund' => true,
        'user' => true,
    ];
}

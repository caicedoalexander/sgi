<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class InvoiceObservation extends Entity
{
    protected array $_accessible = [
        'invoice_id' => true,
        'user_id' => true,
        'message' => true,
    ];
}

<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class InvoiceHistory extends Entity
{
    protected array $_accessible = [
        'invoice_id' => true,
        'user_id' => true,
        'field_changed' => true,
        'old_value' => true,
        'new_value' => true,
    ];
}

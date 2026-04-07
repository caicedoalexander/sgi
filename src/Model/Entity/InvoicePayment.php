<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class InvoicePayment extends Entity
{
    protected array $_accessible = [
        'invoice_id' => true,
        'banking_entity_id' => true,
        'amount' => true,
        'payment_date' => true,
        'created_by' => true,
    ];
}

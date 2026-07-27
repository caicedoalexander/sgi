<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class InvoiceApproval extends Entity
{
    protected array $_accessible = [
        'invoice_id' => true,
        'user_id' => true,
        'token_hash' => true,
        'token_expires_at' => true,
        'status' => true,
        'responded_at' => true,
        'observations' => true,
        'ip_address' => true,
        'user_agent' => true,
    ];

    protected array $_hidden = ['token_hash'];
}

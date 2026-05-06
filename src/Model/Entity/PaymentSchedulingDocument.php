<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class PaymentSchedulingDocument extends Entity
{
    protected array $_accessible = [
        'payment_scheduling_id' => true,
        'file_path' => true,
        'file_name' => true,
        'uploaded_by' => true,
    ];
}

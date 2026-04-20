<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class InvoicePaymentAttachment extends Entity
{
    protected array $_accessible = [
        'invoice_payment_id' => true,
        'file_name' => true,
        'file_path' => true,
        'mime_type' => true,
        'file_size' => true,
        'uploaded_by' => true,
        'created' => true,
        'modified' => true,
        'invoice_payment' => true,
        'uploaded_by_user' => true,
    ];
}

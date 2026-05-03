<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class RefundDocument extends Entity
{
    protected array $_accessible = [
        'refund_id' => true,
        'document_type' => true,
        'file_path' => true,
        'file_name' => true,
        'file_size' => true,
        'mime_type' => true,
        'uploaded_by' => true,
        'created' => true,
        'modified' => true,
        'refund' => true,
        'uploaded_by_user' => true,
    ];
}

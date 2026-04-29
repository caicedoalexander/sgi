<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class AdvanceLegalizationSignature extends Entity
{
    protected array $_accessible = [
        'legalization_id' => true,
        'signed_by_user_id' => true,
        'signed_at' => true,
        'document_path' => true,
        'document_name' => true,
        'signature_status' => true,
        'rejection_reason' => true,
    ];
}

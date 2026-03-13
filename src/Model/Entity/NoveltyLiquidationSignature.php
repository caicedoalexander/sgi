<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class NoveltyLiquidationSignature extends Entity
{
    protected array $_accessible = [
        'liquidation_doc_id' => true,
        'signer_type' => true,
        'signature_path' => true,
        'signed_by' => true,
        'approved_at' => true,
    ];
}

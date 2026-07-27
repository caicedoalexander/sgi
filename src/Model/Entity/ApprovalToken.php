<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class ApprovalToken extends Entity
{
    protected array $_accessible = [
        'token_hash' => true,
        'entity_type' => true,
        'entity_id' => true,
        'created_by' => true,
        'expires_at' => true,
        'used_at' => true,
        'action_taken' => true,
        'observations' => true,
        'ip_address' => true,
        'user_agent' => true,
        'approved_by_user_id' => true,
    ];

    protected array $_hidden = ['token_hash'];
}

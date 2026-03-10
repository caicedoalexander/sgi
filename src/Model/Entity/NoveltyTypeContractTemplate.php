<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class NoveltyTypeContractTemplate extends Entity
{
    protected array $_accessible = [
        'novelty_type_id' => true,
        'contract_type' => true,
        'temporary_organization_id' => true,
        'leave_document_template_id' => true,
    ];
}

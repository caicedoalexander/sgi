<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class NoveltyType extends Entity
{
    protected array $_accessible = [
        'name' => true,
        'parent_id' => true,
        'requires_boss_approval' => true,
        'requires_employee_signature_creation' => true,
        'requires_employee_signature_review' => true,
        'show_start_date' => true,
        'show_end_date' => true,
        'show_permission_date' => true,
        'show_schedule_type' => true,
        'uses_custom_name' => true,
        'is_massive' => true,
        'novelty_type_contract_templates' => true,
    ];
}

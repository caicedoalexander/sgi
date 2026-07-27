<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class AssetMovement extends Entity
{
    protected array $_accessible = [
        'asset_id' => true,
        'movement_type' => true,
        'from_employee_id' => true,
        'to_employee_id' => true,
        'from_operation_center_id' => true,
        'to_operation_center_id' => true,
        'reason' => true,
        'movement_date' => true,
        'acta_status' => true,
        'performed_by_user_id' => true,
        'requested_by_phone' => true,
        'requested_by_employee_id' => true,
        'source' => true,
        'created' => false,
    ];
}

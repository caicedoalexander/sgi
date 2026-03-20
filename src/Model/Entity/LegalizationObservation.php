<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class LegalizationObservation extends Entity
{
    protected array $_accessible = [
        'legalization_record_id' => true,
        'user_id' => true,
        'message' => true,
    ];
}

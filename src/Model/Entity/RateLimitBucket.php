<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class RateLimitBucket extends Entity
{
    protected array $_accessible = [
        'bucket_key' => true,
        'window_start' => true,
        'count' => true,
        'created' => true,
        'modified' => true,
    ];
}

<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class NoveltyObservation extends Entity
{
    protected array $_accessible = [
        'novelty_id' => true,
        'liquidation_doc_id' => true,
        'user_id' => true,
        'message' => true,
        'is_read' => true,
    ];
}

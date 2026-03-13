<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class NoveltyLiquidationDoc extends Entity
{
    protected array $_accessible = [
        'liquidation_number' => true,
        'period' => true,
        'pipeline_status' => true,
        'document_date' => true,
        'performed_by' => true,
        'passes_for_payment' => true,
        'payment_status' => true,
        'payment_date' => true,
        'created_by' => true,
    ];
}

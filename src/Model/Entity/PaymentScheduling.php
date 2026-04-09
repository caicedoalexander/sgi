<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\PaymentSchedulingConstants;
use Cake\ORM\Entity;

class PaymentScheduling extends Entity
{
    protected array $_accessible = [
        'code' => true,
        'title' => true,
        'pipeline_status' => true,
        'created_by' => true,
    ];

    public function isPagada(): bool
    {
        return ($this->pipeline_status ?? '') === PaymentSchedulingConstants::STATUS_PAGADA;
    }
}

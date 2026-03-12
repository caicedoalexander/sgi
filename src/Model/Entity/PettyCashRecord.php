<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\PettyCashConstants;
use Cake\ORM\Entity;

class PettyCashRecord extends Entity
{
    protected array $_accessible = [
        'code' => true,
        'status' => true,
        'total_amount' => true,
        'accrued' => true,
        'accrual_date' => true,
        'ready_for_payment' => true,
        'payment_status' => true,
        'payment_date' => true,
        'notes' => true,
        'created_by' => true,
    ];

    public function isAgrupacion(): bool
    {
        return ($this->status ?? '') === PettyCashConstants::STATUS_AGRUPACION;
    }

    public function isContabilidad(): bool
    {
        return ($this->status ?? '') === PettyCashConstants::STATUS_CONTABILIDAD;
    }

    public function isTesoreria(): bool
    {
        return ($this->status ?? '') === PettyCashConstants::STATUS_TESORERIA;
    }

    public function isPagado(): bool
    {
        return ($this->status ?? '') === PettyCashConstants::STATUS_PAGADO;
    }
}

<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\PettyCashConstants;
use Cake\ORM\Entity;

class PettyCashRecord extends Entity
{
    protected array $_accessible = [
        'code' => false,
        'operation_center_id' => true,
        'status' => true,
        'total_amount' => true,
        'accrued' => true,
        'accrual_date' => true,
        'ready_for_payment' => true,
        'payment_status' => true,
        'payment_date' => true,
        'banking_entity_id' => true,
        'payment_amount' => true,
        'payment_created_by' => true,
        'payment_authorized_by' => true,
        'payment_authorized_date' => true,
        'payment_rejection_reason' => true,
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

    public function isAutPago(): bool
    {
        return ($this->status ?? '') === PettyCashConstants::STATUS_AUT_PAGO;
    }

    public function isPagado(): bool
    {
        return ($this->status ?? '') === PettyCashConstants::STATUS_PAGADO;
    }
}

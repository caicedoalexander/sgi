<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\RefundConstants;
use Cake\ORM\Entity;

class Refund extends Entity
{
    protected array $_accessible = [
        'code' => true,
        'status' => true,
        'total_amount' => true,
        'beneficiary_type' => true,
        'beneficiary_employee_id' => true,
        'beneficiary_provider_id' => true,
        'accrued' => true,
        'accrual_date' => true,
        'ready_for_payment' => true,
        'banking_entity_id' => true,
        'payment_amount' => true,
        'payment_date' => true,
        'payment_created_by' => true,
        'payment_authorized_by' => true,
        'payment_authorized_date' => true,
        'payment_status' => true,
        'payment_rejection_reason' => true,
        'created_by' => true,
        'created' => true,
        'modified' => true,
        'beneficiary_employee' => true,
        'beneficiary_provider' => true,
        'banking_entity' => true,
        'created_by_user' => true,
        'invoices' => true,
        'refund_observations' => true,
    ];

    public function isAgrupacion(): bool
    {
        return $this->status === RefundConstants::STATUS_AGRUPACION;
    }

    public function isContabilidad(): bool
    {
        return $this->status === RefundConstants::STATUS_CONTABILIDAD;
    }

    public function isTesoreria(): bool
    {
        return $this->status === RefundConstants::STATUS_TESORERIA;
    }

    public function isPagado(): bool
    {
        return $this->status === RefundConstants::STATUS_PAGADO;
    }

    public function getBeneficiaryName(): ?string
    {
        if ($this->beneficiary_type === RefundConstants::BENEFICIARY_TYPE_EMPLOYEE) {
            $emp = $this->beneficiary_employee ?? null;
            if ($emp === null) {
                return null;
            }

            return trim(
                ($emp->first_name ?? '')
                . ' ' . ($emp->last_name1 ?? '')
                . ' ' . ($emp->last_name2 ?? ''),
            );
        }

        if ($this->beneficiary_type === RefundConstants::BENEFICIARY_TYPE_PROVIDER) {
            $prov = $this->beneficiary_provider ?? null;

            return $prov->name ?? null;
        }

        return null;
    }
}

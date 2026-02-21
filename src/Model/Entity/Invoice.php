<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class Invoice extends Entity
{
    protected array $_accessible = [
        'invoice_number' => true,
        'registration_date' => true,
        'issue_date' => true,
        'due_date' => true,
        'document_type' => true,
        'purchase_order' => true,
        'provider_id' => true,
        'operation_center_id' => true,
        'detail' => true,
        'amount' => true,
        'expense_type_id' => true,
        'cost_center_id' => true,
        'confirmed_by' => true,
        'approver_id' => true,
        'area_approval' => true,
        'area_approval_date' => true,
        'dian_validation' => true,
        'accrued' => true,
        'accrual_date' => true,
        'ready_for_payment' => true,
        'payment_status' => true,
        'payment_date' => true,
        'pipeline_status' => true,
        'registered_by' => true,
    ];
}

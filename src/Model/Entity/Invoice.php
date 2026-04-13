<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\InvoiceConstants;
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
        'is_equivalent_document' => true,
        'equivalent_holder_type' => true,
        'employee_id' => true,
        'manual_document_number' => true,
        'operation_center_id' => true,
        'detail' => true,
        'amount' => true,
        'expense_type_id' => true,
        'cost_center_id' => true,
        'confirmed_by' => true,
        'approver_id' => true,
        'area_approval' => true,
        'area_approval_date' => false,
        'dian_validation' => true,
        'accrued' => true,
        'accrual_date' => true,
        'ready_for_payment' => true,
        'payment_status' => true,
        'full_payment_date' => true,
        'pipeline_status' => true,
        'registered_by' => true,
        'petty_cash_record_id' => true,
        'legalization_record_id' => true,
    ];

    public function isInPettyCash(): bool
    {
        return !empty($this->petty_cash_record_id);
    }

    public function isRejected(): bool
    {
        return ($this->area_approval ?? '') === InvoiceConstants::APPROVAL_REJECTED;
    }

    public function isApproved(): bool
    {
        return ($this->area_approval ?? '') === InvoiceConstants::APPROVAL_APPROVED;
    }

    public function isPaid(): bool
    {
        return ($this->pipeline_status ?? '') === InvoiceConstants::STATUS_PAGADA;
    }

    /**
     * Check if the invoice can advance to the given status.
     *
     * @param string $nextStatus Target pipeline status.
     * @return bool
     */
    public function canAdvanceTo(string $nextStatus): bool
    {
        if ($this->isRejected()) {
            return false;
        }

        $statuses = InvoiceConstants::PIPELINE_STATUSES;
        $currentIndex = array_search($this->pipeline_status, $statuses);
        $nextIndex = array_search($nextStatus, $statuses);

        if ($currentIndex === false || $nextIndex === false) {
            return false;
        }

        return $nextIndex === $currentIndex + 1;
    }

    /**
     * Check if the invoice is past its due date and not yet paid.
     *
     * @return bool
     */
    public function isOverdue(): bool
    {
        if ($this->isPaid()) {
            return false;
        }

        return $this->due_date && $this->due_date->isPast();
    }
}

<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\AdvanceConstants;
use Cake\ORM\Entity;

class AdvanceLegalization extends Entity
{
    protected array $_accessible = [
        'advance_invoice_id' => true,
        'status' => true,
        'case_type' => true,
        'shortage_amount' => true,
        'surplus_amount' => true,
        'shortage_received_at' => true,
        'shortage_receipt_number' => true,
        'shortage_receipt_path' => true,
        'surplus_payment_id' => true,
        'legalized_at' => true,
        'created_by' => true,
        'updated_by' => true,
        'advance_invoice' => true,
        'linked_invoices' => true,
        'advance_legalization_signatures' => true,
    ];

    /**
     * @return bool true when status is `legalizada`
     */
    public function isLegalized(): bool
    {
        return $this->status === AdvanceConstants::STATUS_LEGALIZADA;
    }

    /**
     * @return bool true when status is `validacion`
     */
    public function isInValidacion(): bool
    {
        return $this->status === AdvanceConstants::STATUS_VALIDACION;
    }
}

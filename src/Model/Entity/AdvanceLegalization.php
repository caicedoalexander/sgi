<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\AdvanceConstants;
use Cake\ORM\Entity;

class AdvanceLegalization extends Entity
{
    /**
     * Pipeline-controlled fields are non-mass-assignable. Solo
     * AdvanceLegalizationService los muta vía property assignment directo,
     * que bypassa _accessible. Esto evita que un patchEntity con datos del
     * cliente pueda mover el estado, declarar caso, o falsificar montos
     * (audit MI-002).
     */
    protected array $_accessible = [
        'advance_invoice_id' => true,
        'status' => false,
        'case_type' => false,
        'shortage_amount' => false,
        'surplus_amount' => false,
        'shortage_received_at' => false,
        'shortage_receipt_number' => false,
        'shortage_receipt_path' => false,
        'surplus_payment_id' => false,
        'legalized_at' => false,
        'created_by' => true,
        'updated_by' => false,
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

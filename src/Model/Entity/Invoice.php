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
        'area_approval' => false,
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
        'refund_id' => true,
        'advance_id' => true,
    ];

    /**
     * Asigna el resultado de aprobación de área y, cuando es aprobada o
     * rechazada, fija la fecha de aprobación al día de hoy.
     *
     * @param string $approval Resultado de aprobación de área a asignar.
     * @return void
     */
    public function setApprovalResult(string $approval): void
    {
        $this->area_approval = $approval;
        if (in_array($approval, [InvoiceConstants::APPROVAL_APPROVED, InvoiceConstants::APPROVAL_REJECTED], true)) {
            $this->area_approval_date = date('Y-m-d');
        }
    }

    /** @return bool true si la factura está vinculada a una caja menor. */
    public function isInPettyCash(): bool
    {
        return !empty($this->petty_cash_record_id);
    }

    /** @return bool true si el área rechazó la factura. */
    public function isRejected(): bool
    {
        return ($this->area_approval ?? '') === InvoiceConstants::APPROVAL_REJECTED;
    }

    /** @return bool true si el área aprobó la factura. */
    public function isApproved(): bool
    {
        return ($this->area_approval ?? '') === InvoiceConstants::APPROVAL_APPROVED;
    }

    /** @return bool true si la factura está en estado pagada. */
    public function isPaid(): bool
    {
        return ($this->pipeline_status ?? '') === InvoiceConstants::STATUS_PAGADA;
    }

    /**
     * True si la factura usa la vista de legalización (pipeline reducido de 3 pasos,
     * secciones de tesorería/pago ocultas): una Legalización, o un Recibo de Caja
     * vinculado a un anticipo. Fuente única del criterio — consumida por
     * InvoicePresentation y los banners de vista/edición.
     */
    public function usesLegalizationView(): bool
    {
        return $this->document_type === InvoiceConstants::DOCTYPE_LEGALIZACION
            || ($this->document_type === InvoiceConstants::DOCTYPE_RECIBO_CAJA
                && $this->advance_id !== null);
    }

    /** @return bool true si la factura está en un estado terminal (pagada o legalizada). */
    public function isInFinalState(): bool
    {
        return in_array($this->pipeline_status ?? '', [
            InvoiceConstants::STATUS_PAGADA,
            InvoiceConstants::STATUS_LEGALIZADA,
        ], true);
    }

    /** @return bool true si la factura no está en un estado terminal. */
    public function isEditable(): bool
    {
        return !$this->isInFinalState();
    }

    /** @return bool true si la factura sigue en Aprobación y el área aún no la aprobó. */
    public function requiresApproval(): bool
    {
        return ($this->pipeline_status ?? '') === InvoiceConstants::STATUS_APROBACION
            && ($this->area_approval ?? '') !== InvoiceConstants::APPROVAL_APPROVED;
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

    // -----------------------------------------------------------------
    // State-machine predicates (permissions-unification 2026-05-12, PR1).
    //
    // Encapsulan solo estado del agregado — sin combinar con rol del
    // usuario, que es responsabilidad de InvoiceActionPolicy. La policy
    // compone _canOperate($roleId, $step) && $invoice->canX(). Única fuente
    // de verdad de la regla de estado para el verbo correspondiente.
    // -----------------------------------------------------------------

    /** @return bool true cuando Tesorería puede registrar un nuevo pago. */
    public function canRegisterPayment(): bool
    {
        return !$this->isRejected()
            && !$this->isPaid()
            && ($this->pipeline_status ?? '') === InvoiceConstants::STATUS_TESORERIA;
    }

    /** @return bool true cuando Contador puede autorizar un pago pendiente. */
    public function canAuthorizePayment(): bool
    {
        return !$this->isRejected()
            && !$this->isPaid()
            && ($this->pipeline_status ?? '') === InvoiceConstants::STATUS_AUTORIZACION_PAGO;
    }

    /** @return bool true cuando Tesorería puede confirmar la ejecución del pago. */
    public function canConfirmPayment(): bool
    {
        return !$this->isRejected()
            && !$this->isPaid()
            && ($this->pipeline_status ?? '') === InvoiceConstants::STATUS_VERIFICACION_PAGO;
    }

    /** @return bool alias de canRegisterPayment para el verbo "add" del sub-controller. */
    public function canAddPayment(): bool
    {
        return $this->canRegisterPayment();
    }

    /** @return bool alias de canRegisterPayment para el verbo "edit" (mismo estado). */
    public function canEditPayment(): bool
    {
        return $this->canRegisterPayment();
    }

    /** @return bool alias de canAuthorizePayment para el verbo "reject" (mismo estado). */
    public function canRejectPayment(): bool
    {
        return $this->canAuthorizePayment();
    }

    /** @return bool alias de canRegisterPayment para el verbo "delete" (mismo estado). */
    public function canDeletePayment(): bool
    {
        return $this->canRegisterPayment();
    }
}

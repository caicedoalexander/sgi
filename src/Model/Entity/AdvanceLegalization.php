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

    // -----------------------------------------------------------------
    // State-machine predicates (audit MA-010)
    //
    // Cada predicate encapsula la regla de estado pura — sin combinar con
    // el rol del usuario, que es responsabilidad del
    // AdvanceLegalizationActionPolicy. El service usa estos predicates
    // como guard al inicio de cada transición; el policy los compone con
    // el rol para autorizar el HTTP entry point. Una sola fuente de
    // verdad de la regla de estado.
    // -----------------------------------------------------------------

    /** @return bool true cuando el estado permite vincular facturas. */
    public function canLinkInvoices(): bool
    {
        return $this->status === AdvanceConstants::STATUS_VALIDACION;
    }

    /** @return bool true cuando el estado permite desvincular una factura. */
    public function canUnlinkInvoice(): bool
    {
        return $this->status === AdvanceConstants::STATUS_VALIDACION;
    }

    /** @return bool true cuando el estado permite avanzar a Revisión y Firmas. */
    public function canMoveToRevision(): bool
    {
        return $this->status === AdvanceConstants::STATUS_VALIDACION;
    }

    /** @return bool true cuando el estado permite (re)subir la relación de facturas. */
    public function canUploadRelationDocument(): bool
    {
        return in_array(
            $this->status,
            [AdvanceConstants::STATUS_VALIDACION, AdvanceConstants::STATUS_REVISION_FIRMAS],
            true,
        );
    }

    /** @return bool true cuando el estado permite marcar la firma como completa. */
    public function canMarkSigned(): bool
    {
        return $this->status === AdvanceConstants::STATUS_REVISION_FIRMAS;
    }

    /** @return bool true cuando el estado permite devolver a Validación con motivo. */
    public function canReturnToValidacion(): bool
    {
        return $this->status === AdvanceConstants::STATUS_REVISION_FIRMAS;
    }

    /** @return bool true cuando se puede declarar caso exacto (sin caso previo). */
    public function canMarkExact(): bool
    {
        return $this->status === AdvanceConstants::STATUS_CONTABILIDAD
            && $this->case_type === null;
    }

    /** @return bool true cuando se puede declarar faltante (sin caso previo). */
    public function canRegisterShortage(): bool
    {
        return $this->status === AdvanceConstants::STATUS_CONTABILIDAD
            && $this->case_type === null;
    }

    /** @return bool true cuando se puede declarar sobrante (sin caso previo). */
    public function canRegisterSurplus(): bool
    {
        return $this->status === AdvanceConstants::STATUS_CONTABILIDAD
            && $this->case_type === null;
    }

    /** @return bool true cuando Tesorería puede confirmar consignación de faltante. */
    public function canConfirmShortage(): bool
    {
        return $this->status === AdvanceConstants::STATUS_TESORERIA
            && $this->case_type === AdvanceConstants::CASE_FALTANTE;
    }

    /** @return bool true cuando Tesorería puede registrar reintegro (sin pago previo). */
    public function canRegisterRefund(): bool
    {
        return $this->status === AdvanceConstants::STATUS_TESORERIA
            && $this->case_type === AdvanceConstants::CASE_SOBRANTE
            && empty($this->surplus_payment_id);
    }

    public function isVerificacionPago(): bool
    {
        return ($this->status ?? '') === AdvanceConstants::STATUS_VERIFICACION_PAGO;
    }

    /**
     * @return bool true cuando Tesorería puede confirmar la ejecución efectiva
     *              del reintegro autorizado (`verificacion_pago` → `legalizada`).
     */
    public function canConfirmRefundPayment(): bool
    {
        return $this->status === AdvanceConstants::STATUS_VERIFICACION_PAGO
            && $this->case_type === AdvanceConstants::CASE_SOBRANTE;
    }
}

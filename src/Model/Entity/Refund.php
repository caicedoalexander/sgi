<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\RefundConstants;
use Cake\ORM\Entity;

class Refund extends Entity
{
    /**
     * Solo se permite mass-assignment de campos de formulario.
     * Estado de pipeline (`status`), totales calculados, autorías y campos
     * de pago se asignan únicamente desde los servicios.
     */
    protected array $_accessible = [
        'code' => false,
        'operation_center_id' => true,
        'status' => false,
        'total_amount' => false,
        'beneficiary_type' => true,
        'beneficiary_employee_id' => true,
        'beneficiary_provider_id' => true,
        'accrued' => true,
        'accrual_date' => true,
        'ready_for_payment' => true,
        'banking_entity_id' => false,
        'payment_amount' => false,
        'payment_date' => false,
        'payment_created_by' => false,
        'payment_authorized_by' => false,
        'payment_authorized_date' => false,
        'payment_status' => false,
        'payment_rejection_reason' => false,
        'created_by' => false,
        'created' => false,
        'modified' => false,
        'beneficiary_employee' => false,
        'beneficiary_provider' => false,
        'banking_entity' => false,
        'created_by_user' => false,
        'invoices' => false,
        'refund_observations' => false,
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

    public function isPagada(): bool
    {
        return $this->status === RefundConstants::STATUS_PAGADA;
    }

    /**
     * True si el reintegro está en estado Autorización de Pago.
     */
    public function isAutorizacionPago(): bool
    {
        return $this->status === RefundConstants::STATUS_AUTORIZACION_PAGO;
    }

    public function isVerificacionPago(): bool
    {
        return $this->status === RefundConstants::STATUS_VERIFICACION_PAGO;
    }

    /**
     * True si el reintegro está en alguna fase posterior a Tesorería donde ya
     * se manipulan datos de pago. Útil para gates de UI/serv. que deben
     * comportarse igual en `autorizacion_pago` y `pagada`.
     */
    public function isInPaymentPhase(): bool
    {
        return $this->isTesoreria() || $this->isAutorizacionPago() || $this->isVerificacionPago() || $this->isPagada();
    }

    /**
     * Solo se permite eliminar reintegros en estado Agrupación. En cualquier
     * otra etapa hay datos del pipeline (asociaciones, audits) que harían
     * destructiva la eliminación.
     */
    public function canBeDeleted(): bool
    {
        return $this->isAgrupacion();
    }

    /**
     * True si existe una transición forward "automática" desde el estado
     * actual. Excluye `pagada` (terminal) y `autorizacion_pago` (transición
     * controlada por register/authorize de pagos, no por el botón de avance).
     */
    public function canAdvancePipeline(): bool
    {
        if ($this->isPagada() || $this->isAutorizacionPago() || $this->isVerificacionPago()) {
            return false;
        }

        return isset(RefundConstants::TRANSITIONS[$this->status]);
    }

    // -----------------------------------------------------------------
    // State-machine predicates (permissions-unification 2026-05-12, PR1).
    //
    // Encapsulan solo estado del agregado — la composición con rol vive
    // en RefundActionPolicy: _canOperate($roleId, $step) && $refund->canX().
    // -----------------------------------------------------------------

    /** @return bool true cuando Tesorería puede registrar un nuevo pago. */
    public function canRegisterPayment(): bool
    {
        return $this->isTesoreria();
    }

    /** @return bool true cuando Contador puede autorizar un pago pendiente. */
    public function canAuthorizePayment(): bool
    {
        return $this->isAutorizacionPago();
    }

    /** @return bool true cuando Tesorería puede confirmar la ejecución del pago. */
    public function canConfirmPayment(): bool
    {
        return $this->isVerificacionPago();
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

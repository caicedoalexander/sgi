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

    /** @return bool true si la caja menor está en estado Agrupación. */
    public function isAgrupacion(): bool
    {
        return ($this->status ?? '') === PettyCashConstants::STATUS_AGRUPACION;
    }

    /** @return bool true si la caja menor está en estado Contabilidad. */
    public function isContabilidad(): bool
    {
        return ($this->status ?? '') === PettyCashConstants::STATUS_CONTABILIDAD;
    }

    /** @return bool true si la caja menor está en estado Tesorería. */
    public function isTesoreria(): bool
    {
        return ($this->status ?? '') === PettyCashConstants::STATUS_TESORERIA;
    }

    /** @return bool true si la caja menor está en estado Autorización de pago. */
    public function isAutorizacionPago(): bool
    {
        return ($this->status ?? '') === PettyCashConstants::STATUS_AUTORIZACION_PAGO;
    }

    /** @return bool true si la caja menor está en estado Verificación de pago. */
    public function isVerificacionPago(): bool
    {
        return ($this->status ?? '') === PettyCashConstants::STATUS_VERIFICACION_PAGO;
    }

    /** @return bool true si la caja menor está en estado pagada. */
    public function isPagada(): bool
    {
        return ($this->status ?? '') === PettyCashConstants::STATUS_PAGADA;
    }

    // -----------------------------------------------------------------
    // State-machine predicates (permissions-unification 2026-05-12, PR1).
    //
    // Encapsulan solo estado del agregado — la composición con rol vive
    // en PettyCashActionPolicy: _canOperate($roleId, $step) && $record->canX().
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
}

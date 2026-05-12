<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\Invoice;
use App\ValueObject\UserContext;

/**
 * Audit PA-011 — espejo de `AdvanceLegalizationActionPolicy` para el pipeline
 * de facturas. Combina la dimensión rol×paso (vía `AuthorizationFacade`) con
 * el estado del agregado (`Invoice::isRejected()`, `isPaid()`) y el step
 * actual (preserva el comportamiento previo de `InvoicesController` donde
 * `canRegisterPayment`/`canAuthorizePayment` gateaban además por status).
 */
final class InvoiceActionPolicy
{
    /**
     * @param \App\Authorization\AuthorizationFacade $auth Authorization facade.
     */
    public function __construct(
        private AuthorizationFacade $auth,
    ) {
    }

    /**
     * @param \App\Model\Entity\Invoice $invoice Factura.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canRegisterPayment(Invoice $invoice, int $roleId): bool
    {
        return $invoice->canRegisterPayment()
            && $this->_canOperate($roleId, InvoiceConstants::STATUS_TESORERIA);
    }

    /**
     * @param \App\Model\Entity\Invoice $invoice Factura.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canAuthorizePayment(Invoice $invoice, int $roleId): bool
    {
        return $invoice->canAuthorizePayment()
            && $this->_canOperate($roleId, InvoiceConstants::STATUS_AUTORIZACION_PAGO);
    }

    /**
     * @param \App\Model\Entity\Invoice $invoice Factura.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canConfirmPayment(Invoice $invoice, int $roleId): bool
    {
        return $invoice->canConfirmPayment()
            && $this->_canOperate($roleId, InvoiceConstants::STATUS_VERIFICACION_PAGO);
    }

    /**
     * @param \App\Model\Entity\Invoice $invoice Factura.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canAddPayment(Invoice $invoice, int $roleId): bool
    {
        return $invoice->canAddPayment()
            && $this->_canOperate($roleId, InvoiceConstants::STATUS_TESORERIA);
    }

    /**
     * @param \App\Model\Entity\Invoice $invoice Factura.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canEditPayment(Invoice $invoice, int $roleId): bool
    {
        return $invoice->canEditPayment()
            && $this->_canOperate($roleId, InvoiceConstants::STATUS_TESORERIA);
    }

    /**
     * @param \App\Model\Entity\Invoice $invoice Factura.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canRejectPayment(Invoice $invoice, int $roleId): bool
    {
        return $invoice->canRejectPayment()
            && $this->_canOperate($roleId, InvoiceConstants::STATUS_AUTORIZACION_PAGO);
    }

    /**
     * @param \App\Model\Entity\Invoice $invoice Factura.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canDeletePayment(Invoice $invoice, int $roleId): bool
    {
        return $invoice->canDeletePayment()
            && $this->_canOperate($roleId, InvoiceConstants::STATUS_TESORERIA);
    }

    /**
     * @param int $roleId Role ID.
     * @param string $step Pipeline step.
     * @return bool
     */
    private function _canOperate(int $roleId, string $step): bool
    {
        return $this->auth->canOperate(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_INVOICES,
            $step,
        );
    }
}

<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\PipelineAction;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Service\InvoicePaymentService;
use App\Service\Pipeline\Invoice\Policy\InvoiceActionPolicy;

class InvoicePaymentsController extends AppController
{
    private InvoicePaymentService $paymentService;
    private InvoiceActionPolicy $actionPolicy;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->paymentService = $container->get(InvoicePaymentService::class);
        $this->actionPolicy = $container->get(InvoiceActionPolicy::class);
    }

    /**
     * Obtiene el usuario autenticado de la sesión.
     *
     * @return object
     */
    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    /**
     * Obtiene el nombre del rol del usuario autenticado.
     *
     * @return string
     */
    private function _getRoleName(): string
    {
        return $this->_getUserRoleName($this->_getCurrentUser());
    }

    /**
     * Obtiene el ID del rol del usuario autenticado.
     *
     * @return int
     */
    private function _getRoleId(): int
    {
        return (int)$this->_getCurrentUser()->role_id;
    }

    /**
     * @param string|int|null $invoiceId
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES, step: InvoiceConstants::STATUS_TESORERIA)]
    public function addPayment(string|int|null $invoiceId = null)
    {
        $this->request->allowMethod(['post']);
        $invoicesTable = $this->fetchTable('Invoices');
        $invoice = $invoicesTable->get($invoiceId);

        if (!$this->actionPolicy->canAddPayment($invoice, $this->_getRoleId())) {
            $this->Flash->error('No tiene permisos para registrar pagos en este estado.');

            return $this->_redirectForInvoice($invoice, 'edit', $invoiceId);
        }

        $data = $this->request->getData();

        if (!empty($data['full_payment'])) {
            $data['amount'] = $this->paymentService->getPendingBalance((int)$invoiceId);
        }

        $result = $this->paymentService->registerPayment(
            (int)$invoiceId,
            $data,
            (int)$this->_getCurrentUser()->id,
        );

        if ($result->success) {
            // registerPayment siempre avanza la factura a autorizacion_pago.
            $this->Flash->success($result->data);

            return $this->_redirectForInvoice($invoice, 'index');
        }

        $this->Flash->error(implode(' ', (array)$result->errors));

        return $this->_redirectForInvoice($invoice, 'edit', $invoiceId);
    }

    /**
     * @param string|int|null $invoiceId
     * @param string|int|null $paymentId
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES, step: InvoiceConstants::STATUS_TESORERIA)]
    public function editPayment(string|int|null $invoiceId = null, string|int|null $paymentId = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->fetchTable('Invoices')->get($invoiceId);

        if (!$this->actionPolicy->canEditPayment($invoice, $this->_getRoleId())) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->_redirectForInvoice((int)$invoiceId, 'edit', $invoiceId);
        }

        $data = array_intersect_key(
            $this->request->getData(),
            array_flip(['banking_entity_id', 'amount', 'payment_date']),
        );
        $reason = (string)$this->request->getData('reason');

        $result = $this->paymentService->editPayment(
            (int)$paymentId,
            $data,
            $reason,
            (int)$this->_getCurrentUser()->id,
        );

        if ($result->success) {
            $this->Flash->success($result->data);
        } else {
            $this->Flash->error(implode(' ', (array)$result->errors));
        }

        return $this->_redirectForInvoice((int)$invoiceId, 'edit', $invoiceId);
    }

    /**
     * @param string|int|null $invoiceId
     * @param string|int|null $paymentId
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES, step: InvoiceConstants::STATUS_AUTORIZACION_PAGO)]
    public function authorizePayment(string|int|null $invoiceId = null, string|int|null $paymentId = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->fetchTable('Invoices')->get($invoiceId);

        if (!$this->actionPolicy->canAuthorizePayment($invoice, $this->_getRoleId())) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->_redirectForInvoice((int)$invoiceId, 'edit', $invoiceId);
        }

        $result = $this->paymentService->authorizePayment((int)$paymentId, (int)$this->_getCurrentUser()->id);

        if ($result['success']) {
            if ($result['newPipelineStatus'] === InvoiceConstants::STATUS_VERIFICACION_PAGO) {
                $this->Flash->success('Pago autorizado. Factura en verificación de pago — Tesorería debe confirmar la ejecución.');
            } else {
                $this->Flash->success('Pago autorizado. Factura devuelta a Tesorería (Pago Parcial).');
            }

            // Cambio de estado (avance o devolución) → index; sin cambio
            // (autorización de reintegro de anticipo) → edit.
            if ($result['newPipelineStatus'] !== InvoiceConstants::STATUS_AUTORIZACION_PAGO) {
                return $this->_redirectForInvoice((int)$invoiceId, 'index');
            }

            return $this->_redirectForInvoice((int)$invoiceId, 'edit', $invoiceId);
        }

        $this->Flash->error('No se pudo autorizar el pago.');

        return $this->_redirectForInvoice((int)$invoiceId, 'edit', $invoiceId);
    }

    /**
     * Confirma que el pago de una factura ya fue ejecutado por Tesorería.
     * Avanza de verificacion_pago → pagada.
     *
     * @param string|int|null $invoiceId
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES, step: InvoiceConstants::STATUS_VERIFICACION_PAGO)]
    public function confirmPayment(string|int|null $invoiceId = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->fetchTable('Invoices')->get($invoiceId);

        if (!$this->actionPolicy->canConfirmPayment($invoice, $this->_getRoleId())) {
            $this->Flash->error('No tiene permisos para confirmar este pago.');

            // Sin permiso: la factura sigue en verificacion_pago (no terminal),
            // se queda en edit como el resto de payment-actions sin permiso.
            return $this->_redirectForInvoice((int)$invoiceId, 'edit', $invoiceId);
        }

        $result = $this->paymentService->confirmPayment(
            (int)$invoiceId,
            (int)$this->_getCurrentUser()->id,
        );

        if ($result->success) {
            $this->Flash->success($result->data ?? 'Pago confirmado.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo confirmar el pago.');
        }

        return $this->_redirectForInvoice((int)$invoiceId, 'view', $invoiceId);
    }

    /**
     * @param string|int|null $invoiceId
     * @param string|int|null $paymentId
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES, step: InvoiceConstants::STATUS_AUTORIZACION_PAGO)]
    public function rejectPayment(string|int|null $invoiceId = null, string|int|null $paymentId = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->fetchTable('Invoices')->get($invoiceId);

        if (!$this->actionPolicy->canRejectPayment($invoice, $this->_getRoleId())) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->_redirectForInvoice((int)$invoiceId, 'edit', $invoiceId);
        }

        $reason = (string)$this->request->getData('reason');
        $result = $this->paymentService->rejectPayment(
            (int)$paymentId,
            (int)$this->_getCurrentUser()->id,
            $reason,
        );

        if ($result->success) {
            $this->Flash->success($result->data);
        } else {
            $this->Flash->error(implode(' ', (array)$result->errors));
        }

        return $this->_redirectForInvoice((int)$invoiceId, 'edit', $invoiceId);
    }

    /**
     * @param string|int|null $invoiceId
     * @param string|int|null $paymentId
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES, step: InvoiceConstants::STATUS_TESORERIA)]
    public function deletePayment(string|int|null $invoiceId = null, string|int|null $paymentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $invoicesTable = $this->fetchTable('Invoices');
        $invoice = $invoicesTable->get($invoiceId);

        if (!$this->actionPolicy->canDeletePayment($invoice, $this->_getRoleId())) {
            $this->Flash->error('No tiene permisos para eliminar pagos en este estado.');

            return $this->_redirectForInvoice($invoice, 'edit', $invoiceId);
        }

        $paymentsTable = $this->fetchTable('InvoicePayments');
        $payment = $paymentsTable->get($paymentId);

        if ($paymentsTable->delete($payment)) {
            $this->Flash->success('Pago eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el pago.');
        }

        return $this->_redirectForInvoice($invoice, 'edit', $invoiceId);
    }
}

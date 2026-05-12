<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\PipelineAction;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Service\InvoicePaymentService;

class InvoicePaymentsController extends AppController
{
    private InvoicePaymentService $paymentService;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->paymentService = $this->getContainer()->get(InvoicePaymentService::class);
    }

    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    private function _getRoleName(): string
    {
        return $this->_getUserRoleName($this->_getCurrentUser());
    }

    private function _getRoleId(): int
    {
        return (int)$this->_getCurrentUser()->role_id;
    }

    /**
     * @param string|int|null $invoiceId
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES, step: InvoiceConstants::STATUS_TESORERIA)]
    public function addPayment($invoiceId = null)
    {
        $this->request->allowMethod(['post']);
        $invoicesTable = $this->fetchTable('Invoices');
        $invoice = $invoicesTable->get($invoiceId);

        $roleId = $this->_getRoleId();
        $currentStatus = $invoice->pipeline_status;

        if (
            !(
                $this->authFacade->canOperate(
                    $this->_userContext(),
                    PipelineStepConstants::PIPELINE_INVOICES,
                    InvoiceConstants::STATUS_TESORERIA,
                )
                && $currentStatus === InvoiceConstants::STATUS_TESORERIA
            )
        ) {
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
            $this->Flash->success($result->data);
        } else {
            $this->Flash->error(implode(' ', (array)$result->errors));
        }

        return $this->_redirectForInvoice($invoice, 'edit', $invoiceId);
    }

    /**
     * @param string|int|null $invoiceId
     * @param string|int|null $paymentId
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES, step: InvoiceConstants::STATUS_TESORERIA)]
    public function editPayment($invoiceId = null, $paymentId = null)
    {
        $this->request->allowMethod(['post']);
        $roleId = $this->_getRoleId();

        if (
            !$this->authFacade->canOperate(
                $this->_userContext(),
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_TESORERIA,
            )
        ) {
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
    public function authorizePayment($invoiceId = null, $paymentId = null)
    {
        $this->request->allowMethod(['post']);
        $roleId = $this->_getRoleId();

        if (
            !$this->authFacade->canOperate(
                $this->_userContext(),
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_AUTORIZACION_PAGO,
            )
        ) {
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
        } else {
            $this->Flash->error('No se pudo autorizar el pago.');
        }

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
    public function confirmPayment($invoiceId = null)
    {
        $this->request->allowMethod(['post']);
        $roleId = $this->_getRoleId();

        if (
            !$this->authFacade->canOperate(
                $this->_userContext(),
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_VERIFICACION_PAGO,
            )
        ) {
            $this->Flash->error('No tiene permisos para confirmar este pago.');

            return $this->_redirectForInvoice((int)$invoiceId, 'view', $invoiceId);
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
    public function rejectPayment($invoiceId = null, $paymentId = null)
    {
        $this->request->allowMethod(['post']);
        $roleId = $this->_getRoleId();

        if (
            !$this->authFacade->canOperate(
                $this->_userContext(),
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_AUTORIZACION_PAGO,
            )
        ) {
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
    public function deletePayment($invoiceId = null, $paymentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $invoicesTable = $this->fetchTable('Invoices');
        $invoice = $invoicesTable->get($invoiceId);

        $roleId = $this->_getRoleId();
        $currentStatus = $invoice->pipeline_status;

        if (
            !(
                $this->authFacade->canOperate(
                    $this->_userContext(),
                    PipelineStepConstants::PIPELINE_INVOICES,
                    InvoiceConstants::STATUS_TESORERIA,
                )
                && $currentStatus === InvoiceConstants::STATUS_TESORERIA
            )
        ) {
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

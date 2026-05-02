<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\InvoicePaymentService;

class InvoicePaymentsController extends AppController
{
    private InvoicePaymentService $paymentService;

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

    public function addPayment($invoiceId = null)
    {
        $this->request->allowMethod(['post']);
        $invoicesTable = $this->fetchTable('Invoices');
        $invoice = $invoicesTable->get($invoiceId);

        $roleName = $this->_getRoleName();
        $currentStatus = $invoice->pipeline_status;

        if (
            $roleName !== RoleConstants::ADMIN && (
            $roleName !== RoleConstants::TESORERIA ||
            $currentStatus !== InvoiceConstants::STATUS_TESORERIA
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
     * Edit a pending payment. Requires non-empty reason; records field changes
     * in invoice_histories and persists the reason as an invoice observation.
     */
    public function editPayment($invoiceId = null, $paymentId = null)
    {
        $this->request->allowMethod(['post']);
        $roleName = $this->_getRoleName();

        if ($roleName !== RoleConstants::TESORERIA && $roleName !== RoleConstants::ADMIN) {
            $this->Flash->error('Solo Tesorería puede editar pagos.');

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

    public function authorizePayment($invoiceId = null, $paymentId = null)
    {
        $this->request->allowMethod(['post']);
        $roleName = $this->_getRoleName();

        if ($roleName !== RoleConstants::CONTADOR && $roleName !== RoleConstants::ADMIN) {
            $this->Flash->error('Solo el Contador puede autorizar pagos.');

            return $this->_redirectForInvoice((int)$invoiceId, 'edit', $invoiceId);
        }

        $result = $this->paymentService->authorizePayment((int)$paymentId, (int)$this->_getCurrentUser()->id);

        if ($result['success']) {
            if ($result['newPipelineStatus'] === InvoiceConstants::STATUS_PAGADA) {
                $this->Flash->success('Pago autorizado. Factura marcada como Pagada.');
            } else {
                $this->Flash->success('Pago autorizado. Factura devuelta a Tesorería (Pago Parcial).');
            }
        } else {
            $this->Flash->error('No se pudo autorizar el pago.');
        }

        return $this->_redirectForInvoice((int)$invoiceId, 'edit', $invoiceId);
    }

    public function rejectPayment($invoiceId = null, $paymentId = null)
    {
        $this->request->allowMethod(['post']);
        $roleName = $this->_getRoleName();

        if ($roleName !== RoleConstants::CONTADOR && $roleName !== RoleConstants::ADMIN) {
            $this->Flash->error('Solo el Contador puede rechazar pagos.');

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

    public function deletePayment($invoiceId = null, $paymentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $invoicesTable = $this->fetchTable('Invoices');
        $invoice = $invoicesTable->get($invoiceId);

        $roleName = $this->_getRoleName();
        $currentStatus = $invoice->pipeline_status;

        if (
            $roleName !== RoleConstants::ADMIN && (
            $roleName !== RoleConstants::TESORERIA ||
            $currentStatus !== InvoiceConstants::STATUS_TESORERIA
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

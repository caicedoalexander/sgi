<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\InvoicePaymentAttachmentService;

class InvoicePaymentAttachmentsController extends AppController
{
    private InvoicePaymentAttachmentService $attachmentService;

    public function initialize(): void
    {
        parent::initialize();
        $this->attachmentService = new InvoicePaymentAttachmentService();
    }

    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    private function _getRoleName(): string
    {
        return $this->_getUserRoleName($this->_getCurrentUser());
    }

    public function upload($invoiceId = null)
    {
        $this->request->allowMethod(['post']);

        $roleName = $this->_getRoleName();
        if ($roleName !== RoleConstants::TESORERIA && $roleName !== RoleConstants::ADMIN) {
            $this->Flash->error('Solo Tesorería puede subir soportes de pago.');

            return $this->redirect(['controller' => 'Invoices', 'action' => 'edit', $invoiceId]);
        }

        $invoicesTable = $this->fetchTable('Invoices');
        $invoice = $invoicesTable->get($invoiceId);

        if ($invoice->pipeline_status !== InvoiceConstants::STATUS_AUTORIZACION_PAGO) {
            $this->Flash->error('Los soportes solo se pueden subir durante Autorización de Pago.');

            return $this->redirect(['controller' => 'Invoices', 'action' => 'edit', $invoiceId]);
        }

        $file = $this->request->getUploadedFile('file');
        $paymentId = (int)$this->request->getData('invoice_payment_id');

        if (!$file || !$paymentId) {
            $this->Flash->error('Debe seleccionar un archivo y el pago asociado.');

            return $this->redirect(['controller' => 'Invoices', 'action' => 'edit', $invoiceId]);
        }

        $result = $this->attachmentService->upload(
            (int)$invoiceId,
            $paymentId,
            $file,
            (int)$this->_getCurrentUser()->id,
        );

        if ($result->success) {
            $this->Flash->success($result->data);
        } else {
            $this->Flash->error(implode(' ', (array)$result->errors));
        }

        return $this->redirect(['controller' => 'Invoices', 'action' => 'edit', $invoiceId]);
    }

    public function delete($invoiceId = null, $attachmentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);

        $roleName = $this->_getRoleName();
        if ($roleName !== RoleConstants::TESORERIA && $roleName !== RoleConstants::ADMIN) {
            $this->Flash->error('Solo Tesorería puede eliminar soportes de pago.');

            return $this->redirect(['controller' => 'Invoices', 'action' => 'edit', $invoiceId]);
        }

        $result = $this->attachmentService->delete((int)$invoiceId, (int)$attachmentId);

        if ($result->success) {
            $this->Flash->success($result->data);
        } else {
            $this->Flash->error(implode(' ', (array)$result->errors));
        }

        return $this->redirect(['controller' => 'Invoices', 'action' => 'edit', $invoiceId]);
    }
}

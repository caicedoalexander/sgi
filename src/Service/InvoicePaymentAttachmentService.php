<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Trait\DocumentUploadTrait;
use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;

class InvoicePaymentAttachmentService
{
    use DocumentUploadTrait;

    public function upload(int $invoiceId, int $paymentId, UploadedFile $file, int $uploadedBy): ServiceResult
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $payment = $paymentsTable->find()
            ->where(['id' => $paymentId, 'invoice_id' => $invoiceId])
            ->first();

        if (!$payment) {
            return ServiceResult::fail('El pago no pertenece a esta factura.');
        }

        $result = $this->uploadAndSave(
            $file,
            'InvoicePaymentAttachments',
            'invoice-payment-supports/' . $invoiceId,
            'inv_pay_',
            [
                'invoice_payment_id' => $paymentId,
                'uploaded_by' => $uploadedBy,
            ],
        );

        if (is_string($result)) {
            return ServiceResult::fail($result);
        }

        return ServiceResult::ok('Soporte subido correctamente.');
    }

    public function delete(int $invoiceId, int $attachmentId): ServiceResult
    {
        $attachmentsTable = TableRegistry::getTableLocator()->get('InvoicePaymentAttachments');
        $attachment = $attachmentsTable->find()
            ->where(['InvoicePaymentAttachments.id' => $attachmentId])
            ->contain(['InvoicePayments'])
            ->first();

        if (!$attachment || (int)$attachment->invoice_payment->invoice_id !== $invoiceId) {
            return ServiceResult::fail('El soporte no pertenece a esta factura.');
        }

        if (!$this->deleteDocumentRecord('InvoicePaymentAttachments', $attachmentId)) {
            return ServiceResult::fail('No se pudo eliminar el soporte.');
        }

        return ServiceResult::ok('Soporte eliminado.');
    }
}

<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Trait\DocumentUploadTrait;
use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;

class InvoiceDocumentService
{
    use DocumentUploadTrait;

    /**
     * Upload a document for an invoice.
     *
     * @param int $invoiceId Invoice ID.
     * @param string $pipelineStatus Current pipeline status.
     * @param \Laminas\Diactoros\UploadedFile $file Uploaded file.
     * @param int|null $uploadedBy User ID.
     * @param string|null $documentType Document type.
     * @return object|string Entity on success, error message on failure.
     */
    public function uploadDocument(
        int $invoiceId,
        string $pipelineStatus,
        UploadedFile $file,
        ?int $uploadedBy,
        ?string $documentType = null,
    ): object|string {
        return $this->uploadAndSave($file, 'InvoiceDocuments', 'invoices/' . $invoiceId, 'inv_', [
            'invoice_id' => $invoiceId,
            'pipeline_status' => $pipelineStatus,
            'document_type' => $documentType,
            'uploaded_by' => $uploadedBy,
        ]);
    }

    /**
     * Delete an invoice document.
     *
     * @param int $documentId Document ID.
     * @return bool
     */
    public function deleteDocument(int $documentId): bool
    {
        return $this->deleteDocumentRecord('InvoiceDocuments', $documentId);
    }

    /**
     * Check if a document can be deleted in the current status.
     *
     * @param object $document Document entity.
     * @param string $currentPipelineStatus Current pipeline status.
     * @return bool
     */
    public function canDeleteDocument(object $document, string $currentPipelineStatus): bool
    {
        return $document->pipeline_status === $currentPipelineStatus;
    }

    /**
     * Get documents grouped by pipeline status.
     *
     * @param int $invoiceId Invoice ID.
     * @return array
     */
    public function getDocumentsByStatus(int $invoiceId): array
    {
        $documentsTable = TableRegistry::getTableLocator()->get('InvoiceDocuments');
        $documents = $documentsTable->find()
            ->where(['invoice_id' => $invoiceId])
            ->contain(['UploadedByUsers'])
            ->order(['InvoiceDocuments.created' => 'DESC'])
            ->all();

        $grouped = [];
        foreach ($documents as $doc) {
            $grouped[$doc->pipeline_status][] = $doc;
        }

        return $grouped;
    }
}

<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Trait\DocumentUploadTrait;
use Laminas\Diactoros\UploadedFile;

class RefundDocumentService
{
    use DocumentUploadTrait;

    /**
     * Sube un soporte para un reintegro.
     *
     * @return object|string Entity en éxito, mensaje string en error.
     */
    public function uploadDocument(
        int $refundId,
        UploadedFile $file,
        ?int $uploadedBy,
        ?string $documentType = null,
    ): object|string {
        return $this->uploadAndSave($file, 'RefundDocuments', 'refunds/' . $refundId, 'rf_', [
            'refund_id' => $refundId,
            'document_type' => $documentType,
            'uploaded_by' => $uploadedBy,
        ]);
    }

    public function deleteDocument(int $documentId): bool
    {
        return $this->deleteDocumentRecord('RefundDocuments', $documentId);
    }
}

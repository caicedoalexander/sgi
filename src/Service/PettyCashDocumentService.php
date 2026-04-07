<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Trait\DocumentUploadTrait;
use Laminas\Diactoros\UploadedFile;

class PettyCashDocumentService
{
    use DocumentUploadTrait;

    /**
     * Upload a document for a petty cash record.
     *
     * @param int $recordId Petty cash record ID.
     * @param \Laminas\Diactoros\UploadedFile $file Uploaded file.
     * @param int|null $uploadedBy User ID.
     * @param string|null $documentType Document type.
     * @return object|string Entity on success, error message on failure.
     */
    public function uploadDocument(
        int $recordId,
        UploadedFile $file,
        ?int $uploadedBy,
        ?string $documentType = null,
    ): object|string {
        return $this->uploadAndSave($file, 'PettyCashDocuments', 'petty_cash/' . $recordId, 'pc_', [
            'petty_cash_record_id' => $recordId,
            'document_type' => $documentType,
            'uploaded_by' => $uploadedBy,
        ]);
    }

    /**
     * Delete a petty cash document.
     *
     * @param int $documentId Document ID.
     * @return bool
     */
    public function deleteDocument(int $documentId): bool
    {
        return $this->deleteDocumentRecord('PettyCashDocuments', $documentId);
    }
}

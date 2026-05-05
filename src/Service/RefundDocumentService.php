<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Trait\DocumentUploadTrait;
use Cake\ORM\TableRegistry;
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

    /**
     * Elimina un soporte verificando que pertenezca al reintegro indicado.
     * El parámetro $refundId es obligatorio para garantizar el aislamiento
     * anti-IDOR: nunca se debe poder borrar un documento sin probar su
     * pertenencia al refund del cual el caller obtuvo permiso.
     *
     * @param int $documentId ID del soporte.
     * @param int $refundId ID del reintegro propietario (obligatorio).
     * @return bool True si se eliminó correctamente.
     */
    public function deleteDocument(int $documentId, int $refundId): bool
    {
        $table = TableRegistry::getTableLocator()->get('RefundDocuments');
        if (!$table->exists(['id' => $documentId, 'refund_id' => $refundId])) {
            return false;
        }

        return $this->deleteDocumentRecord('RefundDocuments', $documentId);
    }
}

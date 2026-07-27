<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;

class LegalizationDocumentService
{
    private const MAX_DOC_SIZE = 10 * 1024 * 1024; // 10 MB

    private const ALLOWED_DOC_MIMES = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/gif',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * Sube y persiste un documento de legalización; retorna la entidad o un mensaje de error.
     *
     * @param int $recordId Id del registro de legalización.
     * @param \Laminas\Diactoros\UploadedFile $file Archivo subido.
     * @param int|null $uploadedBy Id del usuario que sube el archivo.
     * @param string|null $documentType Tipo de documento, si aplica.
     * @return object|string Entidad del documento guardado, o mensaje de error.
     */
    public function uploadDocument(
        int $recordId,
        UploadedFile $file,
        ?int $uploadedBy,
        ?string $documentType = null,
    ): object|string {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return 'No se recibió ningún archivo válido.';
        }

        if ($file->getSize() > self::MAX_DOC_SIZE) {
            return 'El archivo excede el tamaño máximo de 10MB.';
        }

        $mimeType = $file->getClientMediaType();
        if (!in_array($mimeType, self::ALLOWED_DOC_MIMES)) {
            return 'Tipo de archivo no permitido. Use PDF, imágenes, Word o Excel.';
        }

        $uploadDir = WWW_ROOT . 'uploads' . DS . 'legalizations' . DS . $recordId;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $originalName = $file->getClientFilename();
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $uniqueName = uniqid('leg_') . '.' . $extension;
        $filePath = $uploadDir . DS . $uniqueName;

        $file->moveTo($filePath);

        $documentsTable = TableRegistry::getTableLocator()->get('LegalizationDocuments');
        $document = $documentsTable->newEntity([
            'legalization_record_id' => $recordId,
            'document_type' => $documentType,
            'file_path' => 'uploads/legalizations/' . $recordId . '/' . $uniqueName,
            'file_name' => $originalName,
            'file_size' => $file->getSize(),
            'mime_type' => $mimeType,
            'uploaded_by' => $uploadedBy,
        ]);

        if (!$documentsTable->save($document)) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            return 'No se pudo guardar el documento.';
        }

        return $document;
    }

    /**
     * Elimina un documento de legalización y su archivo físico.
     *
     * @param int $documentId Id del documento.
     * @return bool
     */
    public function deleteDocument(int $documentId): bool
    {
        $documentsTable = TableRegistry::getTableLocator()->get('LegalizationDocuments');
        $document = $documentsTable->get($documentId);

        $filePath = WWW_ROOT . $document->file_path;
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        return $documentsTable->delete($document);
    }
}

<?php
declare(strict_types=1);

namespace App\Service\Trait;

use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;

trait DocumentUploadTrait
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
     * Validate, move, and persist an uploaded document.
     *
     * @param \Laminas\Diactoros\UploadedFile $file The uploaded file.
     * @param string $tableName ORM table name (e.g. 'InvoiceDocuments').
     * @param string $subDir Upload subdirectory (e.g. 'invoices/42').
     * @param string $prefix File name prefix (e.g. 'inv_').
     * @param array $entityFields Extra entity fields to merge.
     * @return object|string Entity on success, error message on failure.
     */
    protected function uploadAndSave(
        UploadedFile $file,
        string $tableName,
        string $subDir,
        string $prefix,
        array $entityFields,
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

        $uploadDir = WWW_ROOT . 'uploads' . DS . $subDir;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $originalName = $file->getClientFilename();
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $uniqueName = uniqid($prefix) . '.' . $extension;
        $filePath = $uploadDir . DS . $uniqueName;

        $file->moveTo($filePath);

        $documentsTable = TableRegistry::getTableLocator()->get($tableName);
        $document = $documentsTable->newEntity(array_merge($entityFields, [
            'file_path' => 'uploads/' . $subDir . '/' . $uniqueName,
            'file_name' => $originalName,
            'file_size' => $file->getSize(),
            'mime_type' => $mimeType,
        ]));

        if (!$documentsTable->save($document)) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            return 'No se pudo guardar el documento.';
        }

        return $document;
    }

    /**
     * Delete a document record and its physical file.
     *
     * @param string $tableName ORM table name.
     * @param int $documentId Document ID.
     * @return bool
     */
    protected function deleteDocumentRecord(string $tableName, int $documentId): bool
    {
        $documentsTable = TableRegistry::getTableLocator()->get($tableName);
        $document = $documentsTable->get($documentId);

        $filePath = WWW_ROOT . $document->file_path;
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        return $documentsTable->delete($document);
    }
}

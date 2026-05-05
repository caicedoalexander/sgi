<?php
declare(strict_types=1);

namespace App\Service\Trait;

use Cake\ORM\TableRegistry;
use finfo;
use Laminas\Diactoros\UploadedFile;

trait DocumentUploadTrait
{
    private const MAX_DOC_SIZE = 20 * 1024 * 1024; // 20 MB

    /**
     * Mapa MIME real (validado con finfo) → extensión que se grabará en disco.
     * La extensión se deriva de aquí, NUNCA del nombre enviado por el cliente:
     * eso evita que un nombre `evil.phtml` con MIME `application/pdf` falsificado
     * o un MIME real distinto puedan dejar un archivo ejecutable en webroot.
     */
    private const ALLOWED_MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        // Algunos navegadores/sistemas reportan los formatos OOXML como ZIP genérico
        // tras finfo (porque internamente lo son). No los aceptamos sin contenedor
        // específico para evitar archivos ZIP arbitrarios.
    ];

    /**
     * Validate and move an uploaded file to its final location, without
     * persisting any DB record. Returns the file metadata on success or
     * an error message on failure.
     *
     * @param \Laminas\Diactoros\UploadedFile $file The uploaded file.
     * @param string $subDir Upload subdirectory (e.g. 'invoices/42').
     * @param string $prefix File name prefix (e.g. 'inv_').
     * @return array{file_path: string, file_name: string, file_size: int, mime_type: string}|string
     *         Metadata array on success, error message on failure.
     */
    protected function validateAndMoveUpload(
        UploadedFile $file,
        string $subDir,
        string $prefix,
    ): array|string {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return 'No se recibió ningún archivo válido.';
        }

        if ($file->getSize() > self::MAX_DOC_SIZE) {
            return 'El archivo excede el tamaño máximo de 20 MB.';
        }

        $uploadDir = WWW_ROOT . 'uploads' . DS . $subDir;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Movemos a un nombre temporal sin extensión "real" para inspeccionar
        // el MIME del contenido con finfo. El header Content-Type del cliente
        // (getClientMediaType) es trivialmente falsificable y NO se usa para
        // decidir tipo ni extensión.
        $uniqueId = uniqid($prefix);
        $tempPath = $uploadDir . DS . $uniqueId . '.uploadtmp';
        $file->moveTo($tempPath);

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($tempPath);
        if ($realMime === false || !isset(self::ALLOWED_MIME_EXTENSIONS[$realMime])) {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            return 'Tipo de archivo no permitido. Use PDF, imágenes, Word o Excel.';
        }

        // Extensión derivada del MIME real validado, no del nombre original.
        $extension = self::ALLOWED_MIME_EXTENSIONS[$realMime];
        $uniqueName = $uniqueId . '.' . $extension;
        $filePath = $uploadDir . DS . $uniqueName;

        if (!rename($tempPath, $filePath)) {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            return 'No se pudo almacenar el archivo. Intente de nuevo.';
        }

        return [
            'file_path' => 'uploads/' . $subDir . '/' . $uniqueName,
            'file_name' => $file->getClientFilename(),
            'file_size' => $file->getSize(),
            'mime_type' => $realMime,
        ];
    }

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
        $info = $this->validateAndMoveUpload($file, $subDir, $prefix);
        if (is_string($info)) {
            return $info;
        }

        $documentsTable = TableRegistry::getTableLocator()->get($tableName);
        $document = $documentsTable->newEntity(array_merge($entityFields, $info));

        if (!$documentsTable->save($document)) {
            $absolutePath = WWW_ROOT . str_replace('/', DS, $info['file_path']);
            if (file_exists($absolutePath)) {
                unlink($absolutePath);
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

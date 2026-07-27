<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AssetConstants;
use App\Constants\UploadConstants;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;
use RuntimeException;
use Throwable;

/**
 * Gestión de actas y soportes de activos. Almacenamiento PRIVADO en
 * ROOT/storage/assets/{assetId} (fuera de webroot, sin acceso directo por URL).
 * Réplica del patrón de EmployeeDocumentService (validación de MIME real +
 * canonicalización de extensión).
 */
class AssetDocumentService
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx'];

    private const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/gif',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private const MIME_TO_EXT = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    /**
     * Directorio raíz de almacenamiento privado de activos (fuera de webroot).
     */
    public static function storageRoot(): string
    {
        return ROOT . DS . 'storage' . DS . 'assets';
    }

    /**
     * Resolver path absoluto en disco a partir del file_path relativo almacenado.
     */
    public function resolveStoragePath(string $relativePath): string
    {
        return self::storageRoot() . DS . str_replace('/', DS, $relativePath);
    }

    /**
     * Subir un documento validando tipo, tamaño, extensión y MIME real (finfo).
     * Almacena el archivo en ROOT/storage/assets/{assetId} y persiste la fila.
     *
     * @param int $assetId ID del activo.
     * @param \Laminas\Diactoros\UploadedFile $file Archivo subido.
     * @param string $documentType Tipo de documento (ver AssetConstants::DOCUMENT_TYPES).
     * @param int|null $movementId ID del movimiento asociado (opcional).
     * @param int $uploadedBy ID del usuario que sube el documento.
     * @return \App\Service\ServiceResult
     */
    public function uploadDocument(
        int $assetId,
        UploadedFile $file,
        string $documentType,
        ?int $movementId,
        int $uploadedBy,
    ): ServiceResult {
        if (!in_array($documentType, AssetConstants::DOCUMENT_TYPES, true)) {
            return ServiceResult::fail('Tipo de documento inválido.');
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            return ServiceResult::fail('No se recibió ningún archivo válido.');
        }

        if ($file->getSize() > UploadConstants::MAX_BYTES) {
            return ServiceResult::fail('El archivo excede el tamaño máximo de ' . UploadConstants::MAX_BYTES_LABEL . '.');
        }

        $originalName = $file->getClientFilename() ?? '';
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return ServiceResult::fail('Tipo de archivo no permitido. Use PDF, imágenes, Word o Excel.');
        }

        $uploadDir = self::storageRoot() . DS . $assetId;
        $this->_ensureDir($uploadDir);

        $uniqueName = uniqid('asset_') . '.' . $extension;
        $absolutePath = $uploadDir . DS . $uniqueName;

        try {
            $file->moveTo($absolutePath);
        } catch (Throwable) {
            return ServiceResult::fail('No se pudo guardar el archivo en disco.');
        }

        $realMime = $this->_detectRealMime($absolutePath);
        if (!in_array($realMime, self::ALLOWED_MIMES, true)) {
            @unlink($absolutePath);

            return ServiceResult::fail('El contenido del archivo no coincide con su extensión.');
        }

        [$absolutePath, $relativePath] = $this->_canonicalize($absolutePath, $assetId . '/' . $uniqueName, $realMime);

        $documentsTable = TableRegistry::getTableLocator()->get('AssetDocuments');
        $document = $documentsTable->newEntity([
            'asset_id' => $assetId,
            'asset_movement_id' => $movementId,
            'document_type' => $documentType,
            'name' => $originalName,
            'file_path' => $relativePath,
            'file_size' => $file->getSize(),
            'mime_type' => $realMime,
            'uploaded_by' => $uploadedBy,
        ]);

        if (!$documentsTable->save($document)) {
            @unlink($absolutePath);

            return ServiceResult::fail('No se pudo guardar el documento.');
        }

        return ServiceResult::ok($document);
    }

    /**
     * Eliminar documento validando ownership y limpiando el archivo físico.
     *
     * @param int $assetId ID del activo.
     * @param int $documentId ID del documento.
     * @return \App\Service\ServiceResult
     */
    public function deleteDocument(int $assetId, int $documentId): ServiceResult
    {
        $documentsTable = TableRegistry::getTableLocator()->get('AssetDocuments');

        try {
            $document = $documentsTable->find()
                ->where(['AssetDocuments.id' => $documentId, 'AssetDocuments.asset_id' => $assetId])
                ->firstOrFail();
        } catch (RecordNotFoundException) {
            return ServiceResult::fail('El documento no existe o no pertenece al activo.');
        }

        $absolutePath = $this->resolveStoragePath($document->file_path);

        if (!$documentsTable->delete($document)) {
            return ServiceResult::fail('No se pudo eliminar el documento.');
        }

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        return ServiceResult::ok();
    }

    /**
     * Detectar MIME real del archivo en disco usando finfo.
     * Devuelve cadena vacía si no se puede determinar.
     */
    private function _detectRealMime(string $absolutePath): string
    {
        if (!is_file($absolutePath)) {
            return '';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return '';
        }

        // finfo_close is deprecated as of PHP 8.4; the resource is closed when $finfo goes out of scope.
        $mime = finfo_file($finfo, $absolutePath);

        return $mime !== false ? $mime : '';
    }

    /**
     * Crear directorio con manejo de race condition.
     */
    private function _ensureDir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('No se pudo crear el directorio %s.', $path));
        }
    }

    /**
     * Renombra el archivo para que su extensión coincida con la canónica del
     * MIME real. Defense-in-depth; si el rename falla, conserva el original.
     *
     * @return array{0:string, 1:string} [absolutePath, relativePath]
     */
    private function _canonicalize(string $absolutePath, string $relativePath, string $realMime): array
    {
        $canonicalExt = self::MIME_TO_EXT[$realMime] ?? null;
        if ($canonicalExt === null) {
            return [$absolutePath, $relativePath];
        }

        $currentExt = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        if ($currentExt === $canonicalExt) {
            return [$absolutePath, $relativePath];
        }

        $newAbsolute = preg_replace('/\.[^.]+$/', '.' . $canonicalExt, $absolutePath) ?? $absolutePath;
        $newRelative = preg_replace('/\.[^.]+$/', '.' . $canonicalExt, $relativePath) ?? $relativePath;

        if (!@rename($absolutePath, $newAbsolute)) {
            return [$absolutePath, $relativePath];
        }

        return [$newAbsolute, $newRelative];
    }
}

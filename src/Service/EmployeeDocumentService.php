<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\EmployeeDocument;
use App\Model\Entity\EmployeeFolder;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;
use RuntimeException;
use Throwable;

class EmployeeDocumentService
{
    private const MAX_DOC_SIZE = 20 * 1024 * 1024; // 20 MB
    private const MAX_PROFILE_SIZE = 2 * 1024 * 1024; // 2 MB

    /**
     * Whitelist de extensiones permitidas para documentos.
     * Se valida case-insensitive contra la extensión real del archivo subido.
     */
    private const ALLOWED_DOC_EXTENSIONS = [
        'pdf', 'jpg', 'jpeg', 'png', 'gif',
        'doc', 'docx', 'xls', 'xlsx', 'txt',
    ];

    private const ALLOWED_PROFILE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private const ALLOWED_DOC_MIMES = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/gif',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
    ];

    private const ALLOWED_PROFILE_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    ];

    /**
     * Mapeo MIME real → extensión canónica para documentos.
     * Usado por canonicalize() para renombrar el archivo en disco si la
     * extensión del cliente no coincide con la canónica del MIME real (CR-028).
     */
    private const MIME_TO_EXT = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
    ];

    private const MIME_TO_EXT_PROFILE = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * Resolver el directorio raíz de almacenamiento de documentos sensibles
     * (fuera de webroot, sin acceso directo por URL).
     */
    public static function storageRoot(): string
    {
        return ROOT . DS . 'storage' . DS . 'employees';
    }

    /**
     * Verifica que la carpeta pertenezca al empleado indicado.
     * Lanza RecordNotFoundException si no coincide o no existe.
     */
    public function assertFolderOwnership(int $employeeId, int $folderId): EmployeeFolder
    {
        $foldersTable = TableRegistry::getTableLocator()->get('EmployeeFolders');

        return $foldersTable->find()
            ->where(['EmployeeFolders.id' => $folderId, 'EmployeeFolders.employee_id' => $employeeId])
            ->firstOrFail();
    }

    /**
     * Verifica que el documento pertenezca al empleado indicado (vía join con la carpeta).
     * Lanza RecordNotFoundException si no coincide o no existe.
     */
    public function assertDocumentOwnership(int $employeeId, int $documentId): EmployeeDocument
    {
        $documentsTable = TableRegistry::getTableLocator()->get('EmployeeDocuments');

        return $documentsTable->find()
            ->contain(['EmployeeFolders'])
            ->where([
                'EmployeeDocuments.id' => $documentId,
                'EmployeeFolders.employee_id' => $employeeId,
            ])
            ->firstOrFail();
    }

    /**
     * Resolver path absoluto en disco a partir del file_path almacenado.
     * Backwards-compat: paths viejos (uploads/employees/...) viven en webroot;
     * paths nuevos se guardan relativos al storage root fuera de webroot.
     */
    public function resolveStoragePath(string $relativePath): string
    {
        if (str_starts_with($relativePath, 'uploads/')) {
            return WWW_ROOT . $relativePath;
        }

        return self::storageRoot() . DS . str_replace('/', DS, $relativePath);
    }

    /**
     * Subir un documento validando ownership de la carpeta, tamaño,
     * extensión (whitelist) y MIME real (finfo) del archivo.
     */
    public function uploadDocument(
        int $employeeId,
        int $folderId,
        UploadedFile $file,
        ?int $uploadedBy,
    ): ServiceResult {
        try {
            $this->assertFolderOwnership($employeeId, $folderId);
        } catch (RecordNotFoundException) {
            return ServiceResult::fail('La carpeta seleccionada no existe o no pertenece al empleado.');
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            return ServiceResult::fail('No se recibió ningún archivo válido.');
        }

        if ($file->getSize() > self::MAX_DOC_SIZE) {
            return ServiceResult::fail('El archivo excede el tamaño máximo de 20 MB.');
        }

        $originalName = $file->getClientFilename() ?? '';
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, self::ALLOWED_DOC_EXTENSIONS, true)) {
            return ServiceResult::fail('Tipo de archivo no permitido.');
        }

        $uploadDir = self::storageRoot() . DS . $employeeId;
        $this->ensureDir($uploadDir);

        $uniqueName = uniqid('doc_') . '.' . $extension;
        $absolutePath = $uploadDir . DS . $uniqueName;

        try {
            $file->moveTo($absolutePath);
        } catch (Throwable $e) {
            return ServiceResult::fail('No se pudo guardar el archivo en disco.');
        }

        // Validar MIME real luego de mover (finfo opera sobre el archivo final)
        $realMime = $this->detectRealMime($absolutePath);
        if (!in_array($realMime, self::ALLOWED_DOC_MIMES, true)) {
            @unlink($absolutePath);

            return ServiceResult::fail('El contenido del archivo no coincide con su extensión.');
        }

        // Canonicalizar extensión a partir del MIME real (CR-028).
        [$absolutePath, $relativeFilePath] = $this->canonicalize(
            $absolutePath,
            $employeeId . '/' . $uniqueName,
            $realMime,
            self::MIME_TO_EXT,
        );

        $documentsTable = TableRegistry::getTableLocator()->get('EmployeeDocuments');
        $document = $documentsTable->newEntity([
            'employee_folder_id' => $folderId,
            'name' => $originalName,
            'file_path' => $relativeFilePath,
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
     * Crear una carpeta para el empleado validando ownership del padre (si aplica).
     */
    public function createFolder(int $employeeId, ?string $name, ?int $parentId): ServiceResult
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') {
            return ServiceResult::fail('El nombre de la carpeta es requerido.');
        }

        if ($parentId !== null) {
            try {
                $this->assertFolderOwnership($employeeId, $parentId);
            } catch (RecordNotFoundException) {
                return ServiceResult::fail('La carpeta padre no es válida.');
            }
        }

        $foldersTable = TableRegistry::getTableLocator()->get('EmployeeFolders');
        $folder = $foldersTable->newEntity([
            'employee_id' => $employeeId,
            'name' => $name,
            'parent_id' => $parentId,
        ]);

        if (!$foldersTable->save($folder)) {
            return ServiceResult::fail('No se pudo crear la carpeta.');
        }

        return ServiceResult::ok($folder);
    }

    /**
     * Eliminar documento validando ownership y limpiando el archivo físico
     * sólo si la fila se borra correctamente.
     */
    public function deleteDocument(int $employeeId, int $documentId): ServiceResult
    {
        try {
            $document = $this->assertDocumentOwnership($employeeId, $documentId);
        } catch (RecordNotFoundException) {
            return ServiceResult::fail('El documento no existe o no pertenece al empleado.');
        }

        $absolutePath = $this->resolveStoragePath($document->file_path);

        $documentsTable = TableRegistry::getTableLocator()->get('EmployeeDocuments');
        if (!$documentsTable->delete($document)) {
            return ServiceResult::fail('No se pudo eliminar el documento.');
        }

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        return ServiceResult::ok();
    }

    /**
     * Validar y mover la imagen de perfil. Mutar `profile_image` en la entidad
     * pero NO persistir — el caller hace el save dentro de su transacción.
     * La imagen de perfil queda en webroot porque es pública por naturaleza.
     */
    public function handleProfileImage(object $employee, ?UploadedFile $file): ServiceResult
    {
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return ServiceResult::ok(['skipped' => true]);
        }

        if ($file->getSize() > self::MAX_PROFILE_SIZE) {
            return ServiceResult::fail('La imagen de perfil excede el tamaño máximo de 2 MB.');
        }

        $originalName = $file->getClientFilename() ?? '';
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, self::ALLOWED_PROFILE_EXTENSIONS, true)) {
            return ServiceResult::fail('Tipo de imagen no permitido. Use JPG, PNG, GIF o WebP.');
        }

        $uploadDir = WWW_ROOT . 'uploads' . DS . 'employees' . DS . $employee->id;
        $this->ensureDir($uploadDir);

        $fileName = 'profile.' . $extension;
        $absolutePath = $uploadDir . DS . $fileName;

        // Borrar imagen previa (puede tener distinta extensión)
        if (!empty($employee->profile_image)) {
            $oldAbsolute = WWW_ROOT . $employee->profile_image;
            if (is_file($oldAbsolute) && $oldAbsolute !== $absolutePath) {
                @unlink($oldAbsolute);
            }
        }

        try {
            $file->moveTo($absolutePath);
        } catch (Throwable $e) {
            return ServiceResult::fail('No se pudo guardar la imagen de perfil.');
        }

        $realMime = $this->detectRealMime($absolutePath);
        if (!in_array($realMime, self::ALLOWED_PROFILE_MIMES, true)) {
            @unlink($absolutePath);

            return ServiceResult::fail('El contenido de la imagen no coincide con su extensión.');
        }

        // Canonicalizar extensión a partir del MIME real (CR-028).
        [$absolutePath, $relativePath] = $this->canonicalize(
            $absolutePath,
            'uploads/employees/' . $employee->id . '/' . $fileName,
            $realMime,
            self::MIME_TO_EXT_PROFILE,
        );

        $employee->profile_image = $relativePath;
        $employee->setDirty('profile_image', true);

        return ServiceResult::ok(['path' => $employee->profile_image]);
    }

    /**
     * Borrar la imagen de perfil que ya quedó en disco (compensación si el
     * save de la entity falla luego de moveTo).
     */
    public function cleanupProfileImage(?string $relativePath): void
    {
        if (!$relativePath) {
            return;
        }

        $absolutePath = WWW_ROOT . $relativePath;
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    /**
     * Crear las carpetas por defecto para un empleado nuevo.
     * Usa saveMany para hacerlo en una sola transacción y reportar errores.
     */
    public function createDefaultFolders(int $employeeId): ServiceResult
    {
        $defaultFoldersTable = TableRegistry::getTableLocator()->get('DefaultFolders');
        $foldersTable = TableRegistry::getTableLocator()->get('EmployeeFolders');

        $defaults = $defaultFoldersTable->find()
            ->order(['sort_order' => 'ASC'])
            ->all()
            ->toList();

        if (empty($defaults)) {
            return ServiceResult::ok(['count' => 0]);
        }

        $entities = array_map(
            fn($default) => $foldersTable->newEntity([
                'employee_id' => $employeeId,
                'name' => $default->name,
                'parent_id' => null,
            ]),
            $defaults,
        );

        $saved = $foldersTable->saveMany($entities, ['atomic' => true]);
        if ($saved === false) {
            return ServiceResult::fail('No se pudieron crear las carpetas por defecto.');
        }

        return ServiceResult::ok(['count' => count($entities)]);
    }

    /**
     * Eliminar todos los archivos físicos del empleado (storage + webroot).
     * Llamado desde delete() del controller después de borrar la fila.
     */
    public function deleteEmployeeFiles(int $employeeId): void
    {
        $this->purgeDir(self::storageRoot() . DS . $employeeId);
        $this->purgeDir(WWW_ROOT . 'uploads' . DS . 'employees' . DS . $employeeId);
    }

    /**
     * Detectar MIME real del archivo en disco usando finfo.
     * Devuelve cadena vacía si no se puede determinar.
     */
    private function detectRealMime(string $absolutePath): string
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
     * Crear directorio con manejo de race condition (otro proceso pudo haberlo creado).
     */
    private function ensureDir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('No se pudo crear el directorio %s.', $path));
        }
    }

    /**
     * Borrar contenido + directorio si existe.
     */
    private function purgeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . DS . '*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }

    /**
     * Renombra el archivo en disco para que su extensión coincida con la
     * canónica del MIME real detectado por finfo (CR-028).
     *
     * Si el rename falla (permisos, etc.), se conserva el path original sin
     * lanzar excepción — la validación de MIME ya pasó, esto es defense-in-depth.
     *
     * @param string $absolutePath Path absoluto actual.
     * @param string $relativePath Path relativo actual.
     * @param string $realMime MIME real detectado.
     * @param array<string,string> $mimeToExt Mapeo MIME → extensión canónica.
     * @return array{0:string, 1:string} [absolutePath, relativePath] resultantes.
     */
    private function canonicalize(
        string $absolutePath,
        string $relativePath,
        string $realMime,
        array $mimeToExt,
    ): array {
        $canonicalExt = $mimeToExt[$realMime] ?? null;
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

<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;

class EmployeeDocumentService
{
    private const MAX_DOC_SIZE = 20 * 1024 * 1024; // 20 MB
    private const MAX_PROFILE_SIZE = 2 * 1024 * 1024; // 2 MB

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
     * Validate and store an uploaded document, returning the saved entity or an error string.
     *
     * @return \App\Model\Entity\EmployeeDocument|string Entity on success, error message on failure.
     */
    public function uploadDocument(
        int $employeeId,
        int $folderId,
        UploadedFile $file,
        ?int $uploadedBy,
    ): object|string {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return 'No se recibió ningún archivo válido.';
        }

        if ($file->getSize() > self::MAX_DOC_SIZE) {
            return 'El archivo excede el tamaño máximo de 20 MB.';
        }

        $mimeType = $file->getClientMediaType();
        if (!in_array($mimeType, self::ALLOWED_DOC_MIMES)) {
            return 'Tipo de archivo no permitido.';
        }

        $uploadDir = WWW_ROOT . 'uploads' . DS . 'employees' . DS . $employeeId;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $originalName = $file->getClientFilename();
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $uniqueName = uniqid('doc_') . '.' . $extension;
        $filePath = $uploadDir . DS . $uniqueName;

        $file->moveTo($filePath);

        $documentsTable = TableRegistry::getTableLocator()->get('EmployeeDocuments');
        $document = $documentsTable->newEntity([
            'employee_folder_id' => $folderId,
            'name' => $originalName,
            'file_path' => 'uploads/employees/' . $employeeId . '/' . $uniqueName,
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
     * Delete a document record and its physical file.
     */
    public function deleteDocument(int $documentId): bool
    {
        $documentsTable = TableRegistry::getTableLocator()->get('EmployeeDocuments');
        $document = $documentsTable->get($documentId);

        $filePath = WWW_ROOT . $document->file_path;
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        return $documentsTable->delete($document);
    }

    /**
     * Handle profile image upload. Returns null on success or an error/warning message.
     */
    public function handleProfileImage(object $employee, ?UploadedFile $file): ?string
    {
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return null; // No file uploaded — nothing to do
        }

        if ($file->getSize() > self::MAX_PROFILE_SIZE) {
            return 'La imagen de perfil excede el tamaño máximo de 2MB.';
        }

        $mimeType = $file->getClientMediaType();
        if (!in_array($mimeType, self::ALLOWED_PROFILE_MIMES)) {
            return 'Tipo de imagen no permitido. Use JPEG, PNG, GIF o WebP.';
        }

        $uploadDir = WWW_ROOT . 'uploads' . DS . 'employees' . DS . $employee->id;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $extension = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
        $fileName = 'profile.' . $extension;
        $filePath = $uploadDir . DS . $fileName;

        // Remove old profile image
        if ($employee->profile_image) {
            $oldPath = WWW_ROOT . $employee->profile_image;
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }

        $file->moveTo($filePath);

        $employee->profile_image = 'uploads/employees/' . $employee->id . '/' . $fileName;
        TableRegistry::getTableLocator()->get('Employees')->save($employee);

        return null;
    }

    /**
     * Create the default folder structure for a new employee.
     */
    public function createDefaultFolders(int $employeeId): void
    {
        $defaultFoldersTable = TableRegistry::getTableLocator()->get('DefaultFolders');
        $foldersTable = TableRegistry::getTableLocator()->get('EmployeeFolders');

        $defaults = $defaultFoldersTable->find()
            ->order(['sort_order' => 'ASC'])
            ->all();

        foreach ($defaults as $default) {
            $folder = $foldersTable->newEntity([
                'employee_id' => $employeeId,
                'name' => $default->name,
                'parent_id' => null,
            ]);
            $foldersTable->save($folder);
        }
    }

    /**
     * Delete all physical files for an employee (used before deleting the employee record).
     */
    public function deleteEmployeeFiles(int $employeeId): void
    {
        $uploadDir = WWW_ROOT . 'uploads' . DS . 'employees' . DS . $employeeId;
        if (!is_dir($uploadDir)) {
            return;
        }

        $files = glob($uploadDir . DS . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($uploadDir);
    }
}

<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;

class DianCrosscheckService
{
    private const ALLOWED_MIMES = [
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private const MAX_SIZE = 10 * 1024 * 1024; // 10MB

    private N8nService $n8nService;

    /**
     * Constructor.
     */
    public function __construct(?N8nService $n8nService = null)
    {
        $this->n8nService = $n8nService ?? new N8nService();
    }

    /**
     * Process an uploaded DIAN crosscheck file.
     *
     * @return \App\Model\Entity\DianCrosscheck|string Entity on success, error message on failure.
     */
    public function processUpload(UploadedFile $file, int $userId): mixed
    {
        // Validate MIME type
        $mime = $file->getClientMediaType();
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return 'El archivo debe ser un archivo Excel (.xls o .xlsx).';
        }

        // Validate file size
        if ($file->getSize() > self::MAX_SIZE) {
            return 'El archivo no debe superar los 10 MB.';
        }

        // Prepare upload directory
        $uploadDir = WWW_ROOT . 'uploads' . DS . 'dian_crosschecks';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $fileName = $file->getClientFilename();
        $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
        $filePath = $uploadDir . DS . $safeName;

        // Move uploaded file
        $file->moveTo($filePath);

        // Create DB record
        $table = TableRegistry::getTableLocator()->get('DianCrosschecks');
        $entity = $table->newEntity([
            'uploaded_by' => $userId,
            'file_name' => $fileName,
            'file_path' => 'uploads/dian_crosschecks/' . $safeName,
            'status' => 'enviado',
        ]);

        if (!$table->save($entity)) {
            return 'Error al guardar el registro en la base de datos.';
        }

        // Send to n8n
        if ($this->n8nService->isConfigured('n8n_webhook_dian_crosscheck')) {
            $result = $this->n8nService->sendFile(
                'n8n_webhook_dian_crosscheck',
                $filePath,
                'file',
                ['crosscheck_id' => $entity->id, 'file_name' => $fileName],
            );

            if ($result['success']) {
                $entity->status = 'procesando';
                $entity->n8n_response = $result['body'];
            } else {
                $entity->status = 'error';
                $entity->error_message = $result['error'];
            }
            $table->save($entity);
        }

        return $entity;
    }
}

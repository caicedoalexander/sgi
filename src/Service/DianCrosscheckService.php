<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Log\Log;
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
     * @param \Laminas\Diactoros\UploadedFile $file Uploaded file.
     * @param int $userId User ID.
     * @return \App\Service\ServiceResult
     */
    public function processUpload(UploadedFile $file, int $userId): ServiceResult
    {
        // Validate MIME type
        $mime = $file->getClientMediaType();
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return ServiceResult::fail('El archivo debe ser un archivo Excel (.xls o .xlsx).');
        }

        // Validate file size
        if ($file->getSize() > self::MAX_SIZE) {
            return ServiceResult::fail('El archivo no debe superar los 10 MB.');
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
            return ServiceResult::fail('Error al guardar el registro en la base de datos.');
        }

        // Send to n8n with retry tracking
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
                $entity->status = 'error_envio';
                $entity->error_message = $result['error'];
                $entity->attempt_count = 1;
                Log::warning("DianCrosscheck #{$entity->id}: webhook failed, queued for retry — {$result['error']}");
            }
            $table->save($entity);
        }

        return ServiceResult::ok($entity);
    }

    /**
     * Retry failed webhook sends. Call from a cron job or admin action.
     *
     * @param int $maxAttempts Maximum retry attempts before marking as permanent failure.
     * @return array{retried: int, succeeded: int, failed: int}
     */
    public function retryFailed(int $maxAttempts = 3): array
    {
        $table = TableRegistry::getTableLocator()->get('DianCrosschecks');
        $pending = $table->find()
            ->where([
                'status' => 'error_envio',
                'attempt_count <' => $maxAttempts,
            ])
            ->all();

        $retried = 0;
        $succeeded = 0;
        $failed = 0;

        foreach ($pending as $entity) {
            $retried++;
            $filePath = WWW_ROOT . $entity->file_path;

            if (!file_exists($filePath)) {
                $entity->status = 'error_permanente';
                $entity->error_message = 'Archivo no encontrado para reintento';
                $table->save($entity);
                $failed++;
                continue;
            }

            $result = $this->n8nService->sendFile(
                'n8n_webhook_dian_crosscheck',
                $filePath,
                'file',
                ['crosscheck_id' => $entity->id, 'file_name' => $entity->file_name],
            );

            $entity->attempt_count = ($entity->attempt_count ?? 0) + 1;

            if ($result['success']) {
                $entity->status = 'procesando';
                $entity->n8n_response = $result['body'];
                $entity->error_message = null;
                $succeeded++;
            } else {
                $entity->error_message = $result['error'];
                if ($entity->attempt_count >= $maxAttempts) {
                    $entity->status = 'error_permanente';
                }
                $failed++;
            }

            $table->save($entity);
        }

        return compact('retried', 'succeeded', 'failed');
    }
}

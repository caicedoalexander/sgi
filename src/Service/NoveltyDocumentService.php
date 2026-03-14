<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;

class NoveltyDocumentService
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
     * @param int $noveltyId Novelty ID.
     * @param string $pipelineStatus Current pipeline status.
     * @param \Laminas\Diactoros\UploadedFile $file Uploaded file.
     * @param int|null $uploadedBy User ID.
     * @return object|string
     */
    public function uploadForNovelty(
        int $noveltyId,
        string $pipelineStatus,
        UploadedFile $file,
        ?int $uploadedBy,
    ): object|string {
        return $this->upload($file, $pipelineStatus, $uploadedBy, 'novelties/' . $noveltyId, [
            'novelty_id' => $noveltyId,
        ]);
    }

    /**
     * @param int $liquidationDocId Liquidation document ID.
     * @param string $pipelineStatus Current pipeline status.
     * @param \Laminas\Diactoros\UploadedFile $file Uploaded file.
     * @param int|null $uploadedBy User ID.
     * @return object|string
     */
    public function uploadForGroup(
        int $liquidationDocId,
        string $pipelineStatus,
        UploadedFile $file,
        ?int $uploadedBy,
    ): object|string {
        return $this->upload($file, $pipelineStatus, $uploadedBy, 'novelty_liquidations/' . $liquidationDocId, [
            'liquidation_doc_id' => $liquidationDocId,
        ]);
    }

    /**
     * @param \Laminas\Diactoros\UploadedFile $file Uploaded file.
     * @param string $pipelineStatus Pipeline status.
     * @param int|null $uploadedBy User ID.
     * @param string $subDir Upload subdirectory.
     * @param array $extraFields Extra entity fields.
     * @return object|string
     */
    private function upload(
        UploadedFile $file,
        string $pipelineStatus,
        ?int $uploadedBy,
        string $subDir,
        array $extraFields,
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
        $uniqueName = uniqid('nov_') . '.' . $extension;
        $filePath = $uploadDir . DS . $uniqueName;

        $file->moveTo($filePath);

        $documentsTable = TableRegistry::getTableLocator()->get('NoveltyDocuments');
        $document = $documentsTable->newEntity(array_merge($extraFields, [
            'pipeline_status' => $pipelineStatus,
            'file_path' => 'uploads/' . $subDir . '/' . $uniqueName,
            'file_name' => $originalName,
            'file_size' => $file->getSize(),
            'mime_type' => $mimeType,
            'uploaded_by' => $uploadedBy,
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
     * @param int $documentId Document ID.
     * @return bool
     */
    public function deleteDocument(int $documentId): bool
    {
        $documentsTable = TableRegistry::getTableLocator()->get('NoveltyDocuments');
        $document = $documentsTable->get($documentId);

        $filePath = WWW_ROOT . $document->file_path;
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        return $documentsTable->delete($document);
    }

    /**
     * @param object $document Document entity.
     * @param string $currentPipelineStatus Current pipeline status.
     * @return bool
     */
    public function canDeleteDocument(object $document, string $currentPipelineStatus): bool
    {
        return $document->pipeline_status === $currentPipelineStatus;
    }

    /**
     * @param int $noveltyId Novelty ID.
     * @return array<string, array>
     */
    public function getDocumentsByStatus(int $noveltyId): array
    {
        $documentsTable = TableRegistry::getTableLocator()->get('NoveltyDocuments');
        $documents = $documentsTable->find()
            ->where(['novelty_id' => $noveltyId])
            ->contain(['UploadedByUsers'])
            ->order(['NoveltyDocuments.created' => 'DESC'])
            ->all();

        $grouped = [];
        foreach ($documents as $doc) {
            $grouped[$doc->pipeline_status][] = $doc;
        }

        return $grouped;
    }

    /**
     * @param int $liquidationDocId Liquidation document ID.
     * @return array<string, array>
     */
    public function getGroupDocumentsByStatus(int $liquidationDocId): array
    {
        $documentsTable = TableRegistry::getTableLocator()->get('NoveltyDocuments');
        $documents = $documentsTable->find()
            ->where(['liquidation_doc_id' => $liquidationDocId])
            ->contain(['UploadedByUsers'])
            ->order(['NoveltyDocuments.created' => 'DESC'])
            ->all();

        $grouped = [];
        foreach ($documents as $doc) {
            $grouped[$doc->pipeline_status][] = $doc;
        }

        return $grouped;
    }
}

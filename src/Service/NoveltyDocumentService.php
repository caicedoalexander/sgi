<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\NoveltyConstants;
use App\Service\Trait\DocumentUploadTrait;
use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;

class NoveltyDocumentService
{
    use DocumentUploadTrait;

    private const TABLE = 'NoveltyDocuments';
    private const PREFIX = 'nov_';

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
        return $this->uploadAndSave(
            $file,
            self::TABLE,
            'novelties/' . $noveltyId,
            self::PREFIX,
            [
                'novelty_id' => $noveltyId,
                'pipeline_status' => $pipelineStatus,
                'uploaded_by' => $uploadedBy,
            ],
        );
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
        return $this->uploadAndSave(
            $file,
            self::TABLE,
            'novelty_liquidations/' . $liquidationDocId,
            self::PREFIX,
            [
                'liquidation_doc_id' => $liquidationDocId,
                'pipeline_status' => $pipelineStatus,
                'uploaded_by' => $uploadedBy,
            ],
        );
    }

    /**
     * @param int $documentId Document ID.
     * @return bool
     */
    public function deleteDocument(int $documentId): bool
    {
        return $this->deleteDocumentRecord(self::TABLE, $documentId);
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
        $documentsTable = TableRegistry::getTableLocator()->get(self::TABLE);
        $documents = $documentsTable->find()
            ->where(['novelty_id' => $noveltyId])
            ->contain(['UploadedByUsers'])
            ->orderBy(['NoveltyDocuments.created' => 'DESC'])
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
        $documentsTable = TableRegistry::getTableLocator()->get(self::TABLE);
        $documents = $documentsTable->find()
            ->where([
                'liquidation_doc_id' => $liquidationDocId,
                'document_type !=' => NoveltyConstants::DOC_TYPE_LIQUIDATION,
            ])
            ->contain(['UploadedByUsers'])
            ->orderBy(['NoveltyDocuments.created' => 'DESC'])
            ->all();

        $grouped = [];
        foreach ($documents as $doc) {
            $grouped[$doc->pipeline_status][] = $doc;
        }

        return $grouped;
    }

    /**
     * Get the liquidation document for a group.
     *
     * @param int $liquidationDocId Liquidation document ID.
     * @return object|null
     */
    public function getLiquidationDocument(int $liquidationDocId): ?object
    {
        $documentsTable = TableRegistry::getTableLocator()->get(self::TABLE);

        return $documentsTable->find()
            ->where([
                'liquidation_doc_id' => $liquidationDocId,
                'document_type' => NoveltyConstants::DOC_TYPE_LIQUIDATION,
            ])
            ->contain(['UploadedByUsers'])
            ->first();
    }

    /**
     * Upload the liquidation document (first time).
     *
     * @param int $liquidationDocId Liquidation document ID.
     * @param \Laminas\Diactoros\UploadedFile $file Uploaded file.
     * @param int|null $uploadedBy User ID.
     * @return object|string
     */
    public function uploadLiquidationDocument(
        int $liquidationDocId,
        UploadedFile $file,
        ?int $uploadedBy,
    ): object|string {
        $existing = $this->getLiquidationDocument($liquidationDocId);
        if ($existing) {
            return 'Ya existe un documento de liquidación. Use la opción de actualizar.';
        }

        return $this->uploadAndSave(
            $file,
            self::TABLE,
            'novelty_liquidations/' . $liquidationDocId,
            self::PREFIX,
            [
                'liquidation_doc_id' => $liquidationDocId,
                'pipeline_status' => NoveltyConstants::DOC_STATUS_LIQUIDACION,
                'document_type' => NoveltyConstants::DOC_TYPE_LIQUIDATION,
                'uploaded_by' => $uploadedBy,
            ],
        );
    }

    /**
     * Update (replace) the liquidation document.
     *
     * Saves the new file first, then removes the previous record + physical file,
     * so a validation/upload failure leaves the existing document intact.
     *
     * @param int $liquidationDocId Liquidation document ID.
     * @param \Laminas\Diactoros\UploadedFile $file Uploaded file.
     * @param int|null $uploadedBy User ID.
     * @return object|string
     */
    public function updateLiquidationDocument(
        int $liquidationDocId,
        UploadedFile $file,
        ?int $uploadedBy,
    ): object|string {
        $existing = $this->getLiquidationDocument($liquidationDocId);
        if (!$existing) {
            return 'No existe un documento de liquidación para actualizar.';
        }

        $previousId = (int)$existing->id;

        $result = $this->uploadAndSave(
            $file,
            self::TABLE,
            'novelty_liquidations/' . $liquidationDocId,
            self::PREFIX,
            [
                'liquidation_doc_id' => $liquidationDocId,
                'pipeline_status' => NoveltyConstants::DOC_STATUS_LIQUIDACION,
                'document_type' => NoveltyConstants::DOC_TYPE_LIQUIDATION,
                'uploaded_by' => $uploadedBy,
            ],
        );

        if (is_string($result)) {
            return $result;
        }

        $this->deleteDocumentRecord(self::TABLE, $previousId);

        return $result;
    }
}

<?php
declare(strict_types=1);

namespace App\Controller\Trait;

use Cake\Datasource\EntityInterface;

/**
 * Builds the uniform JSON payload returned by AJAX document uploaders
 * (see webroot/js/spi-document-uploader.js for the consumer contract).
 *
 * Used by Invoices, PettyCashRecords, NoveltyDocuments and NoveltyLiquidationDocs
 * controllers — keeps the JSON shape consistent across modules.
 */
trait DocumentJsonPayloadTrait
{
    /**
     * Build the `document` array exposed in the JSON `success` response.
     *
     * @param \Cake\Datasource\EntityInterface $document Document entity (with id,
     *     file_name, document_type, mime_type, file_path, file_size, created and
     *     optional pipeline_status).
     * @param bool $canDelete Whether the requesting user is allowed to delete it.
     * @param string|null $deleteUrl Pre-built relative URL to the delete action,
     *     or null if not available.
     * @param array<string,string> $badgeColors Map pipeline_status → badge CSS class.
     * @param array<string,string> $statusLabels Map pipeline_status → display label.
     * @return array<string,mixed>
     */
    protected function _buildDocumentPayload(
        EntityInterface $document,
        bool $canDelete,
        ?string $deleteUrl,
        array $badgeColors = [],
        array $statusLabels = [],
    ): array {
        $status = $document->pipeline_status ?? null;

        return [
            'id' => $document->id,
            'file_name' => $document->file_name,
            'document_type' => $document->document_type ?? null,
            'mime_type' => $document->mime_type,
            'file_path' => $document->file_path,
            'file_size' => $document->file_size,
            'pipeline_status' => $status,
            'created' => $document->created->format('d/m/Y H:i'),
            'can_delete' => $canDelete,
            'badge_class' => $status !== null ? ($badgeColors[$status] ?? 'pill-muted') : null,
            'badge_label' => $status !== null ? ($statusLabels[$status] ?? $status) : null,
            'delete_url' => $canDelete ? $deleteUrl : null,
        ];
    }
}

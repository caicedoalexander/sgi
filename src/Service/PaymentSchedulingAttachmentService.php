<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Trait\DocumentUploadTrait;
use Laminas\Diactoros\UploadedFile;

class PaymentSchedulingAttachmentService
{
    use DocumentUploadTrait;

    private const TABLE = 'PaymentSchedulingAttachments';

    /**
     * Upload an attachment for a payment scheduling.
     *
     * @param int $schedulingId Payment scheduling ID.
     * @param \Laminas\Diactoros\UploadedFile $file Uploaded file.
     * @param int|null $uploadedBy User ID.
     * @return object|string Entity on success, error message on failure.
     */
    public function uploadAttachment(
        int $schedulingId,
        UploadedFile $file,
        ?int $uploadedBy,
    ): object|string {
        return $this->uploadAndSave(
            $file,
            self::TABLE,
            'payment_schedulings/' . $schedulingId,
            'ps_',
            [
                'payment_scheduling_id' => $schedulingId,
                'uploaded_by' => $uploadedBy,
            ],
        );
    }

    /**
     * Delete a payment scheduling attachment.
     *
     * @param int $attachmentId Attachment ID.
     * @return bool
     */
    public function deleteAttachment(int $attachmentId): bool
    {
        return $this->deleteDocumentRecord(self::TABLE, $attachmentId);
    }
}

<?php
declare(strict_types=1);

namespace App\Service;

use Laminas\Diactoros\UploadedFile;

class LeaveSignatureService
{
    private const MAX_SIZE = 2 * 1024 * 1024; // 2MB
    private const ALLOWED_MIMES = ['image/png', 'image/jpeg'];

    /**
     * Save signature from an uploaded file.
     *
     * @return string|null Relative path on success, null on failure.
     */
    public function saveFromUpload(int $leaveId, UploadedFile $file, int $userId): ?string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return null;
        }

        $mime = $file->getClientMediaType();
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return null;
        }

        if ($file->getSize() > self::MAX_SIZE) {
            return null;
        }

        $dir = $this->ensureDir($leaveId);
        $ext = $mime === 'image/png' ? 'png' : 'jpg';
        $fileName = "signature_{$userId}_" . time() . ".{$ext}";
        $filePath = $dir . DS . $fileName;

        $file->moveTo($filePath);

        return "uploads/leaves/{$leaveId}/{$fileName}";
    }

    /**
     * Save signature from a base64-encoded data URL (canvas).
     *
     * @return string|null Relative path on success, null on failure.
     */
    public function saveFromBase64(int $leaveId, string $base64Data, int $userId): ?string
    {
        if (!preg_match('/^data:image\/(png|jpeg);base64,/', $base64Data, $matches)) {
            return null;
        }

        $ext = $matches[1] === 'jpeg' ? 'jpg' : 'png';
        $data = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $base64Data));

        if ($data === false || strlen($data) > self::MAX_SIZE) {
            return null;
        }

        $dir = $this->ensureDir($leaveId);
        $fileName = "signature_{$userId}_" . time() . ".{$ext}";
        $filePath = $dir . DS . $fileName;

        file_put_contents($filePath, $data);

        return "uploads/leaves/{$leaveId}/{$fileName}";
    }

    /**
     * @param int $leaveId Leave ID for directory path.
     * @return string Absolute directory path.
     */
    private function ensureDir(int $leaveId): string
    {
        $dir = WWW_ROOT . 'uploads' . DS . 'leaves' . DS . $leaveId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }
}

<?php
declare(strict_types=1);

namespace App\Service;

use Laminas\Diactoros\UploadedFile;

class NoveltySignatureService
{
    private const MAX_SIZE = 2 * 1024 * 1024; // 2MB
    private const ALLOWED_MIMES = ['image/png', 'image/jpeg'];

    /**
     * @param int $noveltyId Novelty ID.
     * @param \Laminas\Diactoros\UploadedFile $file Uploaded file.
     * @param int $userId User ID.
     * @param string $type Signer type.
     * @return string|null
     */
    public function saveFromUpload(int $noveltyId, UploadedFile $file, int $userId, string $type = 'employee'): ?string
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

        $dir = $this->ensureDir($noveltyId);
        $ext = $mime === 'image/png' ? 'png' : 'jpg';
        $fileName = "{$type}_signature_{$userId}_" . time() . ".{$ext}";
        $filePath = $dir . DS . $fileName;

        $file->moveTo($filePath);

        return "uploads/novelties/{$noveltyId}/{$fileName}";
    }

    /**
     * @param int $noveltyId Novelty ID.
     * @param string $base64Data Base64-encoded image data.
     * @param int $userId User ID.
     * @param string $type Signer type.
     * @return string|null
     */
    public function saveFromBase64(int $noveltyId, string $base64Data, int $userId, string $type = 'employee'): ?string
    {
        if (!preg_match('/^data:image\/(png|jpeg);base64,/', $base64Data, $matches)) {
            return null;
        }

        $ext = $matches[1] === 'jpeg' ? 'jpg' : 'png';
        $data = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $base64Data));

        if ($data === false || strlen($data) > self::MAX_SIZE) {
            return null;
        }

        $dir = $this->ensureDir($noveltyId);
        $fileName = "{$type}_signature_{$userId}_" . time() . ".{$ext}";
        $filePath = $dir . DS . $fileName;

        file_put_contents($filePath, $data);

        return "uploads/novelties/{$noveltyId}/{$fileName}";
    }

    /**
     * @param int $noveltyId Novelty ID.
     * @return string
     */
    private function ensureDir(int $noveltyId): string
    {
        $dir = WWW_ROOT . 'uploads' . DS . 'novelties' . DS . $noveltyId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }
}

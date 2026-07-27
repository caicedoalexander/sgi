<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\AssetConstants;
use App\Service\AssetDocumentService;
use App\Test\Factory\AssetFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\UploadedFile;

final class AssetDocumentServiceTest extends TestCase
{
    /**
     * Archivos a limpiar tras cada test.
     *
     * @var array<int, string>
     */
    private array $createdPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->createdPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    private function makePdfUpload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'itamdoc');
        file_put_contents($tmp, "%PDF-1.4\n%minimal acta\n");

        return new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, 'acta.pdf', 'application/pdf');
    }

    public function testUploadStoresFileOutsideWebrootAndPersistsRow(): void
    {
        $asset = AssetFactory::new()->save();
        $user = UserFactory::new()->save();
        $service = new AssetDocumentService();

        $result = $service->uploadDocument(
            $asset->id,
            $this->makePdfUpload(),
            AssetConstants::DOCTYPE_ACTA,
            null,
            $user->id,
        );

        $this->assertTrue($result->success);
        $document = $result->data;
        $this->createdPaths[] = $service->resolveStoragePath($document->file_path);

        $this->assertStringContainsString('storage' . DIRECTORY_SEPARATOR . 'assets', $service->resolveStoragePath($document->file_path));
        $this->assertFileExists($service->resolveStoragePath($document->file_path));
        $this->assertSame('application/pdf', $document->mime_type);

        $persisted = $this->fetchTable('AssetDocuments')->get($document->id);
        $this->assertSame($asset->id, $persisted->asset_id);
        $this->assertSame(AssetConstants::DOCTYPE_ACTA, $persisted->document_type);
    }

    public function testUploadRejectsInvalidDocumentType(): void
    {
        $asset = AssetFactory::new()->save();
        $user = UserFactory::new()->save();
        $service = new AssetDocumentService();

        $result = $service->uploadDocument($asset->id, $this->makePdfUpload(), 'no_existe', null, $user->id);

        $this->assertFalse($result->success);
    }

    public function testDeleteRemovesRowAndFile(): void
    {
        $asset = AssetFactory::new()->save();
        $user = UserFactory::new()->save();
        $service = new AssetDocumentService();

        $document = $service->uploadDocument($asset->id, $this->makePdfUpload(), AssetConstants::DOCTYPE_ACTA, null, $user->id)->data;
        $absolute = $service->resolveStoragePath($document->file_path);

        $result = $service->deleteDocument($asset->id, $document->id);

        $this->assertTrue($result->success);
        $this->assertFileDoesNotExist($absolute);
        $this->assertFalse($this->fetchTable('AssetDocuments')->exists(['id' => $document->id]));
    }

    public function testUploadRejectsMimeMismatch(): void
    {
        $asset = AssetFactory::new()->save();
        $user = UserFactory::new()->save();
        $service = new AssetDocumentService();

        $tmp = tempnam(sys_get_temp_dir(), 'itamspoof');
        file_put_contents($tmp, "esto no es un PDF, es texto plano\n");
        $spoof = new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, 'fake.pdf', 'application/pdf');

        $result = $service->uploadDocument(
            $asset->id,
            $spoof,
            AssetConstants::DOCTYPE_ACTA,
            null,
            $user->id,
        );

        $this->assertFalse($result->success);
        // El archivo temporal no debe quedar persistido (la validación MIME lo descarta).
        $this->assertCount(0, $this->fetchTable('AssetDocuments')->find()->where(['asset_id' => $asset->id])->all()->toArray());
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

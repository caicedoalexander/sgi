<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Model\Entity\AdvanceLegalizationDocument;
use App\Service\AdvanceLegalizationDocumentService;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\UploadedFile;

final class AdvanceLegalizationDocumentServiceTest extends TestCase
{
    /**
     * @var array<int,string>
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

    private function service(): AdvanceLegalizationDocumentService
    {
        return new AdvanceLegalizationDocumentService();
    }

    private function makePdfUpload(string $name = 'soporte.pdf'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'legdoc');
        file_put_contents($tmp, "%PDF-1.4\n%minimal\n");

        return new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, $name, 'application/pdf');
    }

    private function seedLeg(): int
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        return (int)$leg->id;
    }

    public function testUploadDocumentCreatesRow(): void
    {
        $legId = $this->seedLeg();
        $user = UserFactory::new()->save();

        $result = $this->service()->uploadDocument($legId, $this->makePdfUpload(), (int)$user->id);

        $this->assertInstanceOf(AdvanceLegalizationDocument::class, $result);
        $this->createdPaths[] = WWW_ROOT . str_replace('/', DS, $result->file_path);

        $this->assertSame($legId, $result->legalization_id);
        $this->assertSame((int)$user->id, $result->uploaded_by);
        $this->assertNull($result->document_type);
        $this->assertSame('soporte.pdf', $result->file_name);
    }

    public function testDeleteDocumentRemovesRow(): void
    {
        $legId = $this->seedLeg();
        $doc = $this->service()->uploadDocument($legId, $this->makePdfUpload(), null);
        $this->createdPaths[] = WWW_ROOT . str_replace('/', DS, $doc->file_path);

        $deleted = $this->service()->deleteDocument((int)$doc->id, $legId);

        $this->assertTrue($deleted);
        $this->assertFalse(
            TableRegistry::getTableLocator()->get('AdvanceLegalizationDocuments')->exists(['id' => $doc->id]),
        );
    }

    public function testDeleteDocumentIsAntiIdor(): void
    {
        $legId = $this->seedLeg();
        $otherLegId = $this->seedLeg();
        $doc = $this->service()->uploadDocument($legId, $this->makePdfUpload(), null);
        $this->createdPaths[] = WWW_ROOT . str_replace('/', DS, $doc->file_path);

        // Intentar borrar el doc de legId pasando otherLegId → no debe borrar.
        $deleted = $this->service()->deleteDocument((int)$doc->id, $otherLegId);

        $this->assertFalse($deleted);
        $this->assertTrue(
            TableRegistry::getTableLocator()->get('AdvanceLegalizationDocuments')->exists(['id' => $doc->id]),
        );
    }
}

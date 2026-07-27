<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\User;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\ProviderFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\UploadedFile;

final class AdvancesLegalizationDocumentTest extends TestCase
{
    use IntegrationTestTrait;

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

    private function _seedOperator(string $step): User
    {
        $role = RoleFactory::new()->save();

        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'advances',
            'can_view' => true,
            'can_edit' => true,
        ]));

        $pipelinePermissions = TableRegistry::getTableLocator()->get('PipelinePermissions');
        $pipelinePermissions->saveOrFail($pipelinePermissions->newEntity([
            'role_id' => $role->id,
            'pipeline' => PipelineStepConstants::PIPELINE_LEGALIZATIONS,
            'step' => $step,
            'can_operate' => true,
        ]));

        return UserFactory::new(['role_id' => $role->id])->save();
    }

    private function makePdfUpload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'legupload');
        file_put_contents($tmp, "%PDF-1.4\n%minimal\n");

        return new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, 'soporte.pdf', 'application/pdf');
    }

    private function seedLeg(string $status): array
    {
        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus($status)->save();

        return [(int)$anticipo->id, (int)$leg->id];
    }

    public function testUploadCreatesDocumentRow(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD);
        [$anticipoId, $legId] = $this->seedLeg(AdvanceConstants::STATUS_CONTABILIDAD);

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        // El objeto UploadedFile debe viajar por `files`, no por los datos de
        // `post()`: `IntegrationTestTrait::_buildRequest()` fija `files => []`
        // de forma incondicional y solo ese array pasa por
        // `ServerRequestFactory::marshalFiles()` (que alimenta
        // `$request->getUploadedFiles()`). Un `UploadedFile` embebido en el
        // array de `post()` termina en `getParsedBody()`, no en
        // `getUploadedFile()`, y el controller lo leería como "sin archivo".
        $this->configRequest(['files' => ['file' => $this->makePdfUpload()]]);
        $this->post('/advances/upload-legalization-document/' . $anticipoId);

        $rows = TableRegistry::getTableLocator()->get('AdvanceLegalizationDocuments')
            ->find()->where(['legalization_id' => $legId])->all()->toArray();
        $this->assertCount(1, $rows);
        $this->assertSame((int)$user->id, $rows[0]->uploaded_by);
        $this->assertNull($rows[0]->document_type);
        $this->createdPaths[] = WWW_ROOT . str_replace('/', DS, $rows[0]->file_path);
    }

    public function testUploadForbiddenWhenLegalizada(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD);
        [$anticipoId, $legId] = $this->seedLeg(AdvanceConstants::STATUS_LEGALIZADA);

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->configRequest(['files' => ['file' => $this->makePdfUpload()]]);
        $this->post('/advances/upload-legalization-document/' . $anticipoId);

        $this->assertCount(
            0,
            TableRegistry::getTableLocator()->get('AdvanceLegalizationDocuments')
                ->find()->where(['legalization_id' => $legId])->all()->toArray(),
        );
    }

    public function testDeleteIsAntiIdor(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD);
        [, $legId] = $this->seedLeg(AdvanceConstants::STATUS_CONTABILIDAD);
        [$otherAnticipoId] = $this->seedLeg(AdvanceConstants::STATUS_CONTABILIDAD);

        $docs = TableRegistry::getTableLocator()->get('AdvanceLegalizationDocuments');
        $doc = $docs->saveOrFail($docs->newEntity([
            'legalization_id' => $legId,
            'file_path' => 'uploads/advances/' . $legId . '/leg_fake.pdf',
            'file_name' => 'fake.pdf',
            'mime_type' => 'application/pdf',
        ]));

        // Borrar el doc de legId pasando el anticipo de OTRA legalización → no debe borrar.
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/delete-legalization-document/' . $otherAnticipoId . '/' . $doc->id);

        $this->assertTrue($docs->exists(['id' => $doc->id]), 'El doc no debía borrarse (anti-IDOR).');
    }
}

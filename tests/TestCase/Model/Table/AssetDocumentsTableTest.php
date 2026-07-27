<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Constants\AssetConstants;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

final class AssetDocumentsTableTest extends TestCase
{
    public function testRejectsInvalidDocumentType(): void
    {
        $table = TableRegistry::getTableLocator()->get('AssetDocuments');
        $entity = $table->newEntity([
            'asset_id' => 1,
            'document_type' => 'no_existe',
            'name' => 'acta.pdf',
            'file_path' => 'assets/1/acta.pdf',
            'uploaded_by' => 1,
        ]);
        $this->assertArrayHasKey('document_type', $entity->getErrors());
    }

    public function testAcceptsValidActa(): void
    {
        $table = TableRegistry::getTableLocator()->get('AssetDocuments');
        $entity = $table->newEntity([
            'asset_id' => 1,
            'document_type' => AssetConstants::DOCTYPE_ACTA,
            'name' => 'acta.pdf',
            'file_path' => 'assets/1/acta.pdf',
            'uploaded_by' => 1,
        ]);
        $this->assertSame([], $entity->getErrors());
    }
}

<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

final class AdvanceLegalizationDocumentsTableTest extends TestCase
{
    public function testSavesAndContainsAssociations(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationDocuments');
        $doc = $table->newEntity([
            'legalization_id' => $leg->id,
            'file_path' => 'uploads/advances/' . $leg->id . '/leg_test.pdf',
            'file_name' => 'soporte.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'uploaded_by' => $user->id,
        ]);
        $this->assertNotFalse($table->save($doc), 'La fila de soporte debería guardarse.');

        $reloaded = $table->get($doc->id, contain: ['AdvanceLegalizations', 'UploadedByUsers']);
        $this->assertSame($leg->id, $reloaded->legalization_id);
        $this->assertSame($leg->id, $reloaded->advance_legalization->id);
        $this->assertSame($user->id, $reloaded->uploaded_by_user->id);
        $this->assertNull($reloaded->document_type);
    }
}

<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use Cake\TestSuite\TestCase;

final class AdvanceLegalizationDocumentsPredicateTest extends TestCase
{
    public function testCanManageDocumentsTrueInOperableState(): void
    {
        $leg = new AdvanceLegalization();
        $leg->status = AdvanceConstants::STATUS_CONTABILIDAD;
        $this->assertTrue($leg->canManageDocuments());
    }

    public function testCanManageDocumentsFalseWhenLegalizada(): void
    {
        $leg = new AdvanceLegalization();
        $leg->status = AdvanceConstants::STATUS_LEGALIZADA;
        $this->assertFalse($leg->canManageDocuments());
    }
}

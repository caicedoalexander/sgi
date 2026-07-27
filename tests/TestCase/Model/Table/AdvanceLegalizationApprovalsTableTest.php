<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\AdvanceLegalizationApprovalsTable;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class AdvanceLegalizationApprovalsTableTest extends TestCase
{
    public function testTableConfiguration(): void
    {
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationApprovals');
        $this->assertInstanceOf(AdvanceLegalizationApprovalsTable::class, $table);
        $this->assertSame('advance_legalization_approvals', $table->getTable());
        $this->assertTrue($table->hasAssociation('AdvanceLegalizations'));
        $this->assertTrue($table->hasAssociation('Users'));
    }

    public function testTokenHashIsHidden(): void
    {
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationApprovals');
        $entity = $table->newEntity([
            'advance_legalization_id' => 1,
            'user_id' => 1,
            'status' => 'Pendiente',
            'token_hash' => 'abc',
        ]);
        $this->assertArrayNotHasKey('token_hash', $entity->toArray());
    }
}

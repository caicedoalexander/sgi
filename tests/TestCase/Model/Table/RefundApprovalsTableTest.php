<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\RefundApprovalsTable;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class RefundApprovalsTableTest extends TestCase
{
    public function testTableConfiguration(): void
    {
        $table = TableRegistry::getTableLocator()->get('RefundApprovals');
        $this->assertInstanceOf(RefundApprovalsTable::class, $table);
        $this->assertSame('refund_approvals', $table->getTable());
        $this->assertTrue($table->hasAssociation('Refunds'));
        $this->assertTrue($table->hasAssociation('Users'));
    }

    public function testTokenHashIsHidden(): void
    {
        $table = TableRegistry::getTableLocator()->get('RefundApprovals');
        $entity = $table->newEntity(['refund_id' => 1, 'user_id' => 1, 'status' => 'Pendiente', 'token_hash' => 'abc']);
        $this->assertArrayNotHasKey('token_hash', $entity->toArray());
    }
}

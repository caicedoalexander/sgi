<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Constants\AssetConstants;
use App\Constants\ConsumableConstants;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

final class ConsumableMovementsTableTest extends TestCase
{
    public function testRejectsInvalidMovementType(): void
    {
        $table = TableRegistry::getTableLocator()->get('ConsumableMovements');
        $entity = $table->newEntity([
            'consumable_id' => 1,
            'movement_type' => 'no_existe',
            'quantity' => 1,
            'balance_after' => 1,
            'movement_date' => '2026-06-19 10:00:00',
            'performed_by_user_id' => 1,
            'source' => AssetConstants::SOURCE_WEB,
        ]);
        $this->assertArrayHasKey('movement_type', $entity->getErrors());
    }

    public function testRejectsMissingSource(): void
    {
        $table = TableRegistry::getTableLocator()->get('ConsumableMovements');
        $entity = $table->newEntity([
            'consumable_id' => 1,
            'movement_type' => ConsumableConstants::MOVEMENT_INGRESO,
            'quantity' => 1,
            'balance_after' => 1,
            'movement_date' => '2026-06-19 10:00:00',
            'performed_by_user_id' => 1,
            // source omitted on purpose
        ]);
        $this->assertArrayHasKey('source', $entity->getErrors());
    }
}

<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Constants\AssetConstants;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

final class AssetMovementsTableTest extends TestCase
{
    public function testRejectsInvalidMovementType(): void
    {
        $table = TableRegistry::getTableLocator()->get('AssetMovements');
        $entity = $table->newEntity([
            'asset_id' => 1,
            'movement_type' => 'no_existe',
            'movement_date' => '2026-06-19 10:00:00',
            'performed_by_user_id' => 1,
            'source' => AssetConstants::SOURCE_WEB,
        ]);
        $this->assertArrayHasKey('movement_type', $entity->getErrors());
    }

    public function testAcceptsValidMovement(): void
    {
        $table = TableRegistry::getTableLocator()->get('AssetMovements');
        $entity = $table->newEntity([
            'asset_id' => 1,
            'movement_type' => AssetConstants::MOVEMENT_ENTREGA,
            'movement_date' => '2026-06-19 10:00:00',
            'performed_by_user_id' => 1,
            'acta_status' => AssetConstants::ACTA_PENDIENTE,
            'source' => AssetConstants::SOURCE_WEB,
        ]);
        $this->assertSame([], $entity->getErrors());
    }

    public function testRejectsMissingSource(): void
    {
        $table = TableRegistry::getTableLocator()->get('AssetMovements');
        $entity = $table->newEntity([
            'asset_id' => 1,
            'movement_type' => AssetConstants::MOVEMENT_ENTREGA,
            'movement_date' => '2026-06-19 10:00:00',
            'performed_by_user_id' => 1,
            // source omitted on purpose
        ]);
        $this->assertArrayHasKey('source', $entity->getErrors());
    }
}

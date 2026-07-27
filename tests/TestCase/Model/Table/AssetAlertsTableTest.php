<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Constants\AssetAlertConstants;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

final class AssetAlertsTableTest extends TestCase
{
    public function testRejectsInvalidType(): void
    {
        $table = TableRegistry::getTableLocator()->get('AssetAlerts');
        $entity = $table->newEntity([
            'alert_type' => 'no_existe',
            'message' => 'x',
            'priority' => AssetAlertConstants::PRIORITY_MEDIA,
            'status' => AssetAlertConstants::STATUS_ABIERTA,
        ]);
        $this->assertArrayHasKey('alert_type', $entity->getErrors());
    }

    public function testFindOpenReturnsOnlyOpen(): void
    {
        $table = TableRegistry::getTableLocator()->get('AssetAlerts');

        // Create open alert
        $open = $table->newEntity([
            'alert_type' => AssetAlertConstants::TYPE_STOCK_BAJO,
            'message' => 'Stock bajo en tóner',
            'priority' => AssetAlertConstants::PRIORITY_ALTA,
        ]);
        $open->setAccess('status', true);
        $open->status = AssetAlertConstants::STATUS_ABIERTA;
        $table->saveOrFail($open);

        // Create resolved alert
        $resolved = $table->newEntity([
            'alert_type' => AssetAlertConstants::TYPE_STOCK_BAJO,
            'message' => 'Resuelta',
            'priority' => AssetAlertConstants::PRIORITY_BAJA,
        ]);
        $resolved->setAccess('status', true);
        $resolved->status = AssetAlertConstants::STATUS_RESUELTA;
        $table->saveOrFail($resolved);

        $this->assertCount(1, $table->find('open')->all());
    }
}

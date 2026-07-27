<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\ConsumableConstants;
use App\Service\ConsumableStockService;
use App\Test\Factory\ConsumableFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

final class ConsumableStockServiceTest extends TestCase
{
    private function service(): ConsumableStockService
    {
        return new ConsumableStockService();
    }

    public function testIngressIncreasesStockAndRecordsBalance(): void
    {
        $consumable = ConsumableFactory::new()->withStock(10, 2)->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->registerIngress($consumable->id, 5, [], $user->id);

        $this->assertTrue($result->success);
        $this->assertSame(15, $this->fetchTable('Consumables')->get($consumable->id)->current_stock);

        $movement = $this->fetchTable('ConsumableMovements')->find()->where(['consumable_id' => $consumable->id])->firstOrFail();
        $this->assertSame(ConsumableConstants::MOVEMENT_INGRESO, $movement->movement_type);
        $this->assertSame(15, $movement->balance_after);
    }

    public function testOutputDecreasesStock(): void
    {
        $consumable = ConsumableFactory::new()->withStock(10, 2)->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->registerOutput($consumable->id, 4, [], $user->id);

        $this->assertTrue($result->success);
        $this->assertSame(6, $this->fetchTable('Consumables')->get($consumable->id)->current_stock);
    }

    public function testOutputFailsWhenInsufficientStock(): void
    {
        $consumable = ConsumableFactory::new()->withStock(3, 2)->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->registerOutput($consumable->id, 5, [], $user->id);

        $this->assertFalse($result->success);
        $this->assertSame(3, $this->fetchTable('Consumables')->get($consumable->id)->current_stock);
        $this->assertCount(0, $this->fetchTable('ConsumableMovements')->find()->where(['consumable_id' => $consumable->id])->all()->toArray());
    }

    public function testAdjustSetsAbsoluteStock(): void
    {
        $consumable = ConsumableFactory::new()->withStock(10, 2)->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->adjust($consumable->id, 7, ['reason' => 'Conteo físico'], $user->id);

        $this->assertTrue($result->success);
        $this->assertSame(7, $this->fetchTable('Consumables')->get($consumable->id)->current_stock);

        $movement = $this->fetchTable('ConsumableMovements')->find()->where(['consumable_id' => $consumable->id])->firstOrFail();
        $this->assertSame(ConsumableConstants::MOVEMENT_AJUSTE, $movement->movement_type);
        $this->assertSame(-3, $movement->quantity);
        $this->assertSame(7, $movement->balance_after);
    }
}

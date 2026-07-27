<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\AssetConstants;
use App\Service\AssetInventoryService;
use App\Test\Factory\AssetFactory;
use App\Test\Factory\EmployeeFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

final class AssetInventoryServiceTest extends TestCase
{
    private function service(): AssetInventoryService
    {
        return new AssetInventoryService();
    }

    public function testRegisterIngressLogsMovementAndKeepsAvailable(): void
    {
        $asset = AssetFactory::new()->withStatus(AssetConstants::STATUS_DISPONIBLE)->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->registerIngress($asset->id, ['reason' => 'Compra inicial'], $user->id);

        $this->assertTrue($result->success);
        $persisted = $this->fetchTable('Assets')->get($asset->id);
        $this->assertSame(AssetConstants::STATUS_DISPONIBLE, $persisted->status);

        $movements = $this->fetchTable('AssetMovements')->find()->where(['asset_id' => $asset->id])->all()->toArray();
        $this->assertCount(1, $movements);
        $this->assertSame(AssetConstants::MOVEMENT_INGRESO, $movements[0]->movement_type);
        $this->assertNull($movements[0]->acta_status);
    }

    public function testAssignSetsResponsibleStatusAndPendingActa(): void
    {
        $asset = AssetFactory::new()->withStatus(AssetConstants::STATUS_DISPONIBLE)->save();
        $employee = EmployeeFactory::new()->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->assign($asset->id, $employee->id, [], $user->id);

        $this->assertTrue($result->success);
        $persisted = $this->fetchTable('Assets')->get($asset->id);
        $this->assertSame(AssetConstants::STATUS_ASIGNADO, $persisted->status);
        $this->assertSame($employee->id, $persisted->responsible_employee_id);

        $movement = $this->fetchTable('AssetMovements')->find()->where(['asset_id' => $asset->id])->firstOrFail();
        $this->assertSame(AssetConstants::MOVEMENT_ENTREGA, $movement->movement_type);
        $this->assertSame($employee->id, $movement->to_employee_id);
        $this->assertSame(AssetConstants::ACTA_PENDIENTE, $movement->acta_status);
    }

    public function testAssignFailsWhenAssetNotAvailable(): void
    {
        $asset = AssetFactory::new()->withStatus(AssetConstants::STATUS_ASIGNADO)->save();
        $employee = EmployeeFactory::new()->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->assign($asset->id, $employee->id, [], $user->id);

        $this->assertFalse($result->success);
        $this->assertCount(0, $this->fetchTable('AssetMovements')->find()->where(['asset_id' => $asset->id])->all()->toArray());
    }
}

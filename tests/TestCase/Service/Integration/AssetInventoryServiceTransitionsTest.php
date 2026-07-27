<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\AssetConstants;
use App\Service\AssetInventoryService;
use App\Test\Factory\AssetFactory;
use App\Test\Factory\EmployeeFactory;
use App\Test\Factory\OperationCenterFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

final class AssetInventoryServiceTransitionsTest extends TestCase
{
    private function service(): AssetInventoryService
    {
        return new AssetInventoryService();
    }

    public function testLendSetsPrestado(): void
    {
        $asset = AssetFactory::new()->withStatus(AssetConstants::STATUS_DISPONIBLE)->save();
        $employee = EmployeeFactory::new()->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->lend($asset->id, $employee->id, [], $user->id);

        $this->assertTrue($result->success);
        $persisted = $this->fetchTable('Assets')->get($asset->id);
        $this->assertSame(AssetConstants::STATUS_PRESTADO, $persisted->status);
        $this->assertSame($employee->id, $persisted->responsible_employee_id);
    }

    public function testReturnClearsResponsibleAndSetsAvailable(): void
    {
        $employee = EmployeeFactory::new()->save();
        $asset = AssetFactory::new()
            ->withStatus(AssetConstants::STATUS_ASIGNADO)
            ->withResponsible($employee->id)
            ->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->returnAsset($asset->id, [], $user->id);

        $this->assertTrue($result->success);
        $persisted = $this->fetchTable('Assets')->get($asset->id);
        $this->assertSame(AssetConstants::STATUS_DISPONIBLE, $persisted->status);
        $this->assertNull($persisted->responsible_employee_id);

        $movement = $this->fetchTable('AssetMovements')->find()->where(['asset_id' => $asset->id])->firstOrFail();
        $this->assertSame($employee->id, $movement->from_employee_id);
        $this->assertSame(AssetConstants::ACTA_PENDIENTE, $movement->acta_status);
    }

    public function testReturnFailsWhenAvailable(): void
    {
        $asset = AssetFactory::new()->withStatus(AssetConstants::STATUS_DISPONIBLE)->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->returnAsset($asset->id, [], $user->id);
        $this->assertFalse($result->success);
    }

    public function testTransferChangesOperationCenterWithoutStatusChange(): void
    {
        $origin = OperationCenterFactory::new()->save();
        $destination = OperationCenterFactory::new()->save();
        $asset = AssetFactory::new()
            ->withStatus(AssetConstants::STATUS_ASIGNADO)
            ->withOperationCenter($origin->id)
            ->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->transfer($asset->id, $destination->id, [], $user->id);

        $this->assertTrue($result->success);
        $persisted = $this->fetchTable('Assets')->get($asset->id);
        $this->assertSame($destination->id, $persisted->operation_center_id);
        $this->assertSame(AssetConstants::STATUS_ASIGNADO, $persisted->status);

        $movement = $this->fetchTable('AssetMovements')->find()->where(['asset_id' => $asset->id])->firstOrFail();
        $this->assertSame($origin->id, $movement->from_operation_center_id);
        $this->assertSame($destination->id, $movement->to_operation_center_id);
        $this->assertNull($movement->acta_status);
    }

    public function testDisposeMakesTerminalAndClearsResponsible(): void
    {
        $employee = EmployeeFactory::new()->save();
        $asset = AssetFactory::new()
            ->withStatus(AssetConstants::STATUS_ASIGNADO)
            ->withResponsible($employee->id)
            ->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->dispose($asset->id, ['reason' => 'Obsoleto'], $user->id);

        $this->assertTrue($result->success);
        $persisted = $this->fetchTable('Assets')->get($asset->id);
        $this->assertSame(AssetConstants::STATUS_DADO_DE_BAJA, $persisted->status);
        $this->assertNull($persisted->responsible_employee_id);

        // Terminal: no se puede volver a operar.
        $again = $this->service()->dispose($asset->id, [], $user->id);
        $this->assertFalse($again->success);
    }

    public function testLendFailsWhenNotAvailable(): void
    {
        $asset = AssetFactory::new()->withStatus(AssetConstants::STATUS_ASIGNADO)->save();
        $employee = EmployeeFactory::new()->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->lend($asset->id, $employee->id, [], $user->id);
        $this->assertFalse($result->success);
    }

    public function testTransferFailsWhenDadoDeBaja(): void
    {
        $asset = AssetFactory::new()->withStatus(AssetConstants::STATUS_DADO_DE_BAJA)->save();
        $destination = OperationCenterFactory::new()->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->transfer($asset->id, $destination->id, [], $user->id);
        $this->assertFalse($result->success);
    }
}

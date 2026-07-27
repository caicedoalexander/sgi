<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\AssetAlertConstants;
use App\Constants\AssetConstants;
use App\Service\AssetAlertService;
use App\Test\Factory\AssetFactory;
use App\Test\Factory\AssetMovementFactory;
use App\Test\Factory\ConsumableFactory;
use Cake\TestSuite\TestCase;

final class AssetAlertServiceTest extends TestCase
{
    private function service(): AssetAlertService
    {
        return new AssetAlertService();
    }

    public function testLowStockCreatesAlertAndDoesNotDuplicate(): void
    {
        ConsumableFactory::new()->withStock(1, 5)->save();

        $first = $this->service()->generate();
        $second = $this->service()->generate();

        $alerts = $this->fetchTable('AssetAlerts')
            ->find()->where(['alert_type' => AssetAlertConstants::TYPE_STOCK_BAJO])->all()->toArray();

        $this->assertGreaterThanOrEqual(1, $first['created']);
        $this->assertCount(1, $alerts, 'No debe duplicar la alerta de stock bajo en la segunda corrida.');
        $this->assertSame(0, $this->_createdOfType($second, AssetAlertConstants::TYPE_STOCK_BAJO));
    }

    public function testAssignedAssetWithoutResponsibleCreatesAlert(): void
    {
        AssetFactory::new()->withStatus(AssetConstants::STATUS_ASIGNADO)->save(); // sin responsable

        $this->service()->generate();

        $this->assertSame(1, $this->fetchTable('AssetAlerts')
            ->find()->where(['alert_type' => AssetAlertConstants::TYPE_ACTIVO_SIN_RESPONSABLE])->count());
    }

    public function testOldPendingActaCreatesAlert(): void
    {
        $asset = AssetFactory::new()->save();
        AssetMovementFactory::new()->forAsset($asset->id)
            ->withType(AssetConstants::MOVEMENT_ENTREGA)
            ->withActaStatus(AssetConstants::ACTA_PENDIENTE)
            ->withMovementDate(date('Y-m-d H:i:s', strtotime('-5 days')))
            ->save();

        $this->service()->generate();

        $this->assertSame(1, $this->fetchTable('AssetAlerts')
            ->find()->where(['alert_type' => AssetAlertConstants::TYPE_ACTA_PENDIENTE])->count());
    }

    public function testOverdueActaIsMarkedVencida(): void
    {
        $asset = AssetFactory::new()->save();
        AssetMovementFactory::new()->forAsset($asset->id)
            ->withType(AssetConstants::MOVEMENT_ENTREGA)
            ->withActaStatus(AssetConstants::ACTA_PENDIENTE)
            ->withMovementDate(date('Y-m-d H:i:s', strtotime('-20 days')))
            ->save();

        $stats = $this->service()->generate();

        $this->assertGreaterThanOrEqual(1, $stats['overdue']);
        $alert = $this->fetchTable('AssetAlerts')->find()
            ->where(['alert_type' => AssetAlertConstants::TYPE_ACTA_PENDIENTE])
            ->firstOrFail();
        $this->assertSame(AssetAlertConstants::STATUS_VENCIDA, $alert->status);
    }

    public function testIncompleteAssetWithoutSerialCreatesAlert(): void
    {
        AssetFactory::new()->setField('serial_number', null)->save();

        $this->service()->generate();

        $this->assertGreaterThanOrEqual(1, $this->fetchTable('AssetAlerts')
            ->find()->where(['alert_type' => AssetAlertConstants::TYPE_REGISTRO_INCOMPLETO])->count());
    }

    /**
     * Helper: cuántas alertas de un tipo se crearon en una corrida (aprox vía
     * recuento total; usado solo para aserción de no-duplicación).
     */
    private function _createdOfType(array $stats, string $type): int
    {
        return $stats['created_by_type'][$type] ?? 0;
    }
}

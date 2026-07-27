<?php
declare(strict_types=1);

namespace App\Test\TestCase\Command;

use App\Constants\AssetConstants;
use App\Test\Factory\AssetFactory;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class ItamGenerateAlertsCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    public function testRunGeneratesAlertsAndReportsStats(): void
    {
        AssetFactory::new()
            ->withStatus(AssetConstants::STATUS_ASIGNADO)
            ->setField('serial_number', 'SN-12345')
            ->save(); // sin responsable pero con serial_number

        $this->exec('itam_generate_alerts');

        $this->assertExitSuccess();
        $this->assertOutputContains('Alertas');
        $this->assertSame(1, $this->fetchTable('AssetAlerts')->find()->count());
    }
}

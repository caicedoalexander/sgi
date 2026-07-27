<?php
declare(strict_types=1);

namespace App\Test\TestCase\Constants;

use App\Constants\AssetAlertConstants;
use App\Constants\AssetConstants;
use App\Constants\ConsumableConstants;
use App\Constants\Domain\Asset\AssetStatus;
use PHPUnit\Framework\TestCase;

final class AssetConstantsTest extends TestCase
{
    public function testStatusConstantsDelegateToEnum(): void
    {
        $this->assertSame(AssetStatus::DISPONIBLE->value, AssetConstants::STATUS_DISPONIBLE);
        $this->assertSame('dado_de_baja', AssetConstants::STATUS_DADO_DE_BAJA);
    }

    public function testStatusesArrayMatchesEnumValues(): void
    {
        $this->assertSame(AssetStatus::values(), AssetConstants::STATUSES);
    }

    public function testStatusLabelsCoverEveryStatus(): void
    {
        foreach (AssetConstants::STATUSES as $status) {
            $this->assertArrayHasKey($status, AssetConstants::STATUS_LABELS);
        }
    }

    public function testCodePrefix(): void
    {
        $this->assertSame('ACT', AssetConstants::CODE_PREFIX);
    }

    public function testConsumableAndAlertConstants(): void
    {
        $this->assertSame('salida', ConsumableConstants::MOVEMENT_SALIDA);
        $this->assertSame('stock_bajo', AssetAlertConstants::TYPE_STOCK_BAJO);
        $this->assertSame('abierta', AssetAlertConstants::STATUS_ABIERTA);
        $this->assertContains('media', AssetAlertConstants::PRIORITIES);
    }
}

<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Constants\AssetConstants;
use App\Model\Entity\Asset;
use App\Model\Entity\AssetCategory;
use App\View\Presentation\AssetPresentation;
use PHPUnit\Framework\TestCase;

final class AssetPresentationTest extends TestCase
{
    public function testStatusBadgesCoverEveryStatus(): void
    {
        foreach (AssetConstants::STATUSES as $status) {
            $this->assertArrayHasKey($status, AssetPresentation::STATUS_BADGES);
            $this->assertArrayHasKey($status, AssetPresentation::STATUS_ICONS);
        }
    }

    public function testForRowDerivesLabelsAndAssociations(): void
    {
        $asset = new Asset([
            'code' => 'ACT-26-001-0001',
            'status' => AssetConstants::STATUS_ASIGNADO,
            'asset_category' => new AssetCategory(['name' => 'Portátil']),
        ]);

        $row = AssetPresentation::forRow($asset);

        $this->assertSame('Asignado', $row->statusLabel);
        $this->assertSame('pill-info-soft', $row->statusBadgeClass);
        $this->assertSame('Portátil', $row->categoryName);
        $this->assertSame('—', $row->responsibleName);
    }
}

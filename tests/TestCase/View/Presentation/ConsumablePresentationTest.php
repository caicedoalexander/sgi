<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Model\Entity\Consumable;
use App\View\Presentation\ConsumablePresentation;
use PHPUnit\Framework\TestCase;

final class ConsumablePresentationTest extends TestCase
{
    public function testStockBadgeFlagsLowStock(): void
    {
        $low = new Consumable(['current_stock' => 2, 'minimum_stock' => 5]);
        $ok = new Consumable(['current_stock' => 9, 'minimum_stock' => 5]);

        $this->assertSame('pill-danger-soft', ConsumablePresentation::stockBadge($low)[1]);
        $this->assertSame('pill-accent-soft', ConsumablePresentation::stockBadge($ok)[1]);
    }
}

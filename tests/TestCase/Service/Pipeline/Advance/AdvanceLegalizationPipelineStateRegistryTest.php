<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Advance;

use App\Constants\Domain\Advance\PipelineStatus;
use App\Service\Pipeline\Advance\AdvanceLegalizationPipelineStateRegistry;
use PHPUnit\Framework\TestCase;

final class AdvanceLegalizationPipelineStateRegistryTest extends TestCase
{
    public function testRegistryHasEightStates(): void
    {
        $registry = new AdvanceLegalizationPipelineStateRegistry();
        $this->assertCount(8, $registry->all());
    }

    public function testGetResolvesEveryEnumCase(): void
    {
        $registry = new AdvanceLegalizationPipelineStateRegistry();
        foreach (PipelineStatus::cases() as $case) {
            $this->assertSame($case, $registry->get($case)->getStatus(), $case->value);
        }
    }
}

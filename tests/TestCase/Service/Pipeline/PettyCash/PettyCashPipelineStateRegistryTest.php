<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\PettyCash;

use App\Constants\Domain\PettyCash\PipelineStatus;
use App\Service\Pipeline\PettyCash\PettyCashPipelineStateRegistry;
use PHPUnit\Framework\TestCase;

final class PettyCashPipelineStateRegistryTest extends TestCase
{
    public function testRegistryHasSixStates(): void
    {
        $registry = new PettyCashPipelineStateRegistry();
        $this->assertCount(6, $registry->all());
    }

    public function testGetMapsEachEnumCase(): void
    {
        $registry = new PettyCashPipelineStateRegistry();
        foreach (PipelineStatus::cases() as $case) {
            $this->assertSame($case, $registry->get($case)->getStatus());
        }
    }
}

<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Refund;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Service\Pipeline\Refund\RefundPipelineStateRegistry;
use PHPUnit\Framework\TestCase;

final class RefundPipelineStateRegistryTest extends TestCase
{
    public function testRegistryHasSixStates(): void
    {
        $registry = new RefundPipelineStateRegistry();
        $this->assertCount(6, $registry->all());
    }

    public function testGetReturnsStateForEveryEnumCase(): void
    {
        $registry = new RefundPipelineStateRegistry();
        foreach (PipelineStatus::cases() as $case) {
            $state = $registry->get($case);
            $this->assertSame($case, $state->getStatus(), "Registry maps {$case->value} → wrong state");
        }
    }

    public function testKeysMatchEnumValues(): void
    {
        $registry = new RefundPipelineStateRegistry();
        $keys = array_keys($registry->all());
        sort($keys);
        $expected = array_map(fn (PipelineStatus $s) => $s->value, PipelineStatus::cases());
        sort($expected);
        $this->assertSame($expected, $keys);
    }
}

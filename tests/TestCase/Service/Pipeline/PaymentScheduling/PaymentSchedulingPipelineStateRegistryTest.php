<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\PaymentScheduling;

use App\Constants\Domain\PaymentScheduling\PipelineStatus;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineStateRegistry;
use PHPUnit\Framework\TestCase;

final class PaymentSchedulingPipelineStateRegistryTest extends TestCase
{
    public function testRegistryHasFiveStates(): void
    {
        $registry = new PaymentSchedulingPipelineStateRegistry();
        $this->assertCount(5, $registry->all());
    }

    public function testEveryEnumCaseResolvesToMatchingState(): void
    {
        $registry = new PaymentSchedulingPipelineStateRegistry();
        foreach (PipelineStatus::cases() as $case) {
            $this->assertSame($case, $registry->get($case)->getStatus());
        }
    }
}

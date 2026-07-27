<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\HealthCheck;

use App\Service\HealthCheck\HealthCheckResult;
use App\Service\HealthCheck\HealthStatus;
use PHPUnit\Framework\TestCase;

final class HealthCheckResultTest extends TestCase
{
    public function testReadOnlyFields(): void
    {
        $r = new HealthCheckResult(
            name: 'database',
            status: HealthStatus::OK,
            critical: true,
            details: ['latency_ms' => 12],
        );

        $this->assertSame('database', $r->name);
        $this->assertSame(HealthStatus::OK, $r->status);
        $this->assertTrue($r->critical);
        $this->assertSame(['latency_ms' => 12], $r->details);
    }

    public function testDetailsDefaultsToEmptyArray(): void
    {
        $r = new HealthCheckResult(name: 'cache', status: HealthStatus::FAIL, critical: false);
        $this->assertSame([], $r->details);
    }
}

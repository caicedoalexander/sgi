<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\HealthCheck;

use App\Service\HealthCheck\HealthStatus;
use PHPUnit\Framework\TestCase;

final class HealthStatusTest extends TestCase
{
    public function testConstantValues(): void
    {
        $this->assertSame('ok', HealthStatus::OK);
        $this->assertSame('fail', HealthStatus::FAIL);
        $this->assertSame('degraded', HealthStatus::DEGRADED);
    }
}

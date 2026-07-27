<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Resilience;

use App\Service\Resilience\RetryPolicy;
use Exception;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class RetryPolicyTest extends TestCase
{
    public function testDefaultsAreThreeAttemptsAndOneSecondDelay(): void
    {
        $p = RetryPolicy::default();
        $this->assertSame(3, $p->maxAttempts);
        $this->assertSame(1000, $p->baseDelayMs);
        $this->assertSame([Exception::class], $p->retriableExceptions);
    }

    public function testNoRetryHasZeroAttempts(): void
    {
        $p = RetryPolicy::noRetry();
        $this->assertSame(0, $p->maxAttempts);
    }

    public function testCustomConstruction(): void
    {
        $p = new RetryPolicy(
            maxAttempts: 5,
            baseDelayMs: 100,
            retriableExceptions: [RuntimeException::class],
        );
        $this->assertSame(5, $p->maxAttempts);
        $this->assertSame(100, $p->baseDelayMs);
        $this->assertSame([RuntimeException::class], $p->retriableExceptions);
    }

    public function testFieldsAreReadOnly(): void
    {
        $ref = new ReflectionClass(RetryPolicy::class);
        foreach (['maxAttempts', 'baseDelayMs', 'retriableExceptions'] as $prop) {
            $this->assertTrue($ref->getProperty($prop)->isReadOnly());
        }
    }
}

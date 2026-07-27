<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Resilience;

use App\Service\Resilience\Retryer;
use App\Service\Resilience\RetryPolicy;
use Cake\Log\Log;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RetryerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!Log::getConfig('debug')) {
            Log::setConfig('debug', ['className' => 'Array']);
        }
        if (!Log::getConfig('error')) {
            Log::setConfig('error', ['className' => 'Array']);
        }
    }

    public function testReturnsResultOnFirstSuccess(): void
    {
        $r = new Retryer(new RetryPolicy(maxAttempts: 3, baseDelayMs: 0));
        $calls = 0;
        $result = $r->run(function () use (&$calls) {
            $calls++;

            return 'ok';
        });
        $this->assertSame('ok', $result);
        $this->assertSame(1, $calls);
    }

    public function testRetriesUntilSuccess(): void
    {
        $r = new Retryer(new RetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 0,
            retriableExceptions: [RuntimeException::class],
        ));

        $calls = 0;
        $result = $r->run(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw new RuntimeException("attempt {$calls} failed");
            }

            return 'finally';
        });

        $this->assertSame('finally', $result);
        $this->assertSame(3, $calls);
    }

    public function testNonRetriableExceptionIsRethrownImmediately(): void
    {
        $r = new Retryer(new RetryPolicy(
            maxAttempts: 5,
            baseDelayMs: 0,
            retriableExceptions: [RuntimeException::class],
        ));

        $calls = 0;
        try {
            $r->run(function () use (&$calls) {
                $calls++;
                throw new LogicException('non retriable');
            });
            $this->fail('Expected LogicException');
        } catch (LogicException $e) {
            $this->assertSame('non retriable', $e->getMessage());
        }
        $this->assertSame(1, $calls, 'Should not retry non-retriable exceptions');
    }

    public function testExhaustedRetriesWrapInRuntimeExceptionPreservingPrevious(): void
    {
        $r = new Retryer(new RetryPolicy(
            maxAttempts: 2,
            baseDelayMs: 0,
            retriableExceptions: [RuntimeException::class],
        ), context: 'svc');

        $calls = 0;
        try {
            $r->run(function () use (&$calls) {
                $calls++;
                throw new RuntimeException("boom #{$calls}");
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Retries exhausted (svc)', $e->getMessage());
            $this->assertStringContainsString('boom #3', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }
        $this->assertSame(3, $calls, 'Initial attempt + 2 retries = 3 total calls');
    }

    public function testZeroAttemptsRunsOnce(): void
    {
        $r = new Retryer(RetryPolicy::noRetry());
        $calls = 0;
        try {
            $r->run(function () use (&$calls) {
                $calls++;
                throw new RuntimeException('once');
            });
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Retries exhausted', $e->getMessage());
        }
        $this->assertSame(1, $calls);
    }

    public function testRetriableMatchesByInheritance(): void
    {
        // InvalidArgumentException extends LogicException; we declare retriable=LogicException
        $r = new Retryer(new RetryPolicy(
            maxAttempts: 1,
            baseDelayMs: 0,
            retriableExceptions: [LogicException::class],
        ));

        $calls = 0;
        try {
            $r->run(function () use (&$calls) {
                $calls++;
                throw new InvalidArgumentException('child class');
            });
            $this->fail('Should have thrown after retries');
        } catch (RuntimeException $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $e->getPrevious());
        }
        $this->assertSame(2, $calls);
    }
}

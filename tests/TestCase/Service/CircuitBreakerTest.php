<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\CircuitBreaker;
use Cake\Cache\Cache;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests para CircuitBreaker — máquina de estados closed/open/half-open.
 *
 * El estado persiste en Cache (config 'default'); el bootstrap de tests no la
 * define, así que se configura un engine Array (in-memory) por test y se descarta
 * en tearDown. El timeout de recuperación se controla de forma determinista vía
 * recoveryTimeoutSeconds (0 = siempre intenta reset; 60 = permanece abierto)
 * en lugar de manipular time(). Cada breaker usa un nombre único → claves de
 * cache aisladas.
 *
 * StructuredLogger usa Cake\Log\Log, que es no-op sin engines configurados.
 */
final class CircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        Cache::drop('default');
        Cache::setConfig('default', ['className' => 'Array']);
    }

    protected function tearDown(): void
    {
        Cache::drop('default');
        parent::tearDown();
    }

    public function testClosedCircuitExecutesActionAndReturnsResult(): void
    {
        $breaker = new CircuitBreaker('cb_closed', 3, 60);

        $result = $breaker->call(fn() => 'ok');

        $this->assertSame('ok', $result);
    }

    public function testOpensAfterThresholdAndShortCircuitsToFallback(): void
    {
        $breaker = new CircuitBreaker('cb_open', 2, 60);
        $calls = 0;
        $failing = function () use (&$calls) {
            $calls++;

            throw new RuntimeException('boom');
        };
        $fallback = fn() => 'fb';

        $breaker->call($failing, $fallback); // count=1
        $breaker->call($failing, $fallback); // count=2 → abre

        // Circuito abierto: la acción ya NO se ejecuta, se usa el fallback.
        $this->assertSame('fb', $breaker->call($failing, $fallback));
        $this->assertSame(2, $calls);
    }

    public function testOpenCircuitWithoutFallbackThrows(): void
    {
        $breaker = new CircuitBreaker('cb_throw', 1, 60);
        $breaker->call(fn() => throw new RuntimeException('boom'), fn() => null); // abre

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is open');
        $breaker->call(fn() => 'never');
    }

    public function testSuccessResetsFailureCount(): void
    {
        $breaker = new CircuitBreaker('cb_reset', 2, 60);

        $breaker->call(fn() => throw new RuntimeException('boom'), fn() => 'fb'); // count=1
        $breaker->call(fn() => 'ok'); // éxito → resetea count a 0
        $breaker->call(fn() => throw new RuntimeException('boom'), fn() => 'fb'); // count=1 (no 2)

        // Si el contador no se hubiera reseteado, este 2º fallo habría abierto el
        // circuito; al seguir cerrado, la acción se ejecuta normalmente.
        $calls = 0;
        $result = $breaker->call(function () use (&$calls) {
            $calls++;

            return 'ok2';
        });

        $this->assertSame('ok2', $result);
        $this->assertSame(1, $calls);
    }

    public function testHalfOpenAttemptsResetWhenTimeoutElapsed(): void
    {
        // recoveryTimeout=0 → el circuito abierto pasa a half-open de inmediato.
        $breaker = new CircuitBreaker('cb_half', 1, 0);
        $breaker->call(fn() => throw new RuntimeException('boom'), fn() => 'fb'); // abre

        $calls = 0;
        $result = $breaker->call(function () use (&$calls) {
            $calls++;

            return 'recovered';
        });

        $this->assertSame('recovered', $result);
        $this->assertSame(1, $calls);
    }
}

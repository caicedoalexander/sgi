<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Middleware\RateLimitMiddleware;
use App\Model\Table\RateLimitBucketsTable;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Cubre el rate limiting por IP+ruta+ventana. Puro: la RateLimitBucketsTable se
 * inyecta como mock (DI explícita), sin tocar BD. El conteo lo controla el mock.
 */
final class RateLimitMiddlewareTest extends TestCase
{
    private function request(string $ip = '1.2.3.4', string $path = '/login'): ServerRequest
    {
        return new ServerRequest([
            'environment' => ['REMOTE_ADDR' => $ip],
            'url' => $path,
        ]);
    }

    public function testAllowsRequestUnderLimitAndDelegatesToHandler(): void
    {
        $buckets = $this->createMock(RateLimitBucketsTable::class);
        $buckets->method('incrementAndGet')->willReturn(5);

        $handlerResponse = new Response();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($handlerResponse);

        $result = (new RateLimitMiddleware(10, 60, $buckets))->process($this->request(), $handler);

        $this->assertSame($handlerResponse, $result);
    }

    public function testBlocksRequestOverLimitWith429AndRetryAfter(): void
    {
        $buckets = $this->createMock(RateLimitBucketsTable::class);
        $buckets->method('incrementAndGet')->willReturn(11);

        // El handler NO debe invocarse cuando se supera el límite.
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $result = (new RateLimitMiddleware(10, 60, $buckets))->process($this->request(), $handler);

        $this->assertSame(429, $result->getStatusCode());
        $this->assertNotEmpty($result->getHeaderLine('Retry-After'));
        $this->assertStringContainsString('Too many requests', (string)$result->getBody());
    }
}

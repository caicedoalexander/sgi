<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Middleware\CorrelationIdMiddleware;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Cubre la generación/propagación del X-Correlation-ID. Puro: el handler captura
 * el request entrante para verificar el atributo inyectado.
 */
final class CorrelationIdMiddlewareTest extends TestCase
{
    /**
     * Handler que captura el request recibido y devuelve una respuesta limpia,
     * para poder aseverar el atributo 'correlationId' inyectado por el middleware.
     */
    private function capturingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $received = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->received = $request;

                return new Response();
            }
        };
    }

    public function testReusesIncomingHeader(): void
    {
        $request = (new ServerRequest())->withHeader('X-Correlation-ID', 'abc-123');
        $handler = $this->capturingHandler();

        $result = (new CorrelationIdMiddleware())->process($request, $handler);

        $this->assertSame('abc-123', $result->getHeaderLine('X-Correlation-ID'));
        $this->assertSame('abc-123', $handler->received?->getAttribute('correlationId'));
        $this->assertSame('abc-123', CorrelationIdMiddleware::getId());
    }

    public function testGeneratesIdWhenHeaderAbsent(): void
    {
        $handler = $this->capturingHandler();

        $result = (new CorrelationIdMiddleware())->process(new ServerRequest(), $handler);

        $generated = $result->getHeaderLine('X-Correlation-ID');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $generated);
        $this->assertSame($generated, $handler->received?->getAttribute('correlationId'));
        $this->assertSame($generated, CorrelationIdMiddleware::getId());
    }
}

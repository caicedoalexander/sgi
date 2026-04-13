<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CorrelationIdMiddleware implements MiddlewareInterface
{
    private static ?string $currentCorrelationId = null;

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request Request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler Handler.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $correlationId = $request->getHeaderLine('X-Correlation-ID') ?: bin2hex(random_bytes(8));
        self::$currentCorrelationId = $correlationId;

        $request = $request->withAttribute('correlationId', $correlationId);
        $response = $handler->handle($request);

        return $response->withHeader('X-Correlation-ID', $correlationId);
    }

    /**
     * @return string|null
     */
    public static function getId(): ?string
    {
        return self::$currentCorrelationId;
    }
}

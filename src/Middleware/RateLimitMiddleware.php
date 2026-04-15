<?php
declare(strict_types=1);

namespace App\Middleware;

use Cake\Cache\Cache;
use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RateLimitMiddleware implements MiddlewareInterface
{
    private int $maxRequests;
    private int $windowSeconds;

    /**
     * @param int $maxRequests Maximum requests per window.
     * @param int $windowSeconds Window duration in seconds.
     */
    public function __construct(int $maxRequests = 10, int $windowSeconds = 60)
    {
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request Request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler Handler.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $path = $request->getUri()->getPath();
        $key = 'rate_limit_' . md5($ip . $path);

        $current = (int)(Cache::read($key, 'default') ?: 0);

        if ($current >= $this->maxRequests) {
            $response = new Response();

            return $response
                ->withStatus(429)
                ->withType('application/json')
                ->withStringBody((string)json_encode(['error' => 'Too many requests']));
        }

        Cache::write($key, $current + 1, 'default');

        return $handler->handle($request);
    }
}

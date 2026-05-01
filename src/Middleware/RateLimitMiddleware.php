<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Model\Table\RateLimitBucketsTable;
use Cake\Http\Response;
use Cake\ORM\TableRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param int $maxRequests Maximum requests allowed per window.
     * @param int $windowSeconds Window duration in seconds.
     * @param \App\Model\Table\RateLimitBucketsTable|null $buckets Bucket table (DI for tests/dev).
     */
    public function __construct(
        private readonly int $maxRequests = 10,
        private readonly int $windowSeconds = 60,
        private readonly ?RateLimitBucketsTable $buckets = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $path = $request->getUri()->getPath();
        $windowStart = (int)floor(time() / $this->windowSeconds) * $this->windowSeconds;
        $key = hash('sha256', $ip . '|' . $path . '|' . $windowStart);

        /** @var \App\Model\Table\RateLimitBucketsTable $buckets */
        $buckets = $this->buckets
            ?? TableRegistry::getTableLocator()->get('RateLimitBuckets');

        $count = $buckets->incrementAndGet($key, $windowStart);

        if ($count > $this->maxRequests) {
            $retryAfter = max(1, $this->windowSeconds - (time() - $windowStart));

            $response = new Response();

            return $response
                ->withStatus(429)
                ->withType('application/json')
                ->withHeader('Retry-After', (string)$retryAfter)
                ->withStringBody((string)json_encode(['error' => 'Too many requests']));
        }

        // Probabilistic in-line garbage collection (1 in 100 requests).
        if (random_int(1, 100) === 1) {
            $buckets->garbageCollect($this->windowSeconds * 5);
        }

        return $handler->handle($request);
    }
}

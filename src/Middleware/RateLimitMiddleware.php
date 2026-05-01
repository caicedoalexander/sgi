<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Model\Table\RateLimitBucketsTable;
use Cake\Core\Configure;
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
     * @param array<string>|null $trustedProxies CIDR list of trusted proxies; null = read from config.
     */
    public function __construct(
        private readonly int $maxRequests = 10,
        private readonly int $windowSeconds = 60,
        private readonly ?RateLimitBucketsTable $buckets = null,
        private readonly ?array $trustedProxies = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $this->resolveClientIp($request);
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

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $remoteAddr = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';

        $trustedProxies = $this->trustedProxies
            ?? $this->parseTrustedProxies((string)Configure::read('Security.trustedProxies', ''));

        if (!$this->ipInRanges($remoteAddr, $trustedProxies)) {
            return $remoteAddr;
        }

        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff === '') {
            return $remoteAddr;
        }

        $first = trim(explode(',', $xff)[0]);

        return $first !== '' ? $first : $remoteAddr;
    }

    /**
     * @return array<string>
     */
    private function parseTrustedProxies(string $csv): array
    {
        if ($csv === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }

    /**
     * @param array<string> $ranges CIDR ranges.
     */
    private function ipInRanges(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private function ipInRange(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            // IPv6 not supported in this helper. Documented limitation.
            return false;
        }

        $mask = -1 << (32 - (int)$bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}

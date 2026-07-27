<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Middleware\HostHeaderMiddleware;
use Cake\Core\Configure;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\InternalErrorException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Cubre la mitigación de Host Header Injection (OWASP). Puro: el handler se stubbea
 * y App.fullBaseUrl/debug se manipulan vía Configure (restaurados en tearDown).
 */
final class HostHeaderMiddlewareTest extends TestCase
{
    private mixed $originalDebug = null;
    private mixed $originalBaseUrl = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDebug = Configure::read('debug');
        $this->originalBaseUrl = Configure::read('App.fullBaseUrl');
    }

    protected function tearDown(): void
    {
        Configure::write('debug', $this->originalDebug);
        Configure::write('App.fullBaseUrl', $this->originalBaseUrl);
        parent::tearDown();
    }

    private function handlerReturning(Response $response): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return $handler;
    }

    private function request(string $host): ServerRequest
    {
        return new ServerRequest(['environment' => ['HTTP_HOST' => $host]]);
    }

    public function testBypassesValidationInDebug(): void
    {
        Configure::write('debug', true);

        $response = new Response();
        $result = (new HostHeaderMiddleware())
            ->process($this->request('anything.evil.com'), $this->handlerReturning($response));

        $this->assertSame($response, $result);
    }

    public function testThrowsWhenFullBaseUrlMissingInProduction(): void
    {
        Configure::write('debug', false);
        Configure::write('App.fullBaseUrl', null);

        $this->expectException(InternalErrorException::class);
        (new HostHeaderMiddleware())
            ->process($this->request('example.com'), $this->handlerReturning(new Response()));
    }

    public function testThrowsWhenHostDoesNotMatchConfiguredHost(): void
    {
        Configure::write('debug', false);
        Configure::write('App.fullBaseUrl', 'https://app.example.com');

        $this->expectException(BadRequestException::class);
        (new HostHeaderMiddleware())
            ->process($this->request('evil.com'), $this->handlerReturning(new Response()));
    }

    public function testPassesWhenHostMatchesConfiguredHost(): void
    {
        Configure::write('debug', false);
        Configure::write('App.fullBaseUrl', 'https://app.example.com');

        $response = new Response();
        $result = (new HostHeaderMiddleware())
            ->process($this->request('app.example.com'), $this->handlerReturning($response));

        $this->assertSame($response, $result);
    }
}

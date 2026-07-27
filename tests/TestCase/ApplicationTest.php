<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.3.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Test\TestCase;

use App\Application;
use App\Middleware\CorrelationIdMiddleware;
use App\Middleware\HostHeaderMiddleware;
use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Core\Configure;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\AssetMiddleware;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ApplicationTest class
 */
class ApplicationTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Test bootstrap in production.
     *
     * @return void
     */
    public function testBootstrap()
    {
        Configure::write('debug', false);
        $app = new Application(dirname(__DIR__, 2) . '/config');
        $app->bootstrap();
        $plugins = $app->getPlugins();

        $this->assertTrue($plugins->has('Bake'), 'plugins has Bake?');
        $this->assertFalse($plugins->has('DebugKit'), 'plugins has DebugKit?');
        $this->assertTrue($plugins->has('Migrations'), 'plugins has Migrations?');
    }

    /**
     * Test bootstrap add DebugKit plugin in debug mode.
     *
     * @return void
     */
    public function testBootstrapInDebug()
    {
        Configure::write('debug', true);
        $app = new Application(dirname(__DIR__, 2) . '/config');
        $app->bootstrap();
        $plugins = $app->getPlugins();

        $this->assertTrue($plugins->has('DebugKit'), 'plugins has DebugKit?');
    }

    /**
     * El orden de la cola de middleware es relevante para seguridad: ErrorHandler
     * envuelve todo, HostHeader valida el host antes de enrutar, Authentication corre
     * tras Routing y Csrf protege los POST. Se asevera la lista ordenada completa
     * (no índices mágicos): si alguien añade/reordena un middleware, este test falla
     * y fuerza una revisión consciente del orden.
     *
     * @return void
     */
    public function testMiddlewareQueueOrder(): void
    {
        $app = new Application(dirname(__DIR__, 2) . '/config');
        $queue = $app->middleware(new MiddlewareQueue());

        $actual = [];
        $count = count($queue);
        for ($i = 0; $i < $count; $i++) {
            $queue->seek($i);
            $actual[] = $queue->current()::class;
        }

        $this->assertSame(
            [
                CorrelationIdMiddleware::class,
                ErrorHandlerMiddleware::class,
                HostHeaderMiddleware::class,
                AssetMiddleware::class,
                RoutingMiddleware::class,
                AuthenticationMiddleware::class,
                BodyParserMiddleware::class,
                CsrfProtectionMiddleware::class,
            ],
            $actual,
        );
    }
}

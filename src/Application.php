<?php
declare(strict_types=1);

namespace App;

use App\Middleware\HostHeaderMiddleware;
use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Datasource\FactoryLocator;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Event\EventManagerInterface;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\ORM\Locator\TableLocator;
use Cake\Routing\Middleware\AssetMiddleware;
use Cake\Routing\Middleware\RoutingMiddleware;
use Psr\Http\Message\ServerRequestInterface;

class Application extends BaseApplication implements AuthenticationServiceProviderInterface
{
    public function bootstrap(): void
    {
        parent::bootstrap();

        FactoryLocator::add('Table', (new TableLocator())->allowFallbackClass(false));

        $this->addPlugin('Authentication');
    }

    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $middlewareQueue
            ->add(new ErrorHandlerMiddleware(Configure::read('Error'), $this))
            ->add(new HostHeaderMiddleware())
            ->add(new AssetMiddleware([
                'cacheTime' => Configure::read('Asset.cacheTime'),
            ]))
            ->add(new RoutingMiddleware($this))
            ->add(new AuthenticationMiddleware($this))
            ->add(new BodyParserMiddleware())
            ->add(new CsrfProtectionMiddleware([
                'httponly' => true,
            ]));

        return $middlewareQueue;
    }

    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
    {
        $service = new AuthenticationService();

        $service->setConfig([
            'unauthenticatedRedirect' => '/login',
            'queryParam' => 'redirect',
        ]);

        $fields = [
            \Authentication\Identifier\PasswordIdentifier::CREDENTIAL_USERNAME => 'username',
            \Authentication\Identifier\PasswordIdentifier::CREDENTIAL_PASSWORD => 'password',
        ];

        $service->loadAuthenticator('Authentication.Session');
        $service->loadAuthenticator('Authentication.Form', [
            'fields' => $fields,
            'loginUrl' => '/login',
        ]);

        $service->loadIdentifier('Authentication.Password', [
            'fields' => $fields,
            'resolver' => [
                'className' => 'Authentication.Orm',
                'userModel' => 'Users',
                'finder' => 'auth',
            ],
        ]);

        return $service;
    }

    public function services(ContainerInterface $container): void
    {
    }

    public function events(EventManagerInterface $eventManager): EventManagerInterface
    {
        return $eventManager;
    }
}

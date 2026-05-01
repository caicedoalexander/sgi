<?php
declare(strict_types=1);

namespace App;

use App\Middleware\CorrelationIdMiddleware;
use App\Middleware\HostHeaderMiddleware;
use App\Service\Adapter\CakeMailerAdapter;
use App\Service\Adapter\PhpSpreadsheetAdapter;
use App\Service\AdvanceLegalizationService;
use App\Service\ApprovalTokenService;
use App\Service\AuthorizationService;
use App\Service\Dashboard\EmployeeStatisticsService;
use App\Service\Dashboard\InvoiceStatisticsService;
use App\Service\DashboardStatisticsService;
use App\Service\DianCrosscheckService;
use App\Service\EmailLogService;
use App\Service\EmployeeDocumentService;
use App\Service\EmployeeFilterService;
use App\Service\EmployeeHistoryService;
use App\Service\ExcelImportService;
use App\Service\ExcelMappingService;
use App\Service\ExcelService;
use App\Service\Interface\MailerInterface;
use App\Service\Interface\SpreadsheetReaderInterface;
use App\Service\InvoiceApprovalService;
use App\Service\InvoiceDocumentService;
use App\Service\InvoiceFieldAccessPolicy;
use App\Service\InvoiceFilterService;
use App\Service\InvoiceHistoryService;
use App\Service\InvoicePaymentService;
use App\Service\InvoicePipelineService;
use App\Service\LeaveDocumentService;
use App\Service\LeaveSignatureService;
use App\Service\LiquidationDocPaymentService;
use App\Service\N8nService;
use App\Service\NotificationService;
use App\Service\NoveltyDocumentService;
use App\Service\NoveltyHistoryService;
use App\Service\NoveltyObservationService;
use App\Service\NoveltyPipelineService;
use App\Service\NoveltySignatureService;
use App\Service\PaymentRegistryService;
use App\Service\PaymentSchedulingPipelineService;
use App\Service\PaymentSchedulingService;
use App\Service\PettyCashDocumentService;
use App\Service\PettyCashService;
use App\Service\SidebarCounterService;
use App\Service\Strategy\InvoiceApprovalStrategy;
use App\Service\Strategy\NoveltyApprovalStrategy;
use App\Service\StructuredLogger;
use App\Service\SystemSettingsService;
use App\Service\WebhookService;
use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Identifier\PasswordIdentifier;
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
use League\Container\Argument\LiteralArgument;
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
            ->add(new CorrelationIdMiddleware())
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
            PasswordIdentifier::CREDENTIAL_USERNAME => 'username',
            PasswordIdentifier::CREDENTIAL_PASSWORD => 'password',
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
        // === Infrastructure / Adapters ===
        $container->addShared(SystemSettingsService::class);
        $container->addShared(StructuredLogger::class);
        $container->addShared(MailerInterface::class, CakeMailerAdapter::class)
            ->addArgument(SystemSettingsService::class);
        $container->addShared(SpreadsheetReaderInterface::class, PhpSpreadsheetAdapter::class);

        // === Auth / Authorization ===
        $container->addShared(AuthorizationService::class);
        $container->addShared(ApprovalTokenService::class);

        // === Email log + notifications (cycle: closure factory in EmailLog) ===
        $container->addShared(EmailLogService::class)
            ->addArgument(new LiteralArgument(
                fn() => $container->get(NotificationService::class),
            ));
        $container->addShared(NotificationService::class)
            ->addArguments([
                SystemSettingsService::class,
                MailerInterface::class,
                EmailLogService::class,
            ]);

        // === Invoice domain (cycle: closure factory in AdvanceLegalization) ===
        $container->addShared(InvoiceHistoryService::class);
        $container->addShared(InvoiceFieldAccessPolicy::class);
        $container->addShared(InvoiceFilterService::class);
        $container->addShared(InvoiceDocumentService::class);
        $container->addShared(InvoicePaymentService::class)
            ->addArguments([
                InvoiceHistoryService::class,
                AdvanceLegalizationService::class,
            ]);
        $container->addShared(AdvanceLegalizationService::class)
            ->addArgument(new LiteralArgument(
                fn() => $container->get(InvoicePipelineService::class),
            ));
        $container->addShared(InvoicePipelineService::class)
            ->addArguments([
                InvoiceHistoryService::class,
                InvoicePaymentService::class,
                InvoiceFieldAccessPolicy::class,
                AdvanceLegalizationService::class,
            ]);
        $container->addShared(InvoiceApprovalService::class)
            ->addArgument(NotificationService::class);

        // === Strategies ===
        $container->addShared(InvoiceApprovalStrategy::class)
            ->addArguments([
                InvoiceHistoryService::class,
                InvoicePipelineService::class,
            ]);
        $container->addShared(NoveltyApprovalStrategy::class)
            ->addArgument(NoveltyObservationService::class);

        // === Novelty domain ===
        $container->addShared(NoveltyHistoryService::class);
        $container->addShared(NoveltyObservationService::class);
        $container->addShared(NoveltyDocumentService::class);
        $container->addShared(NoveltySignatureService::class);
        $container->addShared(NoveltyPipelineService::class);
        $container->addShared(LeaveDocumentService::class);
        $container->addShared(LeaveSignatureService::class);
        $container->addShared(LiquidationDocPaymentService::class);

        // === Petty cash / payment scheduling / advances ===
        $container->addShared(PettyCashDocumentService::class);
        $container->addShared(PettyCashService::class);
        $container->addShared(PaymentSchedulingPipelineService::class);
        $container->addShared(PaymentSchedulingService::class)
            ->addArgument(InvoicePaymentService::class);
        $container->addShared(PaymentRegistryService::class);

        // === Integrations ===
        $container->addShared(WebhookService::class);
        $container->addShared(N8nService::class)
            ->addArguments([WebhookService::class, SystemSettingsService::class]);
        $container->addShared(DianCrosscheckService::class)
            ->addArgument(N8nService::class);

        // === Excel / import ===
        $container->addShared(ExcelService::class);
        $container->addShared(ExcelMappingService::class);
        $container->addShared(ExcelImportService::class)
            ->addArgument(ExcelMappingService::class);

        // === Employees ===
        $container->addShared(EmployeeFilterService::class);
        $container->addShared(EmployeeDocumentService::class);
        $container->addShared(EmployeeHistoryService::class);

        // === Dashboard ===
        $container->addShared(InvoiceStatisticsService::class);
        $container->addShared(EmployeeStatisticsService::class);
        $container->addShared(DashboardStatisticsService::class)
            ->addArguments([
                InvoiceStatisticsService::class,
                EmployeeStatisticsService::class,
            ]);
        $container->addShared(SidebarCounterService::class)
            ->addArguments([
                InvoicePipelineService::class,
                NoveltyPipelineService::class,
                PettyCashService::class,
            ]);
    }

    public function events(EventManagerInterface $eventManager): EventManagerInterface
    {
        return $eventManager;
    }
}

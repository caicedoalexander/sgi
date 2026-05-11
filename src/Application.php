<?php
declare(strict_types=1);

namespace App;

use App\Controller\AppController;
use App\Middleware\CorrelationIdMiddleware;
use App\Middleware\HostHeaderMiddleware;
use App\Service\Adapter\CakeMailerAdapter;
use App\Service\Adapter\PhpSpreadsheetAdapter;
use App\Service\AdvanceLegalizationDocumentService;
use App\Service\AdvanceLegalizationHistoryService;
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
use App\Service\HealthCheck\CacheHealthCheck;
use App\Service\HealthCheck\CircuitBreakerHealthCheck;
use App\Service\HealthCheck\DatabaseHealthCheck;
use App\Service\HealthCheck\EmailLogHealthCheck;
use App\Service\Interface\MailerInterface;
use App\Service\Interface\SpreadsheetReaderInterface;
use App\Service\InvoiceApprovalService;
use App\Service\InvoiceDocumentService;
use App\Service\InvoiceFieldAccessPolicy;
use App\Service\InvoiceFilterService;
use App\Service\InvoiceHistoryService;
use App\Service\InvoiceLockPolicy;
use App\Service\InvoicePaymentService;
use App\Service\InvoicePipelineService;
use App\Service\InvoiceTransitionValidator;
use App\Service\LeaveDocumentService;
use App\Service\LeaveSignatureService;
use App\Service\LiquidationDocPaymentService;
use App\Service\N8nService;
use App\Service\NotificationService;
use App\Service\NoveltyDocumentService;
use App\Service\NoveltyHistoryService;
use App\Service\NoveltyObservationService;
use App\Service\NoveltyService;
use App\Service\NoveltySignatureService;
use App\Service\PaymentRegistryService;
use App\Service\PaymentSchedulingDocumentService;
use App\Service\PaymentSchedulingImportService;
use App\Service\PaymentSchedulingService;
use App\Service\PendingNotificationsService;
use App\Service\PettyCashDocumentService;
use App\Service\PettyCashService;
use App\Service\Pipeline\Invoice\DocumentTypePolicyFactory;
use App\Service\Pipeline\Invoice\InvoicePipelineStateRegistry;
use App\Service\Pipeline\Invoice\LinkedInvoiceLegalizer;
use App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy;
use App\Service\Pipeline\Invoice\Policy\AnticipoDocumentTypePolicy;
use App\Service\Pipeline\Invoice\Policy\LegalizacionDocumentTypePolicy;
use App\Service\Pipeline\Invoice\Policy\StandardDocumentTypePolicy;
use App\Service\Pipeline\Invoice\State\AprobacionState;
use App\Service\Pipeline\Invoice\State\AutorizacionPagoState;
use App\Service\Pipeline\Invoice\State\ContabilidadState;
use App\Service\Pipeline\Invoice\State\LegalizadaState;
use App\Service\Pipeline\Invoice\State\PagadaState;
use App\Service\Pipeline\Invoice\State\VerificacionPagoState;
use App\Service\Pipeline\Invoice\State\TesoreriaState;
use App\Service\PipelineAuthorizationService;
use App\Service\RefundService;
use App\Service\RefundDocumentService;
use App\Service\RefundPaymentService;
use App\Service\SidebarCounterService;
use App\Service\Strategy\InvoiceApprovalStrategy;
use App\Service\Strategy\NoveltyApprovalStrategy;
use App\Service\StructuredLogger;
use App\Service\Subscriber\LegalizationInitializerSubscriber;
use App\Service\Subscriber\LinkedInvoicesPromoterSubscriber;
use App\Service\Subscriber\RefundOutcomeSubscriber;
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
use Cake\Event\EventManager;
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

        // En cakephp/authentication 4.x el identifier se pasa dentro del config
        // del autenticador (clave 'identifier'). loadIdentifier() fue removido.
        $identifierConfig = [
            'className' => 'Authentication.Password',
            'fields' => $fields,
            'resolver' => [
                'className' => 'Authentication.Orm',
                'userModel' => 'Users',
                'finder' => 'auth',
            ],
        ];

        $service->loadAuthenticator('Authentication.Session');
        $service->loadAuthenticator('Authentication.Form', [
            'fields' => $fields,
            'loginUrl' => '/login',
            'identifier' => $identifierConfig,
        ]);

        return $service;
    }

    public function services(ContainerInterface $container): void
    {
        // Expose container to AppController + traits via static accessor.
        AppController::setContainer($container);

        // === Infrastructure / Adapters ===
        $container->addShared(SystemSettingsService::class);
        $container->addShared(StructuredLogger::class);
        $container->addShared(MailerInterface::class, CakeMailerAdapter::class)
            ->addArgument(SystemSettingsService::class);
        $container->addShared(SpreadsheetReaderInterface::class, PhpSpreadsheetAdapter::class);

        // === Auth / Authorization ===
        $container->addShared(AuthorizationService::class);
        $container->addShared(PipelineAuthorizationService::class);
        $container->addShared(ApprovalTokenService::class)
            ->addArguments([
                InvoiceApprovalStrategy::class,
                NoveltyApprovalStrategy::class,
            ]);

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
        $container->addShared(InvoiceFieldAccessPolicy::class)
            ->addArgument(PipelineAuthorizationService::class);
        $container->addShared(InvoiceLockPolicy::class);
        $container->addShared(InvoiceTransitionValidator::class)
            ->addArguments([
                InvoicePipelineStateRegistry::class,
                DocumentTypePolicyFactory::class,
                InvoiceFieldAccessPolicy::class,
                PipelineAuthorizationService::class,
            ]);
        $container->addShared(InvoiceFilterService::class);
        $container->addShared(InvoiceDocumentService::class);
        $container->addShared(InvoicePaymentService::class)
            ->addArguments([
                InvoiceHistoryService::class,
                DocumentTypePolicyFactory::class,
                EventManagerInterface::class,
            ]);
        $container->addShared(AdvanceLegalizationHistoryService::class);
        $container->addShared(AdvanceLegalizationDocumentService::class);
        $container->addShared(AdvanceLegalizationService::class)
            ->addArguments([
                EventManagerInterface::class,
                AdvanceLegalizationHistoryService::class,
                AdvanceLegalizationDocumentService::class,
            ]);
        $container->addShared(AdvanceLegalizationActionPolicy::class)
            ->addArgument(PipelineAuthorizationService::class);
        $container->addShared(InvoicePipelineService::class)
            ->addArguments([
                InvoiceHistoryService::class,
                InvoicePaymentService::class,
                InvoiceFieldAccessPolicy::class,
                InvoiceLockPolicy::class,
                InvoiceTransitionValidator::class,
                InvoicePipelineStateRegistry::class,
                DocumentTypePolicyFactory::class,
                EventManagerInterface::class,
                PipelineAuthorizationService::class,
            ]);
        $container->addShared(InvoiceApprovalService::class)
            ->addArgument(NotificationService::class);

        // === Pipeline states (Plan 4 W9) — registrados pero aún no consumidos ===
        $container->addShared(AprobacionState::class);
        $container->addShared(ContabilidadState::class);
        $container->addShared(TesoreriaState::class)
            ->addArgument(InvoicePaymentService::class);
        $container->addShared(AutorizacionPagoState::class)
            ->addArgument(InvoicePaymentService::class);
        $container->addShared(VerificacionPagoState::class);
        $container->addShared(PagadaState::class);
        $container->addShared(LegalizadaState::class);
        $container->addShared(InvoicePipelineStateRegistry::class)
            ->addArguments([
                AprobacionState::class,
                ContabilidadState::class,
                TesoreriaState::class,
                AutorizacionPagoState::class,
                VerificacionPagoState::class,
                PagadaState::class,
                LegalizadaState::class,
            ]);

        // === Document type policies (Plan 4 W10) — registrados pero aún no consumidos ===
        $container->addShared(StandardDocumentTypePolicy::class);
        $container->addShared(AnticipoDocumentTypePolicy::class)
            ->addArgument(AdvanceLegalizationService::class);
        $container->addShared(LegalizacionDocumentTypePolicy::class);
        $container->addShared(DocumentTypePolicyFactory::class)
            ->addArguments([
                StandardDocumentTypePolicy::class,
                AnticipoDocumentTypePolicy::class,
                LegalizacionDocumentTypePolicy::class,
            ]);

        // === Plan 5: Domain events — services + subscribers ===
        $container->addShared(EventManagerInterface::class, fn() => EventManager::instance());

        $container->addShared(LinkedInvoiceLegalizer::class)
            ->addArgument(InvoiceHistoryService::class);

        $container->addShared(LegalizationInitializerSubscriber::class)
            ->addArguments([
                AdvanceLegalizationService::class,
                DocumentTypePolicyFactory::class,
            ]);

        $container->addShared(RefundOutcomeSubscriber::class)
            ->addArgument(AdvanceLegalizationService::class);

        $container->addShared(LinkedInvoicesPromoterSubscriber::class)
            ->addArgument(LinkedInvoiceLegalizer::class);

        // === Strategies ===
        $container->addShared(InvoiceApprovalStrategy::class)
            ->addArguments([
                InvoiceHistoryService::class,
                InvoicePipelineService::class,
            ]);
        $container->addShared(NoveltyApprovalStrategy::class)
            ->addArguments([
                NoveltyObservationService::class,
                NoveltyHistoryService::class,
            ]);

        // === Novelty domain ===
        $container->addShared(NoveltyHistoryService::class);
        $container->addShared(NoveltyObservationService::class);
        $container->addShared(NoveltyDocumentService::class);
        $container->addShared(NoveltySignatureService::class);
        $container->addShared(NoveltyService::class)
            ->addArgument(PipelineAuthorizationService::class);
        $container->addShared(LeaveDocumentService::class);
        $container->addShared(LeaveSignatureService::class);
        $container->addShared(LiquidationDocPaymentService::class);

        // === Petty cash / payment scheduling / advances ===
        $container->addShared(PettyCashDocumentService::class);
        $container->addShared(PettyCashService::class)
            ->addArguments([
                InvoiceHistoryService::class,
                PipelineAuthorizationService::class,
            ]);
        $container->addShared(RefundDocumentService::class);
        $container->addShared(RefundService::class)
            ->addArguments([
                InvoiceHistoryService::class,
                PipelineAuthorizationService::class,
            ]);
        $container->addShared(RefundPaymentService::class);
        $container->addShared(PaymentSchedulingService::class)
            ->addArgument(InvoicePaymentService::class);
        $container->addShared(PaymentSchedulingImportService::class)
            ->addArgument(InvoicePaymentService::class);
        $container->addShared(PaymentSchedulingDocumentService::class);
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
                NoveltyService::class,
                PettyCashService::class,
                RefundService::class,
            ]);
        $container->addShared(PendingNotificationsService::class)
            ->addArguments([
                SidebarCounterService::class,
                PaymentSchedulingService::class,
            ]);

        // === Plan 6: Health checks ===
        $container->addShared(DatabaseHealthCheck::class);
        $container->addShared(CacheHealthCheck::class);
        $container->addShared(CircuitBreakerHealthCheck::class);
        $container->addShared(EmailLogHealthCheck::class);
    }

    public function events(EventManagerInterface $eventManager): EventManagerInterface
    {
        $container = $this->getContainer();

        $eventManager->on($container->get(LegalizationInitializerSubscriber::class));
        $eventManager->on($container->get(RefundOutcomeSubscriber::class));
        $eventManager->on($container->get(LinkedInvoicesPromoterSubscriber::class));

        return $eventManager;
    }
}

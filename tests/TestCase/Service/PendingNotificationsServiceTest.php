<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Application;
use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Service\PendingNotificationsService;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\Cache\Cache;
use Cake\Http\ServerRequest;
use Cake\ORM\TableRegistry;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;

/**
 * `_buildModules()` reconciliado a 8 módulos: verifica que `legalizations` se
 * incluya junto a los otros 7, para que el digest de n8n sume lo mismo que el
 * badge del sidebar y la lista de "Mis Pendientes".
 */
class PendingNotificationsServiceTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // getPendingByUser() -> _buildModules() -> SidebarCounterService::getCounters()
        // cachea en el grupo `sidebar` (Cake\Cache\Cache::remember).
        Cache::clear('sidebar');

        // `_buildModules()` llama Router::url() para armar la url de cada módulo.
        // `Cake\TestSuite\TestCase::setUp()` corre Router::reload() (colección
        // vacía) antes de cada test, así que hay que cargar routes.php a mano
        // (mismo patrón que `IntegrationTestTrait::resolveRoute()`).
        $app = new Application(dirname(__DIR__, 3) . '/config');
        $app->bootstrap();
        $app->routes(Router::createRouteBuilder('/'));
    }

    public function testBuildModulesIncludesLegalizationsWhenPending(): void
    {
        $role = RoleFactory::new()->save();

        $pp = TableRegistry::getTableLocator()->get('PipelinePermissions');
        foreach (PipelineStepConstants::STEPS_BY_PIPELINE[PipelineStepConstants::PIPELINE_LEGALIZATIONS] as $step) {
            $pp->saveOrFail($pp->newEntity([
                'role_id' => $role->id,
                'pipeline' => PipelineStepConstants::PIPELINE_LEGALIZATIONS,
                'step' => $step,
                'can_operate' => true,
            ]));
        }

        UserFactory::new(['role_id' => $role->id, 'active' => true])->save();

        $anticipo = InvoiceFactory::new()->anticipo()->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();

        $service = (new Application(dirname(__DIR__, 3) . '/config'))->getContainer()
            ->get(PendingNotificationsService::class);
        $rows = $service->getPendingByUser();

        $keys = [];
        foreach ($rows as $row) {
            foreach ($row['modules'] as $module) {
                $keys[] = $module['key'];
            }
        }
        $this->assertContains('legalizations', $keys);
    }

    /**
     * Regresión: el endpoint de la API (`/api/notifications/pending`) corre bajo
     * el prefijo de routing `Api`. Al armar la url de cada módulo con
     * `Router::url()`, sin resetear el prefijo se hereda `prefix => 'Api'` del
     * request actual y explota con `MissingRouteException` (no hay rutas de
     * módulo bajo `Api`). Aquí simulamos ese request para cubrir el caso.
     *
     * @return void
     */
    public function testGetPendingByUserDoesNotInheritApiPrefixInUrls(): void
    {
        $role = RoleFactory::new()->save();

        $pp = TableRegistry::getTableLocator()->get('PipelinePermissions');
        foreach (PipelineStepConstants::STEPS_BY_PIPELINE[PipelineStepConstants::PIPELINE_LEGALIZATIONS] as $step) {
            $pp->saveOrFail($pp->newEntity([
                'role_id' => $role->id,
                'pipeline' => PipelineStepConstants::PIPELINE_LEGALIZATIONS,
                'step' => $step,
                'can_operate' => true,
            ]));
        }

        UserFactory::new(['role_id' => $role->id, 'active' => true])->save();

        $anticipo = InvoiceFactory::new()->anticipo()->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();

        // Simula el contexto real: request bajo el prefijo `Api`.
        Router::setRequest((new ServerRequest())->withAttribute('params', [
            'prefix' => 'Api',
            'controller' => 'Notifications',
            'action' => 'pending',
            'plugin' => null,
            'pass' => [],
        ]));

        $service = (new Application(dirname(__DIR__, 3) . '/config'))->getContainer()
            ->get(PendingNotificationsService::class);
        // Sin el fix, esta llamada lanza MissingRouteException.
        $rows = $service->getPendingByUser();

        $urls = [];
        foreach ($rows as $row) {
            foreach ($row['modules'] as $module) {
                $urls[] = $module['url'];
            }
        }

        $this->assertNotEmpty($urls, 'Debe haber al menos una url de módulo pendiente para que la aserción sea significativa.');
        foreach ($urls as $url) {
            $this->assertStringNotContainsString('/api/', $url, 'Las URLs del digest no deben heredar el prefijo Api del request.');
        }
    }
}

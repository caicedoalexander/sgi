<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\RoleConstants;
use App\Service\AuthorizationService;
use Cake\ORM\Entity;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para AuthorizationService — RBAC CRUD por (rol, módulo, acción).
 *
 * Foco de seguridad:
 *  - Admin bypass ACOTADO: solo los módulos en ADMIN_BYPASS_MODULES (users, roles);
 *    para cualquier otro módulo el Administrador pasa por el lookup normal.
 *  - Mapeo acción→columna (view/index→can_view, add→can_create, edit→can_edit,
 *    delete→can_delete) y acción desconocida → false.
 *  - Módulo sin fila de permisos → false (deny by default).
 *  - Matriz completa con defaults en false.
 *  - Cache per-request e invalidación.
 *
 * Suite pura (sin DB): TableLocator con un mock de Permissions cuyo
 * find()->where()->all() devuelve un arreglo de filas controlado por el test.
 */
final class AuthorizationServiceTest extends TestCase
{
    /**
     * @var array<\Cake\ORM\Entity>
     */
    private array $rows = [];

    protected function setUp(): void
    {
        $this->rows = [];
    }

    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    private function buildService(): AuthorizationService
    {
        $query = $this->getMockBuilder(SelectQuery::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['where', 'all'])
            ->getMock();
        $query->method('where')->willReturnSelf();
        $query->method('all')->willReturnCallback(fn() => new ResultSet($this->rows));

        $table = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find'])
            ->getMock();
        $table->method('find')->willReturn($query);

        $locator = new TableLocator();
        $locator->set('Permissions', $table);
        TableRegistry::setTableLocator($locator);

        return new AuthorizationService();
    }

    private function permissionRow(
        string $module,
        bool $view = false,
        bool $create = false,
        bool $edit = false,
        bool $delete = false,
    ): Entity {
        return new Entity([
            'module' => $module,
            'can_view' => $view,
            'can_create' => $create,
            'can_edit' => $edit,
            'can_delete' => $delete,
        ]);
    }

    public function testAdminBypassesSecurityModules(): void
    {
        $service = $this->buildService();

        // Sin filas de permisos: el bypass debe conceder igualmente.
        $this->assertTrue($service->isAllowed(1, RoleConstants::ADMIN, 'users', 'delete'));
        $this->assertTrue($service->isAllowed(1, RoleConstants::ADMIN, 'roles', 'edit'));
    }

    public function testAdminDoesNotBypassNonSecurityModules(): void
    {
        $this->rows = [];
        $service = $this->buildService();

        // 'invoices' no está en ADMIN_BYPASS_MODULES: Administrador pasa por el
        // lookup normal y, sin fila, se le niega.
        $this->assertFalse($service->isAllowed(1, RoleConstants::ADMIN, 'invoices', 'view'));
    }

    public function testReturnsFalseWhenModuleHasNoPermissionRow(): void
    {
        $this->rows = [$this->permissionRow('providers', view: true)];
        $service = $this->buildService();

        $this->assertFalse($service->isAllowed(2, 'Contabilidad', 'invoices', 'view'));
    }

    public function testMapsActionsToPermissionColumns(): void
    {
        $this->rows = [$this->permissionRow('invoices', view: true, create: false, edit: true, delete: false)];
        $service = $this->buildService();

        $this->assertTrue($service->isAllowed(2, 'Contabilidad', 'invoices', 'view'));
        $this->assertTrue($service->isAllowed(2, 'Contabilidad', 'invoices', 'edit'));
        $this->assertFalse($service->isAllowed(2, 'Contabilidad', 'invoices', 'add'));
        $this->assertFalse($service->isAllowed(2, 'Contabilidad', 'invoices', 'delete'));
    }

    public function testIndexActionAliasesView(): void
    {
        $this->rows = [$this->permissionRow('invoices', view: true)];
        $service = $this->buildService();

        $this->assertTrue($service->isAllowed(2, 'Contabilidad', 'invoices', 'index'));
    }

    public function testUnknownActionReturnsFalse(): void
    {
        $this->rows = [$this->permissionRow('invoices', view: true, create: true, edit: true, delete: true)];
        $service = $this->buildService();

        $this->assertFalse($service->isAllowed(2, 'Contabilidad', 'invoices', 'frobnicate'));
    }

    public function testGetPermissionsForRoleAsMatrixIncludesAllModulesWithFalseDefaults(): void
    {
        $this->rows = [$this->permissionRow('invoices', view: true)];
        $service = $this->buildService();

        $matrix = $service->getPermissionsForRoleAsMatrix(2);

        $this->assertCount(count(AuthorizationService::MODULES), $matrix);
        $this->assertTrue($matrix['invoices']['can_view']);
        $this->assertSame(
            ['can_view' => false, 'can_create' => false, 'can_edit' => false, 'can_delete' => false],
            $matrix['providers'],
        );
    }

    public function testCachesPermissionsPerRole(): void
    {
        $this->rows = [$this->permissionRow('invoices', view: true)];
        $service = $this->buildService();

        $first = $service->getPermissionsForRole(2);
        // Si volviera a consultar, ahora obtendría vacío; la cache debe evitarlo.
        $this->rows = [];
        $second = $service->getPermissionsForRole(2);

        $this->assertArrayHasKey('invoices', $second);
        $this->assertSame($first, $second);
    }

    public function testInvalidateForcesReload(): void
    {
        $this->rows = [$this->permissionRow('invoices', view: true)];
        $service = $this->buildService();

        $service->getPermissionsForRole(2);
        $service->invalidate(2);
        $this->rows = [];
        $after = $service->getPermissionsForRole(2);

        $this->assertArrayNotHasKey('invoices', $after);
    }
}

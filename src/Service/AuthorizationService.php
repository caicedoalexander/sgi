<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\RoleConstants;
use Cake\ORM\TableRegistry;

/**
 * Servicio CRUD de permisos. Consulta directa a `permissions` con cache
 * per-request.
 *
 * Caché: `$cache` se invalida explícitamente vía `invalidate(int $roleId)` y
 * tras `savePermissionsForRole()`. No persiste entre requests por diseño:
 * depende del scope per-request del container de CakePHP DI (la instancia se
 * recolecta al cerrar la respuesta). No promover a caché global sin invalidación
 * cross-request explícita.
 *
 * @internal Depender de `App\Authorization\AuthorizationFacade` en su lugar.
 * Esta clase concreta solo debe inyectarse en `RolesController` y
 * `AppController::_setUserPermissions` (matrices y save quedan fuera del
 * contrato del Facade — ver audit PA-004).
 */
class AuthorizationService
{
    // Role name constants — reference centralized constants
    public const ROLE_ADMIN = RoleConstants::ADMIN;

    /**
     * Módulos donde Administrador conserva bypass automático. Para cualquier
     * otro módulo, el rol Administrador pasa por el lookup normal en la tabla
     * `permissions`. Cleanup post pipeline-permissions (2026-05-02).
     */
    public const ADMIN_BYPASS_MODULES = ['users', 'roles'];

    // Module constants (matching PermissionsTable::MODULES)
    public const MODULES = [
        'invoices' => 'Facturas',
        'advances' => 'Anticipos',
        'providers' => 'Proveedores',
        'operation_centers' => 'Centros de Operación',
        'expense_types' => 'Tipos de Gasto',
        'cost_centers' => 'Centros de Costos',
        'users' => 'Usuarios',
        'roles' => 'Roles',
        'approvers' => 'Aprobadores',
        'employees' => 'Empleados',
        'marital_statuses' => 'Estados Civiles',
        'education_levels' => 'Niveles Educativos',
        'positions' => 'Cargos',
        'default_folders' => 'Carpetas por Defecto',
        'system_settings' => 'Configuración del Sistema',
        'temporary_organizations' => 'Organizaciones Temporales',
        'dian_crosschecks' => 'Cruce DIAN',
        'employee_novelties' => 'Novedades de Empleados',
        'novelty_types' => 'Tipos de Novedad',
        'petty_cash' => 'Caja Menor',
        'refunds' => 'Reintegros',
        'novelty_liquidation_docs' => 'Documentos de Liquidación',
        'leave_document_templates' => 'Plantillas Documento',
        'banking_entities' => 'Entidades Bancarias',
        'payment_schedulings' => 'Programación',
        'payment_registry' => 'Registro de Pagos',
        'email_logs' => 'Logs de correo',
        'assets' => 'Activos',
        'consumables' => 'Consumibles',
        'asset_categories' => 'Categorías de Activos',
        'asset_alerts' => 'Alertas de Inventario',
    ];

    /**
     * Agrupación de presentación de los módulos para la UI de Roles.
     *
     * Fuente única del agrupamiento visual en la matriz de permisos. Cada clave
     * de módulo debe existir en self::MODULES; la unión de todos los grupos
     * cubre la totalidad de MODULES.
     *
     * @var array<string, list<string>>
     */
    public const MODULE_GROUPS = [
        'Financiero' => [
            'invoices', 'advances', 'petty_cash', 'refunds',
            'payment_schedulings', 'payment_registry', 'dian_crosschecks', 'novelty_liquidation_docs',
        ],
        'RRHH' => [
            'employees', 'employee_novelties', 'novelty_types',
        ],
        'Catálogos' => [
            'providers', 'approvers', 'operation_centers', 'expense_types', 'cost_centers',
            'positions', 'marital_statuses', 'education_levels', 'banking_entities',
            'temporary_organizations', 'leave_document_templates',
        ],
        'Seguridad' => [
            'users', 'roles',
        ],
        'Sistema' => [
            'system_settings', 'default_folders', 'email_logs',
        ],
        'Inventario TI' => [
            'assets', 'consumables', 'asset_categories', 'asset_alerts',
        ],
    ];

    private array $cache = [];

    /**
     * Determina si un rol puede ejecutar una acción CRUD sobre un módulo.
     *
     * @param int $roleId Id del rol.
     * @param string $roleName Nombre del rol (para el admin bypass acotado).
     * @param string $module Slug del módulo.
     * @param string $action Acción solicitada (view|index|add|edit|delete).
     * @return bool
     */
    public function isAllowed(int $roleId, string $roleName, string $module, string $action): bool
    {
        // Admin bypass solo para módulos en ADMIN_BYPASS_MODULES.
        if ($roleName === self::ROLE_ADMIN && in_array($module, self::ADMIN_BYPASS_MODULES, true)) {
            return true;
        }

        $permissions = $this->getPermissionsForRole($roleId);

        if (!isset($permissions[$module])) {
            return false;
        }

        $perm = $permissions[$module];

        return match ($action) {
            'view', 'index' => (bool)$perm['can_view'],
            'add' => (bool)$perm['can_create'],
            'edit' => (bool)$perm['can_edit'],
            'delete' => (bool)$perm['can_delete'],
            default => false,
        };
    }

    /**
     * Retorna los permisos de un rol indexados por módulo (cache per-request).
     *
     * @param int $roleId Id del rol.
     * @return array
     */
    public function getPermissionsForRole(int $roleId): array
    {
        if (isset($this->cache[$roleId])) {
            return $this->cache[$roleId];
        }

        $permissionsTable = TableRegistry::getTableLocator()->get('Permissions');
        $rows = $permissionsTable->find()
            ->where(['role_id' => $roleId])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->module] = [
                'can_view' => $row->can_view,
                'can_create' => $row->can_create,
                'can_edit' => $row->can_edit,
                'can_delete' => $row->can_delete,
            ];
        }

        $this->cache[$roleId] = $result;

        return $result;
    }

    /**
     * Retorna los permisos de un rol como matriz completa de todos los módulos,
     * con los módulos ausentes en false.
     *
     * @param int $roleId Id del rol.
     * @return array
     */
    public function getPermissionsForRoleAsMatrix(int $roleId): array
    {
        $permissions = $this->getPermissionsForRole($roleId);
        $matrix = [];

        foreach (array_keys(self::MODULES) as $module) {
            $matrix[$module] = $permissions[$module] ?? [
                'can_view' => false,
                'can_create' => false,
                'can_edit' => false,
                'can_delete' => false,
            ];
        }

        return $matrix;
    }

    /**
     * Persiste (upsert por módulo) los permisos de un rol e invalida la cache.
     *
     * @param int $roleId Id del rol.
     * @param array $data Datos de permisos indexados por módulo.
     * @return void
     */
    public function savePermissionsForRole(int $roleId, array $data): void
    {
        $permissionsTable = TableRegistry::getTableLocator()->get('Permissions');

        foreach (array_keys(self::MODULES) as $module) {
            $existing = $permissionsTable->find()
                ->where(['role_id' => $roleId, 'module' => $module])
                ->first();

            $moduleData = $data[$module] ?? [];
            $permData = [
                'role_id' => $roleId,
                'module' => $module,
                'can_view' => !empty($moduleData['can_view']),
                'can_create' => !empty($moduleData['can_create']),
                'can_edit' => !empty($moduleData['can_edit']),
                'can_delete' => !empty($moduleData['can_delete']),
            ];

            if ($existing) {
                $existing = $permissionsTable->patchEntity($existing, $permData);
                $permissionsTable->save($existing);
            } else {
                $entity = $permissionsTable->newEntity($permData);
                $permissionsTable->save($entity);
            }
        }

        $this->invalidate($roleId);
    }

    /**
     * Invalida la cache per-request para un rol específico. Llamado tras
     * `savePermissionsForRole` y desde `AuthorizationFacade::invalidate`.
     */
    public function invalidate(int $roleId): void
    {
        unset($this->cache[$roleId]);
    }
}

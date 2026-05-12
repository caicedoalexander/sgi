<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\RoleConstants;
use Cake\ORM\TableRegistry;

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
    ];

    private array $cache = [];

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

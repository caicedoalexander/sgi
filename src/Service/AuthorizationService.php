<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\RoleConstants;
use Cake\ORM\TableRegistry;

class AuthorizationService
{
    // Role name constants — reference centralized constants
    public const ROLE_ADMIN = RoleConstants::ADMIN;

    // Module constants (matching PermissionsTable::MODULES)
    public const MODULES = [
        'invoices' => 'Facturas',
        'providers' => 'Proveedores',
        'operation_centers' => 'Centros de Operación',
        'expense_types' => 'Tipos de Gasto',
        'cost_centers' => 'Centros de Costos',
        'users' => 'Usuarios',
        'roles' => 'Roles',
        'approvers' => 'Aprobadores',
        'employees' => 'Empleados',
        'employee_statuses' => 'Estados de Empleado',
        'marital_statuses' => 'Estados Civiles',
        'education_levels' => 'Niveles Educativos',
        'positions' => 'Cargos',
        'default_folders' => 'Carpetas por Defecto',
        'system_settings' => 'Configuración del Sistema',
        'employee_leaves' => 'Permisos de Empleados',
        'leave_types' => 'Tipos de Permiso',
    ];

    private array $cache = [];

    public function isAllowed(int $roleId, string $roleName, string $module, string $action): bool
    {
        // Admin bypasses all checks
        if ($roleName === self::ROLE_ADMIN) {
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

        // Clear cache for this role
        unset($this->cache[$roleId]);
    }
}

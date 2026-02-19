<?php
/**
 * Script para crear usuario admin y roles iniciales.
 * Uso: php bin/seed-admin.php
 */
define('DS', DIRECTORY_SEPARATOR);
define('ROOT', dirname(__DIR__));

require ROOT . DS . 'vendor' . DS . 'autoload.php';

// Bootstrap CakePHP
require ROOT . DS . 'config' . DS . 'bootstrap.php';

use Cake\ORM\TableRegistry;

$rolesTable = TableRegistry::getTableLocator()->get('Roles');
$usersTable = TableRegistry::getTableLocator()->get('Users');
$permissionsTable = TableRegistry::getTableLocator()->get('Permissions');

// Roles
$roles = [
    ['name' => 'Admin', 'description' => 'Acceso completo al sistema'],
    ['name' => 'Registro/Revisión', 'description' => 'Registro y revisión de facturas'],
    ['name' => 'Contabilidad', 'description' => 'Causación y contabilidad'],
    ['name' => 'Tesorería', 'description' => 'Gestión de pagos'],
];

$roleIds = [];
foreach ($roles as $roleData) {
    $existing = $rolesTable->find()->where(['name' => $roleData['name']])->first();
    if (!$existing) {
        $role = $rolesTable->newEntity($roleData);
        $rolesTable->save($role);
        $roleIds[$roleData['name']] = $role->id;
        echo "Rol creado: {$roleData['name']} (ID: {$role->id})\n";
    } else {
        $roleIds[$roleData['name']] = $existing->id;
        echo "Rol ya existe: {$roleData['name']} (ID: {$existing->id})\n";
    }
}

// Permisos para Admin (todos los módulos, todos los permisos)
$modules = ['invoices', 'providers', 'operation_centers', 'expense_types', 'cost_centers', 'users', 'roles', 'approvers'];
$adminRoleId = $roleIds['Admin'] ?? null;
if ($adminRoleId) {
    foreach ($modules as $module) {
        $existing = $permissionsTable->find()->where(['role_id' => $adminRoleId, 'module' => $module])->first();
        if (!$existing) {
            $perm = $permissionsTable->newEntity([
                'role_id' => $adminRoleId,
                'module' => $module,
                'can_view' => true,
                'can_create' => true,
                'can_edit' => true,
                'can_delete' => true,
            ]);
            $permissionsTable->save($perm);
        }
    }
    echo "Permisos Admin configurados.\n";
}

// Usuario admin
$adminExists = $usersTable->find()->where(['username' => 'admin'])->first();
if (!$adminExists) {
    $admin = $usersTable->newEntity([
        'role_id' => $roleIds['Admin'],
        'username' => 'admin',
        'password' => 'Admin2024*',
        'full_name' => 'Administrador SGI',
        'email' => 'admin@sgi.local',
        'active' => true,
    ]);
    if ($usersTable->save($admin)) {
        echo "Usuario admin creado: admin / Admin2024*\n";
    } else {
        echo "Error creando admin: " . print_r($admin->getErrors(), true) . "\n";
    }
} else {
    echo "Usuario admin ya existe.\n";
}

echo "\nDone!\n";

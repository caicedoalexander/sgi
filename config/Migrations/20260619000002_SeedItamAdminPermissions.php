<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Siembra permisos completos (view/create/edit/delete) para el rol
 * Administrador en los módulos de inventario TI. El Administrador NO bypassa
 * estos módulos (solo users/roles), así que sin esta siembra el módulo sería
 * invisible tras desplegar. Otros roles se asignan desde la UI de Roles.
 * Idempotente.
 */
class SeedItamAdminPermissions extends BaseMigration
{
    private const MODULES = ['assets', 'consumables', 'asset_categories', 'asset_alerts'];

    public function up(): void
    {
        $role = $this->fetchRow("SELECT id FROM roles WHERE name = 'Administrador'");
        if (!$role) {
            return;
        }
        $roleId = (int)($role['id'] ?? $role[0]);

        foreach (self::MODULES as $module) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM permissions WHERE role_id = %d AND module = '%s'",
                $roleId,
                $module,
            ));
            if ($existing) {
                continue;
            }

            $this->table('permissions')->insert([[
                'role_id' => $roleId,
                'module' => $module,
                'can_view' => 1,
                'can_create' => 1,
                'can_edit' => 1,
                'can_delete' => 1,
                'created' => date('Y-m-d H:i:s'),
                'modified' => date('Y-m-d H:i:s'),
            ]])->saveData();
        }
    }

    public function down(): void
    {
        $this->execute(
            "DELETE FROM permissions WHERE module IN ('assets', 'consumables', 'asset_categories', 'asset_alerts')",
        );
    }
}

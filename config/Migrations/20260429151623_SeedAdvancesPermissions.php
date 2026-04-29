<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class SeedAdvancesPermissions extends BaseMigration
{
    /**
     * Permission matrix from docs/plans/2026-04-28-anticipos-design.md (§ "Matriz de roles × estados").
     *
     * @var array<string, array{view:int, create:int, edit:int, delete:int}>
     */
    private const MATRIX = [
        'Administrador' => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 1],
        'Contabilidad' => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 0],
        'Tesorería' => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 0],
        'Registro/Revisión' => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 0],
        'Contador' => ['view' => 1, 'create' => 0, 'edit' => 1, 'delete' => 0],
        'Coordinador Administrativo y Financiero' => ['view' => 1, 'create' => 0, 'edit' => 1, 'delete' => 0],
    ];

    public function up(): void
    {
        foreach (self::MATRIX as $roleName => $perms) {
            $row = $this->fetchRow("SELECT id FROM roles WHERE name = '" . addslashes($roleName) . "'");
            if (!$row) {
                continue;
            }
            $roleId = $row['id'] ?? $row[0];

            $existing = $this->fetchRow(
                "SELECT id FROM permissions WHERE role_id = $roleId AND module = 'advances'"
            );
            if ($existing) {
                continue;
            }

            $this->execute(
                "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
                 VALUES ($roleId, 'advances', {$perms['view']}, {$perms['create']}, {$perms['edit']}, {$perms['delete']}, NOW(), NOW())"
            );
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'advances'");
    }
}

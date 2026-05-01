<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class SeedEmailLogsPermissions extends BaseMigration
{
    /**
     * Solo Administrador. El permiso inline desde invoices/edit y
     * employee_novelties/edit reusa los permisos de esas entidades —
     * la validación se hace dentro de EmailLogsController::retry.
     */
    private const MATRIX = [
        'Administrador' => ['view' => 1, 'create' => 0, 'edit' => 1, 'delete' => 0],
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
                "SELECT id FROM permissions WHERE role_id = $roleId AND module = 'email_logs'"
            );
            if ($existing) {
                continue;
            }

            $this->execute(
                "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
                 VALUES ($roleId, 'email_logs', {$perms['view']}, {$perms['create']}, {$perms['edit']}, {$perms['delete']}, NOW(), NOW())"
            );
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'email_logs'");
    }
}

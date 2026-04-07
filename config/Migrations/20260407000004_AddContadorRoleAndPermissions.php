<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddContadorRoleAndPermissions extends BaseMigration
{
    public function up(): void
    {
        // Insert Contador role if not exists
        $exists = $this->fetchRow("SELECT id FROM roles WHERE name = 'Contador'");
        if (!$exists) {
            $this->execute("INSERT INTO roles (name, created, modified) VALUES ('Contador', NOW(), NOW())");
        }

        // Get Contador role_id
        $row = $this->fetchRow("SELECT id FROM roles WHERE name = 'Contador'");
        $contadorRoleId = $row['id'] ?? $row[0];

        // Contador: can view + edit invoices
        $existsPerm = $this->fetchRow(
            "SELECT id FROM permissions WHERE role_id = $contadorRoleId AND module = 'invoices'"
        );
        if (!$existsPerm) {
            $this->execute(
                "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
                 VALUES ($contadorRoleId, 'invoices', 1, 0, 1, 0, NOW(), NOW())"
            );
        }

        // Add banking_entities permission for Admin (role_id=1), Tesorería, Contador
        $adminRow = $this->fetchRow("SELECT id FROM roles WHERE name = 'Administrador'");
        $adminId = $adminRow['id'] ?? $adminRow[0] ?? 1;

        $tesoreriaRow = $this->fetchRow("SELECT id FROM roles WHERE name = 'Tesorería'");
        $tesoreriaId = $tesoreriaRow ? ($tesoreriaRow['id'] ?? $tesoreriaRow[0]) : null;

        foreach ([$adminId, $tesoreriaId, $contadorRoleId] as $roleId) {
            if (!$roleId) continue;
            $ep = $this->fetchRow(
                "SELECT id FROM permissions WHERE role_id = $roleId AND module = 'banking_entities'"
            );
            if (!$ep) {
                $canAll = ($roleId == $adminId) ? 1 : 0;
                $this->execute(
                    "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
                     VALUES ($roleId, 'banking_entities', 1, $canAll, $canAll, $canAll, NOW(), NOW())"
                );
            }
        }
    }

    public function down(): void
    {
        $row = $this->fetchRow("SELECT id FROM roles WHERE name = 'Contador'");
        if ($row) {
            $contadorRoleId = $row['id'] ?? $row[0];
            $this->execute("DELETE FROM permissions WHERE role_id = $contadorRoleId");
            $this->execute("DELETE FROM roles WHERE id = $contadorRoleId");
        }
        $this->execute("DELETE FROM permissions WHERE module = 'banking_entities'");
    }
}

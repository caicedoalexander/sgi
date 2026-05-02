<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class SeedRefundsPermissions extends BaseMigration
{
    public function up(): void
    {
        $existing = $this->fetchAll("SELECT role_id FROM permissions WHERE module = 'refunds'");
        if (!empty($existing)) {
            // Idempotente: si ya hay filas (rerun parcial) no duplicar.
            return;
        }

        $roles = $this->fetchAll('SELECT id, name FROM roles');

        foreach ($roles as $role) {
            $isAdmin = $role['name'] === 'Administrador';

            $this->execute(sprintf(
                "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) "
                . 'VALUES (%d, \'refunds\', %d, %d, %d, %d)',
                $role['id'],
                1,
                $isAdmin ? 1 : 0,
                $isAdmin ? 1 : 0,
                $isAdmin ? 1 : 0,
            ));
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'refunds'");
    }
}

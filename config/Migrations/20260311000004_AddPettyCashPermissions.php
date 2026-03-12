<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddPettyCashPermissions extends BaseMigration
{
    public function up(): void
    {
        // Get all existing roles
        $roles = $this->fetchAll('SELECT id, name FROM roles');

        foreach ($roles as $role) {
            $isAdmin = $role['name'] === 'Administrador';

            $this->execute(sprintf(
                "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES (%d, 'petty_cash', %d, %d, %d, %d)",
                $role['id'],
                1, // can_view for all
                $isAdmin ? 1 : 0,
                $isAdmin ? 1 : 0,
                $isAdmin ? 1 : 0,
            ));
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'petty_cash'");
    }
}

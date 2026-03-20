<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddLegalizationPermissions extends BaseMigration
{
    public function up(): void
    {
        $rows = $this->fetchAll('SELECT id, name FROM roles');

        foreach ($rows as $row) {
            $isAdmin = $row['name'] === 'Administrador';
            $this->execute(sprintf(
                "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete)
                 VALUES (%d, 'legalizations', %d, %d, %d, %d)",
                $row['id'],
                1,
                $isAdmin ? 1 : 0,
                $isAdmin ? 1 : 0,
                $isAdmin ? 1 : 0,
            ));
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'legalizations'");
    }
}

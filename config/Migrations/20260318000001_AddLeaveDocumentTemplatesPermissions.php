<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddLeaveDocumentTemplatesPermissions extends BaseMigration
{
    public function up(): void
    {
        $roles = $this->fetchAll('SELECT id, name FROM roles');

        foreach ($roles as $role) {
            $isAdmin = $role['name'] === 'Administrador';

            $this->execute(sprintf(
                "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES (%d, 'leave_document_templates', %d, %d, %d, %d)",
                $role['id'],
                $isAdmin ? 1 : 0,
                $isAdmin ? 1 : 0,
                $isAdmin ? 1 : 0,
                $isAdmin ? 1 : 0,
            ));
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'leave_document_templates'");
    }
}

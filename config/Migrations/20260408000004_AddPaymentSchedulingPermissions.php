<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddPaymentSchedulingPermissions extends BaseMigration
{
    public function up(): void
    {
        // Tesorería (role_id=3): full CRUD
        $this->execute("INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete)
            VALUES (3, 'payment_schedulings', 1, 1, 1, 1)
            ON DUPLICATE KEY UPDATE can_view=1, can_create=1, can_edit=1, can_delete=1");

        // Contador (role_id from roles table — look up dynamically)
        $this->execute("INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete)
            SELECT id, 'payment_schedulings', 1, 0, 1, 0
            FROM roles WHERE name = 'Contador'
            ON DUPLICATE KEY UPDATE can_view=1, can_edit=1");
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'payment_schedulings'");
    }
}

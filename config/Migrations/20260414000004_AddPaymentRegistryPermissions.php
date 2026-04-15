<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddPaymentRegistryPermissions extends BaseMigration
{
    public function up(): void
    {
        // Find Contador role ID
        $rows = $this->fetchAll("SELECT id FROM roles WHERE name = 'Contador'");
        $contadorId = $rows[0]['id'] ?? null;

        $permissions = [
            ['role_id' => 1, 'module' => 'payment_registry', 'can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false],
            ['role_id' => 3, 'module' => 'payment_registry', 'can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false],
        ];

        if ($contadorId) {
            $permissions[] = ['role_id' => $contadorId, 'module' => 'payment_registry', 'can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false];
        }

        $table = $this->table('permissions');
        foreach ($permissions as $perm) {
            $table->insert($perm);
        }
        $table->saveData();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'payment_registry'");
    }
}

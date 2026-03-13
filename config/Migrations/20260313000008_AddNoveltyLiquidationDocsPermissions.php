<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddNoveltyLiquidationDocsPermissions extends BaseMigration
{
    public function up(): void
    {
        // Get all role IDs
        $roles = $this->fetchAll('SELECT id FROM roles');
        foreach ($roles as $role) {
            $this->execute(sprintf(
                "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES (%d, 'novelty_liquidation_docs', 1, 1, 1, 0)",
                $role['id']
            ));
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'novelty_liquidation_docs'");
    }
}

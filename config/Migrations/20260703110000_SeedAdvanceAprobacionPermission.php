<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Otorga el step 'aprobacion' del pipeline 'legalizations' a los mismos roles
 * que hoy operan 'validacion', para que el nuevo estado tenga operadores desde
 * el deploy (gestionar aprobadores + consolidar).
 */
class SeedAdvanceAprobacionPermission extends BaseMigration
{
    public function up(): void
    {
        $rows = $this->fetchAll(
            "SELECT role_id FROM pipeline_permissions
             WHERE pipeline = 'legalizations' AND step = 'validacion' AND can_operate = 1"
        );
        foreach ($rows as $row) {
            $roleId = (int)$row['role_id'];
            $exists = $this->fetchRow(
                "SELECT id FROM pipeline_permissions
                 WHERE pipeline = 'legalizations' AND step = 'aprobacion' AND role_id = {$roleId}"
            );
            if (!$exists) {
                $this->execute(
                    "INSERT INTO pipeline_permissions (role_id, pipeline, step, can_operate, created, modified)
                     VALUES ({$roleId}, 'legalizations', 'aprobacion', 1, NOW(), NOW())"
                );
            }
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM pipeline_permissions WHERE pipeline = 'legalizations' AND step = 'aprobacion'");
    }
}

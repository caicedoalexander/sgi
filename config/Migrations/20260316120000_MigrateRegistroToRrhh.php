<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Migrate existing novelties from 'registro' to 'rrhh' status.
 * The 'registro' status has been removed from the pipeline — new novelties
 * now start directly at 'rrhh' (or the first active stage).
 */
class MigrateRegistroToRrhh extends BaseMigration
{
    public function up(): void
    {
        $this->execute("UPDATE employee_novelties SET pipeline_status = 'rrhh' WHERE pipeline_status = 'registro'");
    }

    public function down(): void
    {
        // No rollback — cannot determine which were originally 'registro'
    }
}

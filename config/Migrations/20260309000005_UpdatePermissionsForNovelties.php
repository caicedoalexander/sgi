<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class UpdatePermissionsForNovelties extends BaseMigration
{
    public function up(): void
    {
        // Rename module references in permissions table
        $this->execute("UPDATE permissions SET module = 'employee_novelties' WHERE module = 'employee_leaves'");
        $this->execute("UPDATE permissions SET module = 'novelty_types' WHERE module = 'leave_types'");
        $this->execute("DELETE FROM permissions WHERE module = 'employee_incidents'");
    }

    public function down(): void
    {
        $this->execute("UPDATE permissions SET module = 'employee_leaves' WHERE module = 'employee_novelties'");
        $this->execute("UPDATE permissions SET module = 'leave_types' WHERE module = 'novelty_types'");
    }
}

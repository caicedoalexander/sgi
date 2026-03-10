<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class DropLegacyLeaveTables extends BaseMigration
{
    public function up(): void
    {
        // Drop in correct FK order
        if ($this->hasTable('leave_type_contract_templates')) {
            $this->table('leave_type_contract_templates')->drop()->save();
        }
        if ($this->hasTable('employee_leaves')) {
            $this->table('employee_leaves')->drop()->save();
        }
        if ($this->hasTable('leave_types')) {
            $this->table('leave_types')->drop()->save();
        }
        if ($this->hasTable('employee_incidents')) {
            $this->table('employee_incidents')->drop()->save();
        }
    }

    public function down(): void
    {
        // No rollback — tables would need to be recreated from original migrations
    }
}

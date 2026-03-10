<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class RenameHorarioToScheduleType extends BaseMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE employee_leaves CHANGE horario schedule_type VARCHAR(20) DEFAULT NULL");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE employee_leaves CHANGE schedule_type horario VARCHAR(20) DEFAULT NULL");
    }
}

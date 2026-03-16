<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class MakeConditionalNoveltyFieldsNullable extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('employee_novelties');
        $table->changeColumn('permission_date', 'date', ['null' => true, 'default' => null])
            ->changeColumn('schedule_type', 'string', ['limit' => 10, 'null' => true, 'default' => null])
            ->changeColumn('reason', 'text', ['null' => true, 'default' => null])
            ->update();
    }

    public function down(): void
    {
        $table = $this->table('employee_novelties');
        $table->changeColumn('permission_date', 'date', ['null' => false])
            ->changeColumn('schedule_type', 'string', ['limit' => 10, 'null' => false])
            ->changeColumn('reason', 'text', ['null' => false])
            ->update();
    }
}

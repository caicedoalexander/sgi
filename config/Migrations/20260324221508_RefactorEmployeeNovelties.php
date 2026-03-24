<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class RefactorEmployeeNovelties extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('employee_novelties');

        // Add approver_id column
        $table->addColumn('approver_id', 'integer', [
            'null' => true,
            'default' => null,
            'signed' => true,
            'after' => 'approved_at',
        ]);
        $table->addForeignKey('approver_id', 'users', 'id', [
            'delete' => 'SET_NULL',
            'update' => 'NO_ACTION',
        ]);

        // Add area_approval column for rejection tracking
        $table->addColumn('area_approval', 'string', [
            'limit' => 20,
            'null' => true,
            'default' => null,
            'after' => 'approver_id',
        ]);

        // Remove coordinator_signature
        $table->removeColumn('coordinator_signature');

        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('employee_novelties');
        $table->dropForeignKey('approver_id');
        $table->removeColumn('approver_id');
        $table->removeColumn('area_approval');
        $table->addColumn('coordinator_signature', 'string', ['limit' => 255, 'null' => true]);
        $table->update();
    }
}

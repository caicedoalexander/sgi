<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateLeaveTypes extends BaseMigration
{
    public function up(): void
    {
        $this->table('leave_types')
            ->addColumn('code', 'string', [
                'limit' => 20,
                'null' => false,
            ])
            ->addColumn('name', 'string', [
                'limit' => 100,
                'null' => false,
            ])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['code'], ['unique' => true, 'name' => 'uq_leave_types_code'])
            ->create();
    }

    public function down(): void
    {
        $this->table('leave_types')->drop()->save();
    }
}

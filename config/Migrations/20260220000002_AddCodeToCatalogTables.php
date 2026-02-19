<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddCodeToCatalogTables extends BaseMigration
{
    public function up(): void
    {
        $this->table('cost_centers')
            ->addColumn('code', 'string', [
                'limit' => 20,
                'null' => true,
                'default' => null,
                'after' => 'id',
            ])
            ->addIndex(['code'], ['unique' => true, 'name' => 'uq_cost_centers_code'])
            ->update();

        $this->table('operation_centers')
            ->addColumn('code', 'string', [
                'limit' => 20,
                'null' => true,
                'default' => null,
                'after' => 'id',
            ])
            ->addIndex(['code'], ['unique' => true, 'name' => 'uq_operation_centers_code'])
            ->update();
    }

    public function down(): void
    {
        $this->table('cost_centers')
            ->removeIndex(['code'])
            ->removeColumn('code')
            ->update();

        $this->table('operation_centers')
            ->removeIndex(['code'])
            ->removeColumn('code')
            ->update();
    }
}

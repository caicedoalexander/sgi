<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddPipelineFlagsToNoveltyTypes extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('novelty_types');
        $table
            ->addColumn('requires_rrhh', 'boolean', ['default' => true, 'null' => false, 'after' => 'parent_id'])
            ->addColumn('requires_firmas', 'boolean', ['default' => true, 'null' => false, 'after' => 'requires_rrhh'])
            ->addColumn('requires_gdp', 'boolean', ['default' => true, 'null' => false, 'after' => 'requires_firmas'])
            ->addColumn('requires_tesoreria', 'boolean', ['default' => true, 'null' => false, 'after' => 'requires_gdp'])
            ->addColumn('show_start_date', 'boolean', ['default' => true, 'null' => false, 'after' => 'requires_tesoreria'])
            ->addColumn('show_end_date', 'boolean', ['default' => true, 'null' => false, 'after' => 'show_start_date'])
            ->addColumn('show_permission_date', 'boolean', ['default' => true, 'null' => false, 'after' => 'show_end_date'])
            ->addColumn('show_schedule_type', 'boolean', ['default' => true, 'null' => false, 'after' => 'show_permission_date'])
            ->addColumn('uses_custom_name', 'boolean', ['default' => false, 'null' => false, 'after' => 'show_schedule_type'])
            ->addColumn('is_massive', 'boolean', ['default' => false, 'null' => false, 'after' => 'uses_custom_name'])
            ->update();
    }

    public function down(): void
    {
        $table = $this->table('novelty_types');
        $table
            ->removeColumn('requires_rrhh')
            ->removeColumn('requires_firmas')
            ->removeColumn('requires_gdp')
            ->removeColumn('requires_tesoreria')
            ->removeColumn('show_start_date')
            ->removeColumn('show_end_date')
            ->removeColumn('show_permission_date')
            ->removeColumn('show_schedule_type')
            ->removeColumn('uses_custom_name')
            ->removeColumn('is_massive')
            ->update();
    }
}

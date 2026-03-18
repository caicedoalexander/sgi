<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class RemoveCodeFromSimpleCatalogs extends BaseMigration
{
    public function up(): void
    {
        $tables = ['marital_statuses', 'employee_statuses', 'education_levels', 'default_folders'];

        foreach ($tables as $tableName) {
            $table = $this->table($tableName);
            if ($table->hasColumn('code')) {
                $table->removeColumn('code');
                $table->update();
            }
        }
    }

    public function down(): void
    {
        $tables = ['marital_statuses', 'employee_statuses', 'education_levels', 'default_folders'];

        foreach ($tables as $tableName) {
            $table = $this->table($tableName);
            $table->addColumn('code', 'string', [
                'limit' => 20,
                'null' => true,
                'default' => null,
                'after' => 'id',
            ]);
            $table->addIndex(['code'], ['unique' => true]);
            $table->update();
        }
    }
}

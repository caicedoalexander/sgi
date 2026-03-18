<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class RenameLastNameToLastName1AddLastName2 extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('employees');
        $table->renameColumn('last_name', 'last_name1');
        $table->addColumn('last_name2', 'string', [
            'limit' => 100,
            'null' => true,
            'default' => null,
            'after' => 'last_name1',
        ]);
        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('employees');
        $table->removeColumn('last_name2');
        $table->renameColumn('last_name1', 'last_name');
        $table->update();
    }
}

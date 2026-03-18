<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class RemoveActiveFromEmployees extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('employees');
        if ($table->hasColumn('active')) {
            $table->removeColumn('active');
            $table->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('employees');
        $table->addColumn('active', 'boolean', [
            'default' => true,
            'null' => false,
        ]);
        $table->update();
    }
}

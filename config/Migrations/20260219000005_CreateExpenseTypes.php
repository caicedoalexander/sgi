<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateExpenseTypes extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('expense_types');
        $table->addColumn('name', 'string', [
            'limit' => 150,
            'null' => false,
        ]);
        $table->addColumn('created', 'datetime', [
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('modified', 'datetime', [
            'null' => true,
            'default' => null,
        ]);
        $table->create();
    }
}

<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateProviders extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('providers');
        $table->addColumn('nit', 'string', [
            'limit' => 20,
            'null' => false,
        ]);
        $table->addColumn('name', 'string', [
            'limit' => 200,
            'null' => false,
        ]);
        $table->addColumn('active', 'boolean', [
            'default' => true,
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
        $table->addIndex(['nit'], ['unique' => true]);
        $table->create();
    }
}

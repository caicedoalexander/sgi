<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateRoles extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('roles');
        $table->addColumn('name', 'string', [
            'limit' => 100,
            'null' => false,
        ]);
        $table->addColumn('description', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
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

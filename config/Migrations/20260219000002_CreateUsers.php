<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateUsers extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('users');
        $table->addColumn('role_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('username', 'string', [
            'limit' => 50,
            'null' => false,
        ]);
        $table->addColumn('password', 'string', [
            'limit' => 255,
            'null' => false,
        ]);
        $table->addColumn('full_name', 'string', [
            'limit' => 150,
            'null' => false,
        ]);
        $table->addColumn('email', 'string', [
            'limit' => 100,
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
        $table->addIndex(['username'], ['unique' => true]);
        $table->addIndex(['email'], ['unique' => true]);
        $table->addForeignKey('role_id', 'roles', 'id', [
            'delete' => 'RESTRICT',
            'update' => 'NO_ACTION',
        ]);
        $table->create();
    }
}

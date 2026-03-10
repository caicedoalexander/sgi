<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateNoveltyTypes extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('novelty_types')) {
            $this->table('novelty_types')
                ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
                ->addColumn('parent_id', 'integer', ['null' => true, 'default' => null])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addForeignKey('parent_id', 'novelty_types', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'NO_ACTION',
                ])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('novelty_types')) {
            $this->table('novelty_types')->drop()->save();
        }
    }
}

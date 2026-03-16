<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateNoveltyHistories extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('novelty_histories')) {
            $this->table('novelty_histories')
                ->addColumn('novelty_id', 'integer', ['null' => false])
                ->addColumn('user_id', 'integer', ['null' => false])
                ->addColumn('field_changed', 'string', ['limit' => 100, 'null' => false])
                ->addColumn('old_value', 'text', ['null' => true, 'default' => null])
                ->addColumn('new_value', 'text', ['null' => true, 'default' => null])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addForeignKey('novelty_id', 'employee_novelties', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('novelty_histories')) {
            $this->table('novelty_histories')->drop()->save();
        }
    }
}

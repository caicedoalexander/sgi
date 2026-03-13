<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateNoveltyObservations extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('novelty_observations')) {
            $this->table('novelty_observations')
                ->addColumn('novelty_id', 'integer', ['null' => true, 'default' => null])
                ->addColumn('liquidation_doc_id', 'integer', ['null' => true, 'default' => null])
                ->addColumn('user_id', 'integer', ['null' => false])
                ->addColumn('message', 'text', ['null' => false])
                ->addColumn('is_read', 'boolean', ['null' => false, 'default' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addForeignKey('novelty_id', 'employee_novelties', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('liquidation_doc_id', 'novelty_liquidation_docs', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('novelty_observations')) {
            $this->table('novelty_observations')->drop()->save();
        }
    }
}

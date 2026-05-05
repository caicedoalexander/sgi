<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePettyCashHistories extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('petty_cash_histories')) {
            $this->table('petty_cash_histories')
                ->addColumn('petty_cash_record_id', 'integer', ['null' => false])
                ->addColumn('user_id', 'integer', ['null' => false])
                ->addColumn('field_changed', 'string', ['limit' => 100, 'null' => false])
                ->addColumn('old_value', 'text', ['null' => true, 'default' => null])
                ->addColumn('new_value', 'text', ['null' => true, 'default' => null])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addIndex(['petty_cash_record_id'])
                ->addForeignKey(
                    'petty_cash_record_id',
                    'petty_cash_records',
                    'id',
                    ['delete' => 'CASCADE', 'update' => 'NO_ACTION'],
                )
                ->addForeignKey(
                    'user_id',
                    'users',
                    'id',
                    ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'],
                )
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('petty_cash_histories')) {
            $this->table('petty_cash_histories')->drop()->save();
        }
    }
}

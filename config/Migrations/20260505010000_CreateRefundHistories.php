<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateRefundHistories extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('refund_histories')) {
            $this->table('refund_histories')
                ->addColumn('refund_id', 'integer', ['null' => false])
                ->addColumn('user_id', 'integer', ['null' => false])
                ->addColumn('field_changed', 'string', ['limit' => 100, 'null' => false])
                ->addColumn('old_value', 'text', ['null' => true, 'default' => null])
                ->addColumn('new_value', 'text', ['null' => true, 'default' => null])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addIndex(['refund_id'])
                ->addForeignKey(
                    'refund_id',
                    'refunds',
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
        if ($this->hasTable('refund_histories')) {
            $this->table('refund_histories')->drop()->save();
        }
    }
}

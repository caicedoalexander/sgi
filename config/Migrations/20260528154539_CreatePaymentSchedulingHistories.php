<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePaymentSchedulingHistories extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('payment_scheduling_histories')) {
            $this->table('payment_scheduling_histories')
                ->addColumn('payment_scheduling_id', 'integer', ['null' => false])
                ->addColumn('user_id', 'integer', ['null' => false])
                ->addColumn('field_changed', 'string', ['limit' => 100, 'null' => false])
                ->addColumn('old_value', 'text', ['null' => true, 'default' => null])
                ->addColumn('new_value', 'text', ['null' => true, 'default' => null])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addIndex(['payment_scheduling_id'])
                ->addForeignKey(
                    'payment_scheduling_id',
                    'payment_schedulings',
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
        if ($this->hasTable('payment_scheduling_histories')) {
            $this->table('payment_scheduling_histories')->drop()->save();
        }
    }
}

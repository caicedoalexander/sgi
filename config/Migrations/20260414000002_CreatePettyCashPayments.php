<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePettyCashPayments extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('petty_cash_payments')) {
            $this->table('petty_cash_payments')
                ->addColumn('petty_cash_record_id', 'integer', ['null' => false])
                ->addColumn('banking_entity_id', 'integer', ['null' => false])
                ->addColumn('amount', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => false])
                ->addColumn('payment_date', 'date', ['null' => false])
                ->addColumn('authorized', 'boolean', ['default' => false, 'null' => false])
                ->addColumn('authorized_by', 'integer', ['null' => true])
                ->addColumn('authorized_date', 'date', ['null' => true])
                ->addColumn('created_by', 'integer', ['null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['petty_cash_record_id'])
                ->addForeignKey('petty_cash_record_id', 'petty_cash_records', 'id', [
                    'delete' => 'CASCADE', 'update' => 'NO_ACTION',
                ])
                ->addForeignKey('banking_entity_id', 'banking_entities', 'id', [
                    'delete' => 'RESTRICT', 'update' => 'NO_ACTION',
                ])
                ->addForeignKey('authorized_by', 'users', 'id', [
                    'delete' => 'SET_NULL', 'update' => 'NO_ACTION',
                ])
                ->addForeignKey('created_by', 'users', 'id', [
                    'delete' => 'RESTRICT', 'update' => 'NO_ACTION',
                ])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('petty_cash_payments')) {
            $this->table('petty_cash_payments')->drop()->save();
        }
    }
}

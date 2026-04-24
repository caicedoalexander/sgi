<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class ConvertPettyCashPaymentsToRecordFields extends BaseMigration
{
    public function up(): void
    {
        $this->table('petty_cash_records')
            ->addColumn('banking_entity_id', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'payment_date',
            ])
            ->addColumn('payment_amount', 'decimal', [
                'precision' => 15,
                'scale' => 2,
                'null' => true,
                'default' => null,
                'after' => 'banking_entity_id',
            ])
            ->addColumn('payment_created_by', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'payment_amount',
            ])
            ->addColumn('payment_authorized_by', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'payment_created_by',
            ])
            ->addColumn('payment_authorized_date', 'date', [
                'null' => true,
                'default' => null,
                'after' => 'payment_authorized_by',
            ])
            ->addColumn('payment_rejection_reason', 'text', [
                'null' => true,
                'default' => null,
                'after' => 'payment_authorized_date',
            ])
            ->addForeignKey('banking_entity_id', 'banking_entities', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('payment_created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('payment_authorized_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();

        if ($this->hasTable('petty_cash_payments')) {
            $this->table('petty_cash_payments')->drop()->save();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('petty_cash_payments')) {
            $this->table('petty_cash_payments')
                ->addColumn('petty_cash_record_id', 'integer', ['null' => false])
                ->addColumn('banking_entity_id', 'integer', ['null' => false])
                ->addColumn('amount', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => false])
                ->addColumn('payment_date', 'date', ['null' => false])
                ->addColumn('authorized', 'boolean', ['null' => false, 'default' => false])
                ->addColumn('authorized_by', 'integer', ['null' => true])
                ->addColumn('authorized_date', 'date', ['null' => true])
                ->addColumn('status', 'string', ['limit' => 20, 'null' => true])
                ->addColumn('rejection_reason', 'text', ['null' => true])
                ->addColumn('created_by', 'integer', ['null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->create();
        }

        $this->table('petty_cash_records')
            ->dropForeignKey('banking_entity_id')
            ->dropForeignKey('payment_created_by')
            ->dropForeignKey('payment_authorized_by')
            ->removeColumn('banking_entity_id')
            ->removeColumn('payment_amount')
            ->removeColumn('payment_created_by')
            ->removeColumn('payment_authorized_by')
            ->removeColumn('payment_authorized_date')
            ->removeColumn('payment_rejection_reason')
            ->update();
    }
}

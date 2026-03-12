<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddAccountingFieldsAndObservationsToPettyCash extends BaseMigration
{
    public function up(): void
    {
        // Add accounting and treasury fields to petty_cash_records
        if ($this->hasTable('petty_cash_records')) {
            $table = $this->table('petty_cash_records');
            $table->addColumn('accrued', 'boolean', [
                'null' => false,
                'default' => false,
                'after' => 'total_amount',
            ]);
            $table->addColumn('accrual_date', 'date', [
                'null' => true,
                'default' => null,
                'after' => 'accrued',
            ]);
            $table->addColumn('ready_for_payment', 'string', [
                'limit' => 50,
                'null' => true,
                'default' => null,
                'after' => 'accrual_date',
            ]);
            $table->addColumn('payment_status', 'string', [
                'limit' => 30,
                'null' => true,
                'default' => null,
                'after' => 'ready_for_payment',
            ]);
            $table->addColumn('payment_date', 'date', [
                'null' => true,
                'default' => null,
                'after' => 'payment_status',
            ]);
            $table->update();
        }

        // Create petty_cash_observations table
        if (!$this->hasTable('petty_cash_observations')) {
            $obs = $this->table('petty_cash_observations');
            $obs->addColumn('petty_cash_record_id', 'integer', [
                'null' => false,
                'signed' => true,
            ]);
            $obs->addColumn('user_id', 'integer', [
                'null' => false,
                'signed' => true,
            ]);
            $obs->addColumn('message', 'text', [
                'null' => false,
            ]);
            $obs->addColumn('created', 'datetime', [
                'null' => true,
                'default' => null,
            ]);
            $obs->addForeignKey('petty_cash_record_id', 'petty_cash_records', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ]);
            $obs->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ]);
            $obs->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('petty_cash_observations')) {
            $this->table('petty_cash_observations')->drop()->save();
        }

        if ($this->hasTable('petty_cash_records')) {
            $table = $this->table('petty_cash_records');
            $table->removeColumn('accrued');
            $table->removeColumn('accrual_date');
            $table->removeColumn('ready_for_payment');
            $table->removeColumn('payment_status');
            $table->removeColumn('payment_date');
            $table->update();
        }
    }
}

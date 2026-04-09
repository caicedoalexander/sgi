<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePaymentSchedulingTables extends BaseMigration
{
    public function up(): void
    {
        // payment_schedulings
        if (!$this->hasTable('payment_schedulings')) {
            $this->table('payment_schedulings')
                ->addColumn('code', 'string', ['limit' => 20, 'null' => false])
                ->addColumn('title', 'string', ['limit' => 255, 'null' => false])
                ->addColumn('pipeline_status', 'string', ['limit' => 50, 'null' => false, 'default' => 'borrador'])
                ->addColumn('created_by', 'integer', ['null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['code'], ['unique' => true])
                ->addForeignKey('created_by', 'users', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'NO_ACTION',
                ])
                ->create();
        }

        // payment_scheduling_items
        if (!$this->hasTable('payment_scheduling_items')) {
            $this->table('payment_scheduling_items')
                ->addColumn('payment_scheduling_id', 'integer', ['null' => false])
                ->addColumn('invoice_id', 'integer', ['null' => false])
                ->addColumn('banking_entity_id', 'integer', ['null' => false])
                ->addColumn('amount', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addIndex(['payment_scheduling_id'])
                ->addIndex(['invoice_id'])
                ->addForeignKey('payment_scheduling_id', 'payment_schedulings', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'NO_ACTION',
                ])
                ->addForeignKey('invoice_id', 'invoices', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'NO_ACTION',
                ])
                ->addForeignKey('banking_entity_id', 'banking_entities', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'NO_ACTION',
                ])
                ->create();
        }

        // payment_scheduling_attachments
        if (!$this->hasTable('payment_scheduling_attachments')) {
            $this->table('payment_scheduling_attachments')
                ->addColumn('payment_scheduling_id', 'integer', ['null' => false])
                ->addColumn('file_path', 'string', ['limit' => 500, 'null' => false])
                ->addColumn('file_name', 'string', ['limit' => 255, 'null' => false])
                ->addColumn('uploaded_by', 'integer', ['null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addIndex(['payment_scheduling_id'])
                ->addForeignKey('payment_scheduling_id', 'payment_schedulings', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'NO_ACTION',
                ])
                ->addForeignKey('uploaded_by', 'users', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'NO_ACTION',
                ])
                ->create();
        }

        // payment_scheduling_observations
        if (!$this->hasTable('payment_scheduling_observations')) {
            $this->table('payment_scheduling_observations')
                ->addColumn('payment_scheduling_id', 'integer', ['null' => false])
                ->addColumn('user_id', 'integer', ['null' => false])
                ->addColumn('message', 'text', ['null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addIndex(['payment_scheduling_id'])
                ->addForeignKey('payment_scheduling_id', 'payment_schedulings', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'NO_ACTION',
                ])
                ->addForeignKey('user_id', 'users', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'NO_ACTION',
                ])
                ->create();
        }

        // Add FK from invoice_payments to payment_schedulings
        $this->table('invoice_payments')
            ->addForeignKey('payment_scheduling_id', 'payment_schedulings', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('invoice_payments')
            ->dropForeignKey('payment_scheduling_id')
            ->update();

        if ($this->hasTable('payment_scheduling_observations')) {
            $this->table('payment_scheduling_observations')->drop()->save();
        }
        if ($this->hasTable('payment_scheduling_attachments')) {
            $this->table('payment_scheduling_attachments')->drop()->save();
        }
        if ($this->hasTable('payment_scheduling_items')) {
            $this->table('payment_scheduling_items')->drop()->save();
        }
        if ($this->hasTable('payment_schedulings')) {
            $this->table('payment_schedulings')->drop()->save();
        }
    }
}

<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateRefunds extends BaseMigration
{
    public function up(): void
    {
        // refunds (parent record)
        if (!$this->hasTable('refunds')) {
            $table = $this->table('refunds');

            $table->addColumn('code', 'string', [
                'limit' => 30,
                'null' => false,
            ]);
            $table->addColumn('status', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => 'agrupacion',
            ]);
            $table->addColumn('total_amount', 'decimal', [
                'precision' => 15,
                'scale' => 2,
                'null' => false,
                'default' => 0,
            ]);

            // Beneficiary (polymorphic: employee XOR provider)
            $table->addColumn('beneficiary_type', 'string', [
                'limit' => 20,
                'null' => true,
                'default' => null,
            ]);
            $table->addColumn('beneficiary_employee_id', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => true,
            ]);
            $table->addColumn('beneficiary_provider_id', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => true,
            ]);

            // Accounting fields
            $table->addColumn('accrued', 'boolean', [
                'null' => false,
                'default' => false,
            ]);
            $table->addColumn('accrual_date', 'date', [
                'null' => true,
                'default' => null,
            ]);
            $table->addColumn('ready_for_payment', 'string', [
                'limit' => 40,
                'null' => true,
                'default' => null,
            ]);

            // Payment fields
            $table->addColumn('banking_entity_id', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => true,
            ]);
            $table->addColumn('payment_amount', 'decimal', [
                'precision' => 15,
                'scale' => 2,
                'null' => true,
                'default' => null,
            ]);
            $table->addColumn('payment_date', 'date', [
                'null' => true,
                'default' => null,
            ]);
            $table->addColumn('payment_created_by', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => true,
            ]);
            $table->addColumn('payment_authorized_by', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => true,
            ]);
            $table->addColumn('payment_authorized_date', 'date', [
                'null' => true,
                'default' => null,
            ]);
            $table->addColumn('payment_status', 'string', [
                'limit' => 40,
                'null' => true,
                'default' => null,
            ]);
            $table->addColumn('payment_rejection_reason', 'text', [
                'null' => true,
                'default' => null,
            ]);

            // Audit
            $table->addColumn('created_by', 'integer', [
                'null' => false,
                'signed' => true,
            ]);
            $table->addColumn('created', 'datetime', [
                'null' => true,
                'default' => null,
            ]);
            $table->addColumn('modified', 'datetime', [
                'null' => true,
                'default' => null,
            ]);

            $table->addIndex(['code'], ['unique' => true]);
            $table->addIndex(['status']);
            $table->addIndex(['beneficiary_employee_id']);
            $table->addIndex(['beneficiary_provider_id']);

            $table->addForeignKey('beneficiary_employee_id', 'employees', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ]);
            $table->addForeignKey('beneficiary_provider_id', 'providers', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ]);
            $table->addForeignKey('banking_entity_id', 'banking_entities', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ]);
            $table->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ]);
            $table->addForeignKey('payment_created_by', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ]);
            $table->addForeignKey('payment_authorized_by', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ]);

            $table->create();
        }

        // refund_observations
        if (!$this->hasTable('refund_observations')) {
            $obs = $this->table('refund_observations');

            $obs->addColumn('refund_id', 'integer', [
                'null' => false,
                'signed' => true,
            ]);
            $obs->addColumn('user_id', 'integer', [
                'null' => false,
                'signed' => true,
            ]);
            $obs->addColumn('type', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => 'general',
            ]);
            $obs->addColumn('message', 'text', [
                'null' => false,
            ]);
            $obs->addColumn('metadata', 'json', [
                'null' => true,
                'default' => null,
            ]);
            $obs->addColumn('created', 'datetime', [
                'null' => true,
                'default' => null,
            ]);

            $obs->addForeignKey('refund_id', 'refunds', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ]);
            $obs->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ]);

            $obs->create();
        }

        // invoices.refund_id
        if ($this->hasTable('invoices')) {
            $invoices = $this->table('invoices');
            if (!$invoices->hasColumn('refund_id')) {
                $invoices->addColumn('refund_id', 'integer', [
                    'null' => true,
                    'default' => null,
                    'signed' => true,
                ]);
                $invoices->addForeignKey('refund_id', 'refunds', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                ]);
                $invoices->update();
            }
        }

        // invoice_payments.refund_id
        if ($this->hasTable('invoice_payments')) {
            $payments = $this->table('invoice_payments');
            if (!$payments->hasColumn('refund_id')) {
                $payments->addColumn('refund_id', 'integer', [
                    'null' => true,
                    'default' => null,
                    'signed' => true,
                ]);
                $payments->addForeignKey('refund_id', 'refunds', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                ]);
                $payments->update();
            }
        }
    }

    public function down(): void
    {
        if ($this->hasTable('invoice_payments')) {
            $payments = $this->table('invoice_payments');
            if ($payments->hasColumn('refund_id')) {
                $payments->dropForeignKey('refund_id');
                $payments->removeColumn('refund_id');
                $payments->update();
            }
        }

        if ($this->hasTable('invoices')) {
            $invoices = $this->table('invoices');
            if ($invoices->hasColumn('refund_id')) {
                $invoices->dropForeignKey('refund_id');
                $invoices->removeColumn('refund_id');
                $invoices->update();
            }
        }

        if ($this->hasTable('refund_observations')) {
            $this->table('refund_observations')->drop()->save();
        }

        if ($this->hasTable('refunds')) {
            $this->table('refunds')->drop()->save();
        }
    }
}

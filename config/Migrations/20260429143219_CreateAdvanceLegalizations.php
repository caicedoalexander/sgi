<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateAdvanceLegalizations extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('advance_legalizations')) {
            return;
        }

        $this->table('advance_legalizations')
            ->addColumn('advance_invoice_id', 'integer', ['null' => false])
            ->addColumn('status', 'string', ['limit' => 30, 'null' => false, 'default' => 'validacion'])
            ->addColumn('case_type', 'string', ['limit' => 20, 'null' => true, 'default' => null])
            ->addColumn('shortage_amount', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => true, 'default' => null])
            ->addColumn('surplus_amount', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => true, 'default' => null])
            ->addColumn('shortage_received_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('shortage_receipt_number', 'string', ['limit' => 100, 'null' => true, 'default' => null])
            ->addColumn('shortage_receipt_path', 'string', ['limit' => 500, 'null' => true, 'default' => null])
            ->addColumn('surplus_payment_id', 'integer', ['null' => true, 'default' => null])
            ->addColumn('legalized_at', 'datetime', ['null' => true, 'default' => null])
            // signed=true explícito para alinearse con users.id (audit MI-010).
            // Phinx defaults integer a signed=true, pero el flag explícito documenta
            // la consistencia con la tabla referenciada vía FK.
            ->addColumn('created_by', 'integer', ['null' => false, 'signed' => true])
            ->addColumn('updated_by', 'integer', ['null' => true, 'default' => null, 'signed' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['advance_invoice_id'], ['unique' => true])
            ->addIndex(['status'])
            ->addForeignKey('advance_invoice_id', 'invoices', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('surplus_payment_id', 'invoice_payments', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('updated_by', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('advance_legalizations')) {
            $this->table('advance_legalizations')->drop()->save();
        }
    }
}

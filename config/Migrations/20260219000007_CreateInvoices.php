<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateInvoices extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('invoices');

        // Fechas principales
        $table->addColumn('registration_date', 'date', [
            'null' => false,
        ]);
        $table->addColumn('issue_date', 'date', [
            'null' => false,
        ]);
        $table->addColumn('due_date', 'date', [
            'null' => false,
        ]);

        // Datos del documento
        $table->addColumn('document_type', 'string', [
            'limit' => 50,
            'null' => false,
        ]);
        $table->addColumn('purchase_order', 'string', [
            'limit' => 50,
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('provider_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('operation_center_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('detail', 'text', [
            'null' => false,
        ]);
        $table->addColumn('amount', 'decimal', [
            'precision' => 15,
            'scale' => 2,
            'null' => false,
        ]);
        $table->addColumn('expense_type_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('cost_center_id', 'integer', [
            'null' => false,
        ]);

        // Campos de Revisión
        $table->addColumn('confirmed_by', 'integer', [
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('area_approval', 'string', [
            'limit' => 20,
            'default' => 'Pendiente',
            'null' => false,
        ]);
        $table->addColumn('area_approval_date', 'date', [
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('dian_validation', 'string', [
            'limit' => 20,
            'default' => 'Pendiente',
            'null' => false,
        ]);

        // Campos de Contabilidad
        $table->addColumn('accrued', 'boolean', [
            'default' => false,
            'null' => false,
        ]);
        $table->addColumn('accrual_date', 'date', [
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('ready_for_payment', 'string', [
            'limit' => 50,
            'null' => true,
            'default' => null,
        ]);

        // Campos de Tesorería
        $table->addColumn('payment_status', 'string', [
            'limit' => 20,
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('payment_date', 'date', [
            'null' => true,
            'default' => null,
        ]);

        // Campos generales
        $table->addColumn('pipeline_status', 'string', [
            'limit' => 20,
            'default' => 'revision',
            'null' => false,
        ]);
        $table->addColumn('observations', 'text', [
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('registered_by', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('created', 'datetime', [
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('modified', 'datetime', [
            'null' => true,
            'default' => null,
        ]);

        // Foreign Keys
        $table->addForeignKey('provider_id', 'providers', 'id', [
            'delete' => 'RESTRICT',
            'update' => 'NO_ACTION',
        ]);
        $table->addForeignKey('operation_center_id', 'operation_centers', 'id', [
            'delete' => 'RESTRICT',
            'update' => 'NO_ACTION',
        ]);
        $table->addForeignKey('expense_type_id', 'expense_types', 'id', [
            'delete' => 'RESTRICT',
            'update' => 'NO_ACTION',
        ]);
        $table->addForeignKey('cost_center_id', 'cost_centers', 'id', [
            'delete' => 'RESTRICT',
            'update' => 'NO_ACTION',
        ]);
        $table->addForeignKey('confirmed_by', 'users', 'id', [
            'delete' => 'SET_NULL',
            'update' => 'NO_ACTION',
        ]);
        $table->addForeignKey('registered_by', 'users', 'id', [
            'delete' => 'RESTRICT',
            'update' => 'NO_ACTION',
        ]);

        $table->create();
    }
}

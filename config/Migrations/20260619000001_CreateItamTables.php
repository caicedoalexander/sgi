<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateItamTables extends BaseMigration
{
    public function up(): void
    {
        // asset_categories
        if (!$this->hasTable('asset_categories')) {
            $t = $this->table('asset_categories');
            $t->addColumn('code', 'string', ['limit' => 30, 'null' => false]);
            $t->addColumn('name', 'string', ['limit' => 100, 'null' => false]);
            $t->addColumn('description', 'text', ['null' => true, 'default' => null]);
            $t->addColumn('active', 'boolean', ['null' => false, 'default' => true]);
            $t->addColumn('created', 'datetime', ['null' => true, 'default' => null]);
            $t->addColumn('modified', 'datetime', ['null' => true, 'default' => null]);
            $t->addIndex(['code'], ['unique' => true]);
            $t->create();
        }

        // assets
        if (!$this->hasTable('assets')) {
            $t = $this->table('assets');
            $t->addColumn('code', 'string', ['limit' => 30, 'null' => false]);
            $t->addColumn('serial_number', 'string', ['limit' => 100, 'null' => true, 'default' => null]);
            $t->addColumn('asset_category_id', 'integer', ['null' => false, 'signed' => true]);
            $t->addColumn('brand', 'string', ['limit' => 100, 'null' => true, 'default' => null]);
            $t->addColumn('model', 'string', ['limit' => 100, 'null' => true, 'default' => null]);
            $t->addColumn('description', 'text', ['null' => true, 'default' => null]);
            $t->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'disponible']);
            $t->addColumn('responsible_employee_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('operation_center_id', 'integer', ['null' => false, 'signed' => true]);
            $t->addColumn('cost_center_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('acquisition_date', 'date', ['null' => true, 'default' => null]);
            $t->addColumn('observations', 'text', ['null' => true, 'default' => null]);
            $t->addColumn('created', 'datetime', ['null' => true, 'default' => null]);
            $t->addColumn('modified', 'datetime', ['null' => true, 'default' => null]);
            $t->addIndex(['code'], ['unique' => true]);
            $t->addIndex(['status']);
            $t->addIndex(['asset_category_id']);
            $t->addIndex(['responsible_employee_id']);
            $t->addIndex(['operation_center_id']);
            $t->addForeignKey('asset_category_id', 'asset_categories', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('responsible_employee_id', 'employees', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('operation_center_id', 'operation_centers', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('cost_center_id', 'cost_centers', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->create();
        }

        // asset_movements (log inmutable — solo created)
        if (!$this->hasTable('asset_movements')) {
            $t = $this->table('asset_movements');
            $t->addColumn('asset_id', 'integer', ['null' => false, 'signed' => true]);
            $t->addColumn('movement_type', 'string', ['limit' => 20, 'null' => false]);
            $t->addColumn('from_employee_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('to_employee_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('from_operation_center_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('to_operation_center_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('reason', 'text', ['null' => true, 'default' => null]);
            $t->addColumn('movement_date', 'datetime', ['null' => false]);
            $t->addColumn('acta_status', 'string', ['limit' => 20, 'null' => true, 'default' => null]);
            $t->addColumn('performed_by_user_id', 'integer', ['null' => false, 'signed' => true]);
            $t->addColumn('requested_by_phone', 'string', ['limit' => 30, 'null' => true, 'default' => null]);
            $t->addColumn('requested_by_employee_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('source', 'string', ['limit' => 10, 'null' => false, 'default' => 'web']);
            $t->addColumn('created', 'datetime', ['null' => true, 'default' => null]);
            $t->addIndex(['asset_id']);
            $t->addIndex(['movement_type']);
            $t->addIndex(['acta_status']);
            $t->addIndex(['movement_date']);
            $t->addForeignKey('asset_id', 'assets', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('from_employee_id', 'employees', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('to_employee_id', 'employees', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('from_operation_center_id', 'operation_centers', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('to_operation_center_id', 'operation_centers', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('performed_by_user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('requested_by_employee_id', 'employees', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->create();
        }

        // asset_documents
        if (!$this->hasTable('asset_documents')) {
            $t = $this->table('asset_documents');
            $t->addColumn('asset_id', 'integer', ['null' => false, 'signed' => true]);
            $t->addColumn('asset_movement_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('document_type', 'string', ['limit' => 30, 'null' => false]);
            $t->addColumn('name', 'string', ['limit' => 255, 'null' => false]);
            $t->addColumn('file_path', 'string', ['limit' => 255, 'null' => false]);
            $t->addColumn('file_size', 'integer', ['null' => true, 'default' => null]);
            $t->addColumn('mime_type', 'string', ['limit' => 100, 'null' => true, 'default' => null]);
            $t->addColumn('uploaded_by', 'integer', ['null' => false, 'signed' => true]);
            $t->addColumn('created', 'datetime', ['null' => true, 'default' => null]);
            $t->addColumn('modified', 'datetime', ['null' => true, 'default' => null]);
            $t->addIndex(['asset_id']);
            $t->addIndex(['asset_movement_id']);
            $t->addForeignKey('asset_id', 'assets', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE']);
            $t->addForeignKey('asset_movement_id', 'asset_movements', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE']);
            $t->addForeignKey('uploaded_by', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->create();
        }

        // consumables
        if (!$this->hasTable('consumables')) {
            $t = $this->table('consumables');
            $t->addColumn('reference', 'string', ['limit' => 50, 'null' => false]);
            $t->addColumn('description', 'string', ['limit' => 255, 'null' => false]);
            $t->addColumn('current_stock', 'integer', ['null' => false, 'default' => 0]);
            $t->addColumn('minimum_stock', 'integer', ['null' => false, 'default' => 0]);
            $t->addColumn('maximum_stock', 'integer', ['null' => true, 'default' => null]);
            $t->addColumn('operation_center_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('unit', 'string', ['limit' => 20, 'null' => true, 'default' => null]);
            $t->addColumn('created', 'datetime', ['null' => true, 'default' => null]);
            $t->addColumn('modified', 'datetime', ['null' => true, 'default' => null]);
            $t->addIndex(['reference'], ['unique' => true]);
            $t->addIndex(['operation_center_id']);
            $t->addForeignKey('operation_center_id', 'operation_centers', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->create();
        }

        // consumable_movements (log inmutable — solo created)
        if (!$this->hasTable('consumable_movements')) {
            $t = $this->table('consumable_movements');
            $t->addColumn('consumable_id', 'integer', ['null' => false, 'signed' => true]);
            $t->addColumn('movement_type', 'string', ['limit' => 20, 'null' => false]);
            $t->addColumn('quantity', 'integer', ['null' => false]);
            $t->addColumn('balance_after', 'integer', ['null' => false]);
            $t->addColumn('reason', 'text', ['null' => true, 'default' => null]);
            $t->addColumn('related_asset_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('movement_date', 'datetime', ['null' => false]);
            $t->addColumn('performed_by_user_id', 'integer', ['null' => false, 'signed' => true]);
            $t->addColumn('requested_by_phone', 'string', ['limit' => 30, 'null' => true, 'default' => null]);
            $t->addColumn('source', 'string', ['limit' => 10, 'null' => false, 'default' => 'web']);
            $t->addColumn('created', 'datetime', ['null' => true, 'default' => null]);
            $t->addIndex(['consumable_id']);
            $t->addIndex(['movement_type']);
            $t->addIndex(['movement_date']);
            $t->addForeignKey('consumable_id', 'consumables', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('related_asset_id', 'assets', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE']);
            $t->addForeignKey('performed_by_user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->create();
        }

        // asset_alerts
        if (!$this->hasTable('asset_alerts')) {
            $t = $this->table('asset_alerts');
            $t->addColumn('alert_type', 'string', ['limit' => 30, 'null' => false]);
            $t->addColumn('priority', 'string', ['limit' => 10, 'null' => false, 'default' => 'media']);
            $t->addColumn('asset_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('consumable_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('asset_movement_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('message', 'string', ['limit' => 255, 'null' => false]);
            $t->addColumn('status', 'string', ['limit' => 10, 'null' => false, 'default' => 'abierta']);
            $t->addColumn('notified_at', 'datetime', ['null' => true, 'default' => null]);
            $t->addColumn('resolved_at', 'datetime', ['null' => true, 'default' => null]);
            $t->addColumn('created', 'datetime', ['null' => true, 'default' => null]);
            $t->addColumn('modified', 'datetime', ['null' => true, 'default' => null]);
            $t->addIndex(['alert_type']);
            $t->addIndex(['status']);
            $t->addIndex(['priority']);
            $t->addIndex(['asset_id']);
            $t->addIndex(['consumable_id']);
            $t->addForeignKey('asset_id', 'assets', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE']);
            $t->addForeignKey('consumable_id', 'consumables', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE']);
            $t->addForeignKey('asset_movement_id', 'asset_movements', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE']);
            $t->create();
        }
    }

    public function down(): void
    {
        foreach (
            [
            'asset_alerts',
            'consumable_movements',
            'consumables',
            'asset_documents',
            'asset_movements',
            'assets',
            'asset_categories',
            ] as $table
        ) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }
    }
}

<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddEquivalentDocumentToInvoices extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('invoices');

        $table->addColumn('is_equivalent_document', 'boolean', [
            'default' => false,
            'null' => false,
            'after' => 'document_type',
        ]);
        $table->addColumn('equivalent_holder_type', 'string', [
            'limit' => 20,
            'null' => true,
            'default' => null,
            'after' => 'is_equivalent_document',
        ]);
        $table->addColumn('employee_id', 'integer', [
            'null' => true,
            'default' => null,
            'signed' => true,
            'after' => 'provider_id',
        ]);
        $table->addColumn('manual_document_number', 'string', [
            'limit' => 30,
            'null' => true,
            'default' => null,
            'after' => 'employee_id',
        ]);
        $table->update();

        // Make provider_id nullable
        $this->execute('ALTER TABLE invoices MODIFY provider_id INT NULL DEFAULT NULL');

        // Add FK for employee_id
        $table->addForeignKey('employee_id', 'employees', 'id', [
            'delete' => 'SET_NULL',
            'update' => 'NO_ACTION',
        ]);
        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('invoices');
        if ($table->hasForeignKey('employee_id')) {
            $table->dropForeignKey('employee_id');
            $table->update();
        }
        $table->removeColumn('is_equivalent_document');
        $table->removeColumn('equivalent_holder_type');
        $table->removeColumn('employee_id');
        $table->removeColumn('manual_document_number');
        $table->update();

        // Restore provider_id as NOT NULL
        $this->execute('ALTER TABLE invoices MODIFY provider_id INT NOT NULL');
    }
}

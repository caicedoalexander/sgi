<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateLeaveTypeContractTemplates extends BaseMigration
{
    public function up(): void
    {
        $this->table('leave_type_contract_templates')
            ->addColumn('leave_type_id', 'integer', ['signed' => true, 'null' => false])
            ->addColumn('contract_type', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('temporary_organization_id', 'integer', ['signed' => true, 'null' => true, 'default' => null])
            ->addColumn('leave_document_template_id', 'integer', ['signed' => true, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['leave_type_id', 'contract_type', 'temporary_organization_id'], ['unique' => true, 'name' => 'uq_ltype_contract_org'])
            ->addForeignKey('leave_type_id', 'leave_types', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('temporary_organization_id', 'temporary_organizations', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->addForeignKey('leave_document_template_id', 'leave_document_templates', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        $this->table('leave_type_contract_templates')->drop()->save();
    }
}

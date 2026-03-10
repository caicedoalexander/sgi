<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateNoveltyTypeContractTemplates extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('novelty_type_contract_templates')) {
            $this->table('novelty_type_contract_templates')
                ->addColumn('novelty_type_id', 'integer', ['null' => false])
                ->addColumn('contract_type', 'string', ['limit' => 50, 'null' => false])
                ->addColumn('temporary_organization_id', 'integer', ['null' => true, 'default' => null])
                ->addColumn('leave_document_template_id', 'integer', ['null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['novelty_type_id', 'contract_type', 'temporary_organization_id'], ['unique' => true])
                ->addForeignKey('novelty_type_id', 'novelty_types', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('temporary_organization_id', 'temporary_organizations', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
                ->addForeignKey('leave_document_template_id', 'leave_document_templates', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('novelty_type_contract_templates')) {
            $this->table('novelty_type_contract_templates')->drop()->save();
        }
    }
}

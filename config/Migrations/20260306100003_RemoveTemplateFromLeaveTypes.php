<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class RemoveTemplateFromLeaveTypes extends BaseMigration
{
    public function up(): void
    {
        $this->table('leave_types')
            ->dropForeignKey('leave_document_template_id')
            ->save();

        $this->table('leave_types')
            ->removeColumn('leave_document_template_id')
            ->update();
    }

    public function down(): void
    {
        $this->table('leave_types')
            ->addColumn('leave_document_template_id', 'integer', [
                'signed' => true,
                'null' => true,
                'default' => null,
                'after' => 'paid',
            ])
            ->addForeignKey('leave_document_template_id', 'leave_document_templates', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();
    }
}

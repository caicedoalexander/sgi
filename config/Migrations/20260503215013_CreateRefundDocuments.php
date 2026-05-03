<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateRefundDocuments extends BaseMigration
{
    public function change(): void
    {
        if ($this->hasTable('refund_documents')) {
            return;
        }

        $table = $this->table('refund_documents');

        $table->addColumn('refund_id', 'integer', [
            'null' => false,
            'signed' => true,
        ]);
        $table->addColumn('document_type', 'string', [
            'limit' => 100,
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('file_path', 'string', [
            'limit' => 255,
            'null' => false,
        ]);
        $table->addColumn('file_name', 'string', [
            'limit' => 255,
            'null' => false,
        ]);
        $table->addColumn('file_size', 'integer', [
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('mime_type', 'string', [
            'limit' => 100,
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('uploaded_by', 'integer', [
            'null' => true,
            'default' => null,
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

        $table->addIndex(['refund_id']);

        $table->addForeignKey('refund_id', 'refunds', 'id', [
            'delete' => 'CASCADE',
            'update' => 'CASCADE',
        ]);
        $table->addForeignKey('uploaded_by', 'users', 'id', [
            'delete' => 'SET_NULL',
            'update' => 'CASCADE',
        ]);

        $table->create();
    }
}

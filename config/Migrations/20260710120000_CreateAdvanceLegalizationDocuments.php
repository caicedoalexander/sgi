<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateAdvanceLegalizationDocuments extends BaseMigration
{
    public function change(): void
    {
        if ($this->hasTable('advance_legalization_documents')) {
            return;
        }

        $table = $this->table('advance_legalization_documents');

        $table->addColumn('legalization_id', 'integer', [
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

        $table->addIndex(['legalization_id']);

        $table->addForeignKey('legalization_id', 'advance_legalizations', 'id', [
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

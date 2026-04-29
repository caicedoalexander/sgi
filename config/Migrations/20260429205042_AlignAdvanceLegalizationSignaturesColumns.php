<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AlignAdvanceLegalizationSignaturesColumns extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('advance_legalization_signatures');

        if ($table->hasColumn('document_path') && !$table->hasColumn('file_path')) {
            $table->renameColumn('document_path', 'file_path')->update();
        }
        $table = $this->table('advance_legalization_signatures');
        if ($table->hasColumn('document_name') && !$table->hasColumn('file_name')) {
            $table->renameColumn('document_name', 'file_name')->update();
        }

        $table = $this->table('advance_legalization_signatures');
        if (!$table->hasColumn('file_size')) {
            $table->addColumn('file_size', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'file_name',
            ])->update();
        }
        $table = $this->table('advance_legalization_signatures');
        if (!$table->hasColumn('mime_type')) {
            $table->addColumn('mime_type', 'string', [
                'limit' => 100,
                'null' => true,
                'default' => null,
                'after' => 'file_size',
            ])->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('advance_legalization_signatures');
        if ($table->hasColumn('mime_type')) {
            $table->removeColumn('mime_type')->update();
        }
        $table = $this->table('advance_legalization_signatures');
        if ($table->hasColumn('file_size')) {
            $table->removeColumn('file_size')->update();
        }
        $table = $this->table('advance_legalization_signatures');
        if ($table->hasColumn('file_path') && !$table->hasColumn('document_path')) {
            $table->renameColumn('file_path', 'document_path')->update();
        }
        $table = $this->table('advance_legalization_signatures');
        if ($table->hasColumn('file_name') && !$table->hasColumn('document_name')) {
            $table->renameColumn('file_name', 'document_name')->update();
        }
    }
}

<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class DropShortageReceiptPathFromAdvanceLegalizations extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('advance_legalizations');
        if ($table->hasColumn('shortage_receipt_path')) {
            $table->removeColumn('shortage_receipt_path')->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('advance_legalizations');
        if (!$table->hasColumn('shortage_receipt_path')) {
            $table->addColumn('shortage_receipt_path', 'string', [
                'limit' => 500,
                'null' => true,
                'default' => null,
            ])->update();
        }
    }
}

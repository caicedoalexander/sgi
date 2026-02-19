<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class MakeCostCenterNullableOnInvoices extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('invoices');
        $table->changeColumn('cost_center_id', 'integer', [
            'null' => true,
            'default' => null,
        ]);
        $table->update();
    }
}

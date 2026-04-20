<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddStatusEnumToInvoicePayments extends BaseMigration
{
    public function up(): void
    {
        $this->table('invoice_payments')
            ->addColumn('status', 'enum', [
                'values' => ['pending', 'authorized', 'rejected'],
                'default' => 'pending',
                'null' => false,
                'after' => 'authorized',
            ])
            ->update();

        $this->execute("UPDATE invoice_payments SET status = 'authorized' WHERE authorized = 1");
        $this->execute("UPDATE invoice_payments SET status = 'pending' WHERE authorized = 0");
    }

    public function down(): void
    {
        $this->table('invoice_payments')->removeColumn('status')->update();
    }
}

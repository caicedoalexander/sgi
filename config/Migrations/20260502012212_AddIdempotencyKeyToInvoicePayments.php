<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddIdempotencyKeyToInvoicePayments extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('invoice_payments');

        if (!$table->hasColumn('idempotency_key')) {
            $table->addColumn('idempotency_key', 'string', [
                'limit' => 64,
                'null' => true,
                'default' => null,
            ]);
        }

        if (!$table->hasIndex(['idempotency_key'])) {
            $table->addIndex(['idempotency_key'], [
                'unique' => true,
                'name' => 'uq_invoice_payments_idempotency_key',
            ]);
        }

        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('invoice_payments');

        if ($table->hasIndex(['idempotency_key'])) {
            $table->removeIndexByName('uq_invoice_payments_idempotency_key');
        }

        if ($table->hasColumn('idempotency_key')) {
            $table->removeColumn('idempotency_key');
        }

        $table->update();
    }
}

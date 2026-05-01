<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddRateLimitBucketsTable extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('rate_limit_buckets')) {
            return;
        }

        $table = $this->table('rate_limit_buckets', ['signed' => false]);
        $table
            ->addColumn('bucket_key', 'string', [
                'limit' => 64,
                'null' => false,
            ])
            ->addColumn('window_start', 'datetime', [
                'null' => false,
            ])
            ->addColumn('count', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'null' => false,
            ])
            ->addIndex(['bucket_key'], ['unique' => true])
            ->addIndex(['window_start'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('rate_limit_buckets')) {
            $this->table('rate_limit_buckets')->drop()->save();
        }
    }
}

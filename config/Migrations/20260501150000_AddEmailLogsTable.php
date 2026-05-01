<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddEmailLogsTable extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('email_logs')) {
            return;
        }

        $table = $this->table('email_logs', ['signed' => false]);
        $table
            ->addColumn('event_type', 'string', [
                'limit' => 50,
                'null' => false,
            ])
            ->addColumn('entity_type', 'string', [
                'limit' => 50,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('entity_id', 'biginteger', [
                'signed' => false,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('to_email', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('subject', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('template', 'string', [
                'limit' => 100,
                'null' => false,
            ])
            ->addColumn('payload', 'json', [
                'null' => false,
            ])
            ->addColumn('status', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => 'pending',
            ])
            ->addColumn('attempts', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
            ])
            ->addColumn('last_error', 'text', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('last_attempt_at', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('sent_at', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('created_by', 'biginteger', [
                'signed' => false,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'null' => false,
            ])
            ->addIndex(['entity_type', 'entity_id'], ['name' => 'idx_entity'])
            ->addIndex(['status', 'created'], ['name' => 'idx_status_created'])
            ->addIndex(['event_type'], ['name' => 'idx_event_type'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('email_logs')) {
            $this->table('email_logs')->drop()->save();
        }
    }
}

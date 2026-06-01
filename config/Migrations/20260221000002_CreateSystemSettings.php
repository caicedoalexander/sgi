<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateSystemSettings extends BaseMigration
{
    public function up(): void
    {
        $this->table('system_settings')
            ->addColumn('setting_key', 'string', [
                'limit' => 100,
                'null' => false,
            ])
            ->addColumn('setting_value', 'text', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('setting_group', 'string', [
                'limit' => 50,
                'null' => false,
                'default' => 'general',
            ])
            ->addColumn('created', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('modified', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->addIndex(['setting_key'], ['unique' => true, 'name' => 'uq_system_settings_key'])
            ->create();
    }

    public function down(): void
    {
        $this->table('system_settings')->drop()->save();
    }
}

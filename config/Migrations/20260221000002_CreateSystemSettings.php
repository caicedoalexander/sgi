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

        // Seed SMTP settings
        $smtpSettings = [
            ['setting_key' => 'smtp_host', 'setting_value' => null, 'setting_group' => 'smtp'],
            ['setting_key' => 'smtp_port', 'setting_value' => '587', 'setting_group' => 'smtp'],
            ['setting_key' => 'smtp_username', 'setting_value' => null, 'setting_group' => 'smtp'],
            ['setting_key' => 'smtp_password', 'setting_value' => null, 'setting_group' => 'smtp'],
            ['setting_key' => 'smtp_encryption', 'setting_value' => 'tls', 'setting_group' => 'smtp'],
            ['setting_key' => 'smtp_from_email', 'setting_value' => null, 'setting_group' => 'smtp'],
            ['setting_key' => 'smtp_from_name', 'setting_value' => 'SGI', 'setting_group' => 'smtp'],
        ];

        $table = $this->table('system_settings');
        foreach ($smtpSettings as $setting) {
            $setting['created'] = date('Y-m-d H:i:s');
            $setting['modified'] = date('Y-m-d H:i:s');
            $table->insert($setting);
        }
        $table->saveData();
    }

    public function down(): void
    {
        $this->table('system_settings')->drop()->save();
    }
}

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

        // Crea las keys de configuración con su identificador, SIN valor por
        // defecto (todas en null). Los valores los define el usuario desde la UI.
        $keys = [
            'smtp_host',
            'smtp_port',
            'smtp_username',
            'smtp_password',
            'smtp_encryption',
            'smtp_from_email',
            'smtp_from_name',
        ];

        $table = $this->table('system_settings');
        $now = date('Y-m-d H:i:s');
        foreach ($keys as $key) {
            $table->insert([
                'setting_key' => $key,
                'setting_value' => null,
                'setting_group' => 'smtp',
                'created' => $now,
                'modified' => $now,
            ]);
        }
        $table->saveData();
    }

    public function down(): void
    {
        $this->table('system_settings')->drop()->save();
    }
}

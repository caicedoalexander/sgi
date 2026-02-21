<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

class SystemSettingsService
{
    private array $cache = [];

    public function get(string $key): ?string
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $table = TableRegistry::getTableLocator()->get('SystemSettings');
        $setting = $table->find()
            ->where(['setting_key' => $key])
            ->first();

        $value = $setting?->setting_value;
        $this->cache[$key] = $value;

        return $value;
    }

    public function set(string $key, ?string $value): bool
    {
        $table = TableRegistry::getTableLocator()->get('SystemSettings');
        $setting = $table->find()
            ->where(['setting_key' => $key])
            ->first();

        if ($setting) {
            $setting->setting_value = $value;
        } else {
            $setting = $table->newEntity([
                'setting_key' => $key,
                'setting_value' => $value,
                'setting_group' => 'general',
            ]);
        }

        unset($this->cache[$key]);

        return (bool)$table->save($setting);
    }

    public function getGroup(string $group): array
    {
        $table = TableRegistry::getTableLocator()->get('SystemSettings');
        $settings = $table->find()
            ->where(['setting_group' => $group])
            ->all();

        $result = [];
        foreach ($settings as $setting) {
            $result[$setting->setting_key] = $setting->setting_value;
            $this->cache[$setting->setting_key] = $setting->setting_value;
        }

        return $result;
    }

    public function setGroup(string $group, array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }
}

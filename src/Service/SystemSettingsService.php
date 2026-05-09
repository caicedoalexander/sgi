<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Cake\Utility\Security;

class SystemSettingsService
{
    /**
     * Claves cuyo valor se cifra en reposo. La lista es la fuente de verdad —
     * agregar una clave aquí basta para activar el cifrado en su próxima escritura.
     */
    private const ENCRYPTED_KEYS = [
        'smtp_password',
        'notifications_api_key',
    ];

    /**
     * Marcador que precede a un valor cifrado almacenado en BD. Permite distinguir
     * valores cifrados de valores legacy en texto plano y deja la puerta abierta
     * a versionar el formato del cipher en el futuro (enc:v2:..., etc.).
     */
    private const CIPHER_PREFIX = 'enc:v1:';

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

    public function set(string $key, ?string $value, string $group = 'general'): bool
    {
        $table = TableRegistry::getTableLocator()->get('SystemSettings');
        $setting = $table->find()
            ->where(['setting_key' => $key])
            ->first();

        $persistedValue = $value;
        if ($value !== null && $value !== '' && in_array($key, self::ENCRYPTED_KEYS, true)) {
            $persistedValue = $this->_encrypt($value);
        }

        if ($setting) {
            $setting->setting_value = $persistedValue;
        } else {
            $setting = $table->newEntity([
                'setting_key' => $key,
                'setting_value' => $persistedValue,
                'setting_group' => $group,
            ]);
        }

        $saved = (bool)$table->save($setting);
        if ($saved) {
            $this->cache[$key] = $value;
        } else {
            unset($this->cache[$key]);
        }

        return $saved;
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

    /**
     * Cifra un valor en claro y lo formatea para almacenamiento en BD.
     *
     * @param string $plain Valor en claro a cifrar.
     * @return string Valor cifrado con prefijo `enc:v1:` y cuerpo en base64.
     */
    private function _encrypt(string $plain): string
    {
        $cipher = Security::encrypt($plain, Security::getSalt());

        return self::CIPHER_PREFIX . base64_encode($cipher);
    }

    /**
     * Descifra un valor leído de BD para una clave de ENCRYPTED_KEYS.
     * Cualquier valor sin el prefijo `enc:v1:` o con cipher inválido se
     * considera una inconsistencia: se loguea el error sin filtrar el cipher
     * y se retorna `null` para que el consumidor lo trate como "credencial
     * no configurada". No hay fallback a texto plano (proyecto en dev).
     *
     * @param string $stored Valor leído de la columna `setting_value`.
     * @param string $key    Nombre de la clave (solo para logging).
     * @return string|null   Valor en claro, o `null` si el descifrado falla.
     */
    private function _decrypt(string $stored, string $key): ?string
    {
        if (!str_starts_with($stored, self::CIPHER_PREFIX)) {
            Log::error('SystemSettings decryption failed (unprefixed) for key: ' . $key);

            return null;
        }

        $cipher = base64_decode(substr($stored, strlen(self::CIPHER_PREFIX)), true);
        if ($cipher === false) {
            Log::error('SystemSettings decryption failed (base64) for key: ' . $key);

            return null;
        }

        $plain = Security::decrypt($cipher, Security::getSalt());
        if ($plain === null) {
            Log::error('SystemSettings decryption failed (security) for key: ' . $key);

            return null;
        }

        return $plain;
    }
}

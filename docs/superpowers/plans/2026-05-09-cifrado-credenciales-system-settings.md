# Cifrado en reposo de credenciales en `system_settings` — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cifrar en reposo los valores sensibles de `system_settings` (`smtp_password`, `notifications_api_key`) sin modificar la API pública de `SystemSettingsService` ni la UI ni los consumidores.

**Architecture:** Toda la lógica de cifrado vive encapsulada en `SystemSettingsService`. Se cifran solo las claves listadas en `ENCRYPTED_KEYS`. Algoritmo: `Cake\Utility\Security::encrypt()` (AES-256-CBC + HMAC-SHA256) con la llave provista por `Security::getSalt()` (alimentada por `SECURITY_SALT` en `.env`). Marcador en BD: prefijo `enc:v1:<base64>`.

**Tech Stack:** PHP 8.4, CakePHP 5.3, `Cake\Utility\Security`, `Cake\Log\Log`. **Sin tests automatizados** (per `CLAUDE.md` → "Testing Policy"); validación 100 % manual.

**Spec de referencia:** `docs/superpowers/specs/2026-05-09-cifrado-credenciales-system-settings-design.md`

**Política de testing del proyecto:** este proyecto NO usa tests automatizados. No agregar nada en `tests/`. La validación se hace levantando `php bin/cake server` y ejercitando los endpoints en el navegador y con consultas SQL directas. Los pasos de "Run test" del template de writing-plans se sustituyen por validación manual.

---

## Resumen de archivos

| Archivo | Acción | Descripción |
|---------|--------|-------------|
| `src/Service/SystemSettingsService.php` | Modify | Único archivo con cambios. ~30 LOC nuevas: 2 constantes, 2 métodos privados (`_encrypt`, `_decrypt`), modificación de `set()`, `get()`, `getGroup()`. |

**No se modifican:** `SystemSettingsController.php`, `CakeMailerAdapter.php`, `N8nService.php`, ni ningún consumidor. **Sin migraciones de BD.** **Sin templates nuevos.**

---

## Task 1: Agregar constantes y método privado `_encrypt()`

**Files:**
- Modify: `src/Service/SystemSettingsService.php`

Esta tarea introduce las piezas de encriptación pero **aún no las cablea** — el comportamiento observable de la clase no cambia tras esta tarea. Esto permite revisar el helper aislado.

- [ ] **Step 1: Agregar imports al top del archivo**

Reemplazar el bloque de `use` actual:

```php
use Cake\ORM\TableRegistry;
```

Por:

```php
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Cake\Utility\Security;
```

- [ ] **Step 2: Agregar constantes `ENCRYPTED_KEYS` y `CIPHER_PREFIX` al inicio de la clase**

Insertar **después** de `class SystemSettingsService {` y **antes** de `private array $cache = [];`:

```php
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

```

- [ ] **Step 3: Agregar método privado `_encrypt()` al final de la clase**

Insertar **antes** del último `}` de la clase:

```php

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
```

- [ ] **Step 4: Agregar método privado `_decrypt()` al final de la clase**

Insertar inmediatamente después de `_encrypt()`:

```php

    /**
     * Descifra un valor leído de BD. Si el valor no tiene el prefijo `enc:v1:`,
     * se devuelve tal cual (compatibilidad con valores legacy en texto plano).
     * Si el descifrado falla, se loguea el error sin filtrar el cipher y se
     * retorna `null` para que el consumidor lo trate como "credencial no
     * configurada".
     *
     * @param string $stored Valor leído de la columna `setting_value`.
     * @param string $key    Nombre de la clave (solo para logging).
     * @return string|null   Valor en claro, valor legacy tal cual, o `null` si falla.
     */
    private function _decrypt(string $stored, string $key): ?string
    {
        if (!str_starts_with($stored, self::CIPHER_PREFIX)) {
            return $stored;
        }

        $cipher = base64_decode(substr($stored, strlen(self::CIPHER_PREFIX)), true);
        if ($cipher === false) {
            Log::error('SystemSettings decryption failed (base64) for key: ' . $key);

            return null;
        }

        $plain = Security::decrypt($cipher, Security::getSalt());
        if ($plain === null || $plain === false) {
            Log::error('SystemSettings decryption failed (security) for key: ' . $key);

            return null;
        }

        return $plain;
    }
```

- [ ] **Step 5: Validar sintaxis con linter**

Ejecutar:

```bash
composer cs-check
```

Esperado: sin errores nuevos en `src/Service/SystemSettingsService.php`. Si aparecen issues de estilo, correr `composer cs-fix` y re-verificar.

- [ ] **Step 6: Commit**

```bash
git add src/Service/SystemSettingsService.php
git commit -m "feat(settings): agregar helpers de cifrado a SystemSettingsService

Introduce ENCRYPTED_KEYS, CIPHER_PREFIX y los métodos privados _encrypt/_decrypt
basados en Cake\Utility\Security. Aún no se cablean a set/get; sin cambio de
comportamiento observable."
```

---

## Task 2: Cablear cifrado en `set()`

**Files:**
- Modify: `src/Service/SystemSettingsService.php` (método `set`)

- [ ] **Step 1: Reemplazar el cuerpo del método `set()`**

Reemplazar el método completo `public function set(string $key, ?string $value, string $group = 'general'): bool` por:

```php
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

        unset($this->cache[$key]);

        return (bool)$table->save($setting);
    }
```

**Notas:**
- `$value` (en claro) se conserva por si necesitamos repoblar el cache; `$persistedValue` es lo que va a BD.
- No se cifran `null` ni `''` para mantener idempotencia con la lógica del controller (que ya hace `continue` si la pass viene vacía).

- [ ] **Step 2: Validar sintaxis**

Ejecutar:

```bash
composer cs-check
```

Esperado: sin errores nuevos.

- [ ] **Step 3: Smoke test manual de escritura**

Levantar el servidor y probar la escritura cifrada antes de tocar la lectura:

```bash
php bin/cake server
```

En el navegador:
1. Loguearse como admin (`admin` / `Admin2024*`).
2. Ir a `/system-settings`.
3. En "Configuración SMTP" cargar `smtp_password` con un valor de prueba: `test-password-123`.
4. Click en "Guardar".

En la base de datos (cliente MySQL/MariaDB):

```sql
SELECT setting_key, setting_value FROM system_settings WHERE setting_key = 'smtp_password';
```

Esperado: `setting_value` empieza con `enc:v1:` seguido de una cadena base64. **NO** debe aparecer `test-password-123` en claro.

⚠️ **Atención:** la lectura aún no descifra, por lo que la siguiente carga del formulario mostrará el campo password vacío (comportamiento de la UI: nunca repinta el password). Esto es esperado y se valida fin a fin en la Task 4.

- [ ] **Step 4: Commit**

```bash
git add src/Service/SystemSettingsService.php
git commit -m "feat(settings): cifrar valores sensibles al persistir

Modifica SystemSettingsService::set() para aplicar _encrypt() cuando la clave
está en ENCRYPTED_KEYS y el valor no es null/vacío. La lectura todavía no
descifra; los consumidores recibirán el valor cifrado hasta la próxima tarea."
```

---

## Task 3: Cablear descifrado en `get()` y `getGroup()`

**Files:**
- Modify: `src/Service/SystemSettingsService.php` (métodos `get` y `getGroup`)

Esta tarea cierra el ciclo: tras esta, los consumidores reciben los valores en claro.

- [ ] **Step 1: Reemplazar el cuerpo del método `get()`**

Reemplazar el método completo `public function get(string $key): ?string` por:

```php
    public function get(string $key): ?string
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $table = TableRegistry::getTableLocator()->get('SystemSettings');
        $setting = $table->find()
            ->where(['setting_key' => $key])
            ->first();

        $value = $setting?->setting_value;

        if ($value !== null && $value !== '' && in_array($key, self::ENCRYPTED_KEYS, true)) {
            $value = $this->_decrypt($value, $key);
        }

        $this->cache[$key] = $value;

        return $value;
    }
```

**Cambios respecto del original:**
- `isset($this->cache[$key])` → `array_key_exists($key, $this->cache)` para tratar correctamente `null` cacheado (antes hacía un re-lookup innecesario cuando el valor en cache era `null`).
- Bloque de descifrado antes de cachear.

- [ ] **Step 2: Reemplazar el cuerpo del método `getGroup()`**

Reemplazar el método completo `public function getGroup(string $group): array` por:

```php
    public function getGroup(string $group): array
    {
        $table = TableRegistry::getTableLocator()->get('SystemSettings');
        $settings = $table->find()
            ->where(['setting_group' => $group])
            ->all();

        $result = [];
        foreach ($settings as $setting) {
            $value = $setting->setting_value;

            if ($value !== null && $value !== '' && in_array($setting->setting_key, self::ENCRYPTED_KEYS, true)) {
                $value = $this->_decrypt($value, $setting->setting_key);
            }

            $result[$setting->setting_key] = $value;
            $this->cache[$setting->setting_key] = $value;
        }

        return $result;
    }
```

- [ ] **Step 3: Validar sintaxis**

Ejecutar:

```bash
composer cs-check
```

Esperado: sin errores nuevos.

- [ ] **Step 4: Smoke test rápido de lectura**

```bash
php bin/cake server
```

En la BD (asumiendo que en Task 2 dejaste `smtp_password` cifrado con `test-password-123`):

```sql
SELECT setting_value FROM system_settings WHERE setting_key = 'smtp_password';
```

Confirmar que sigue empezando con `enc:v1:`.

En el navegador, ir a `/system-settings` (debe cargar sin errores). En `logs/error.log` no debe aparecer ningún `SystemSettings decryption failed`. La validación funcional completa (envío SMTP, API key) se hace en la Task 4.

- [ ] **Step 5: Commit**

```bash
git add src/Service/SystemSettingsService.php
git commit -m "feat(settings): descifrar valores sensibles al leer

Modifica SystemSettingsService::get() y getGroup() para aplicar _decrypt() en
claves de ENCRYPTED_KEYS. El cache almacena el valor en claro. Cierra el
ciclo: los consumidores ahora reciben el valor descifrado de forma transparente."
```

---

## Task 4: Validación manual fin a fin

**Files:** ninguno (solo verificación operativa).

Estos pasos corresponden uno-a-uno a la sección "Validación manual" del spec. Ejecutar **en orden**.

- [ ] **Step 1: Reset del estado de prueba**

Limpiar el valor de prueba dejado por las tareas anteriores:

```sql
UPDATE system_settings SET setting_value = NULL WHERE setting_key IN ('smtp_password', 'notifications_api_key');
```

- [ ] **Step 2: Verificar lectura sin valores cifrados (estado limpio)**

```bash
php bin/cake server
```

1. Loguearse como admin (`admin` / `Admin2024*`).
2. Navegar a `/system-settings`.
3. **Esperado:** la página carga sin errores, el campo "SMTP Password" se muestra vacío, no hay nada nuevo en `logs/error.log`.

- [ ] **Step 3: Verificar escritura cifrada con un valor real**

1. En `/system-settings` cargar `smtp_password` con la contraseña SMTP real del proyecto.
2. Completar también `smtp_host`, `smtp_port`, `smtp_username`, `smtp_encryption`, `smtp_from_email`.
3. Guardar.
4. En BD ejecutar:

```sql
SELECT setting_value FROM system_settings WHERE setting_key = 'smtp_password';
```

**Esperado:** el valor empieza con `enc:v1:` y NO se ve la contraseña en claro.

- [ ] **Step 4: Verificar lectura cifrada en consumidor real (envío SMTP)**

1. En `/system-settings` click en "Probar conexión SMTP" (`POST /system-settings/test-smtp`).
2. **Esperado:** mensaje flash de éxito y el correo de prueba llega a la bandeja configurada en `smtp_from_email`. Esto demuestra que `CakeMailerAdapter` recibe la contraseña descifrada correctamente.
3. Si falla con error de autenticación, las credenciales SMTP son incorrectas (no es bug del cifrado). Volver a Step 3 con valores correctos.

- [ ] **Step 5: Verificar regeneración cifrada de la API key**

1. En `/system-settings` click en "Regenerar API key" (`POST /system-settings/regenerate-api-key`).
2. En BD:

```sql
SELECT setting_value FROM system_settings WHERE setting_key = 'notifications_api_key';
```

**Esperado:** el valor empieza con `enc:v1:`. NO debe ser una cadena hexadecimal de 64 caracteres en claro.

3. (Opcional, si tenés acceso a n8n o `curl`) Disparar un endpoint de la API de notificaciones que valide la key con el header correspondiente. **Esperado:** respuesta 200. Esto confirma que la key se desencripta bien al validar.

- [ ] **Step 6: Verificar que valores no sensibles siguen en claro**

```sql
SELECT setting_key, setting_value FROM system_settings
 WHERE setting_group = 'smtp' AND setting_key != 'smtp_password';
```

**Esperado:** `smtp_host`, `smtp_port`, `smtp_username`, `smtp_encryption`, `smtp_from_email`, `smtp_from_name` aparecen en texto plano y legibles. Ningún prefijo `enc:v1:`.

- [ ] **Step 7: Verificar defensa contra valores legacy (Caso 1 del spec)**

Simular un valor en texto plano que se haya quedado en BD pre-deploy:

```sql
UPDATE system_settings SET setting_value = 'PlaintextLegacy' WHERE setting_key = 'smtp_password';
```

1. Recargar `/system-settings`.
2. **Esperado:** la página carga sin crashear, el campo password está vacío en el form (la UI nunca repinta el password), y `logs/error.log` NO contiene ningún `SystemSettings decryption failed`.
3. Reescribir la pass real desde la UI y guardar.
4. En BD verificar que el valor vuelve a tener prefijo `enc:v1:`:

```sql
SELECT setting_value FROM system_settings WHERE setting_key = 'smtp_password';
```

- [ ] **Step 8: Verificar defensa contra cipher corrupto (Caso 2 del spec)**

```sql
UPDATE system_settings SET setting_value = 'enc:v1:notbase64===corrupted' WHERE setting_key = 'smtp_password';
```

1. Click en "Probar conexión SMTP".
2. **Esperado:** la operación falla (porque la pass se entrega como `null` al mailer y SMTP rechaza el login), y en `logs/error.log` aparece una línea del tipo:

   ```
   SystemSettings decryption failed (security) for key: smtp_password
   ```

   o

   ```
   SystemSettings decryption failed (base64) for key: smtp_password
   ```

3. Reescribir la pass real desde la UI para dejar el sistema en estado funcional.

- [ ] **Step 9: Validación final con un round trip de la API key**

```bash
php bin/cake server
```

Repetir Step 5: regenerar API key, verificar que en BD está cifrada, y (si es posible) validar el endpoint con el header. Esto sirve como confirmación final de que el ciclo set→get funciona en una única request real.

- [ ] **Step 10: Marcar la validación como completa**

Si todos los steps anteriores pasaron, no hay commit en esta tarea (no se modificó código). Anotar en notas operativas que el cifrado quedó verificado en la fecha de hoy.

---

## Self-Review

**1. Cobertura del spec:**

| Sección del spec | Tarea(s) que la implementan |
|------------------|------------------------------|
| Arquitectura — encapsulación en service | Task 1, 2, 3 |
| Constante `ENCRYPTED_KEYS` | Task 1, Step 2 |
| Constante `CIPHER_PREFIX` | Task 1, Step 2 |
| `_encrypt()` | Task 1, Step 3 |
| `_decrypt()` con manejo de legacy y errores | Task 1, Step 4 |
| `set()` cifra antes de persistir | Task 2, Step 1 |
| `get()` descifra después de leer | Task 3, Step 1 |
| `getGroup()` descifra por cada clave sensible | Task 3, Step 2 |
| Cache con valor en claro | Task 3, Step 1 y 2 (asignan a `$this->cache` el valor desencriptado) |
| Caso 1 — valor legacy sin prefijo | Cubierto por `_decrypt()` (Task 1, Step 4) y validado en Task 4, Step 7 |
| Caso 2 — cipher corrupto | Cubierto por `_decrypt()` (Task 1, Step 4) y validado en Task 4, Step 8 |
| Caso 3 — valor null/vacío | Cubierto por guardas en `set()`, `get()`, `getGroup()` (Task 2 y 3) |
| Caso 4 — salt inválido | No requiere código (CakePHP falla en boot); documentado en spec |
| Validación manual de los 6 casos del spec | Task 4, Steps 2-7 (más Step 8 para Caso 2 explícito y Step 9 para round trip de API key) |

✅ Sin gaps.

**2. Placeholders:** ningún `TBD`, `TODO`, "implement later", "add appropriate error handling". Todo el código mostrado es completo y copy-paste ready.

**3. Consistencia de tipos y nombres:**
- `ENCRYPTED_KEYS` se referencia con el mismo nombre en `set`, `get`, `getGroup` ✓
- `CIPHER_PREFIX` se referencia con el mismo nombre en `_encrypt` y `_decrypt` ✓
- `_encrypt` recibe `string` y devuelve `string` (siempre cifra) ✓
- `_decrypt` recibe `string $stored, string $key` y devuelve `?string` ✓
- Los `use` agregados (`Log`, `Security`, `TableRegistry`) coinciden con los namespaces usados (`Log::error`, `Security::encrypt`, `Security::decrypt`, `Security::getSalt`, `TableRegistry::getTableLocator`) ✓
- La firma pública de `set`, `get`, `getGroup`, `setGroup` no cambia ✓

✅ Plan internamente consistente.

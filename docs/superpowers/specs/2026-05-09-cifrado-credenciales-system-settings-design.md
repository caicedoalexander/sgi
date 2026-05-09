# Cifrado en reposo de credenciales en `system_settings`

**Fecha:** 2026-05-09
**Estado:** Spec aprobado — pendiente plan de implementación
**Autor:** Brainstorming colaborativo (Alexander + Claude)

---

## Problema

La tabla `system_settings` almacena credenciales sensibles en texto plano en la columna `setting_value` (tipo `TEXT`). Un dump accidental de la base de datos, una credencial de BD comprometida o un acceso de lectura indebido expone directamente:

- `smtp_password` — credencial de envío de correo (consumida por `CakeMailerAdapter`).
- `notifications_api_key` — API key generada con `random_bytes(32)` que autoriza la API de notificaciones consumida por n8n.

Otros valores de `system_settings` no son sensibles (host, puerto, from_email, etc.) y no requieren cifrado.

## Objetivo

Cifrar **en reposo** las credenciales sensibles dentro de `system_settings`, manteniendo:

- API pública de `SystemSettingsService` sin cambios (los consumidores no se modifican).
- UI de administración sin cambios funcionales.
- Cero migraciones de schema.
- Cero infra adicional (sin secrets manager externo).

## No es objetivo (out of scope)

- Cifrado de credenciales fuera de `system_settings` (por ejemplo, `DATABASE_URL` en `.env` — ese ya vive fuera de la BD).
- Rotación automática de la clave de cifrado.
- Auditoría de accesos a credenciales.
- HSM, KMS u otros gestores externos.

---

## Decisiones de diseño

| Decisión | Elección | Alternativas descartadas |
|----------|----------|--------------------------|
| Origen de la clave de cifrado | `Security::getSalt()` (ya alimentado por `SECURITY_SALT` en `.env`) | Variable `.env` nueva, secrets manager externo |
| Alcance | Solo claves marcadas como sensibles | Cifrar toda la columna `setting_value` |
| Marcado de claves sensibles | Constante hardcoded en `SystemSettingsService::ENCRYPTED_KEYS` | Columna `is_encrypted` en BD, convención por grupo |
| Migración de datos existentes | Ninguna — valores se reingresan por la UI tras el deploy | Comando CLI de reencriptado |
| Algoritmo | `Cake\Utility\Security::encrypt()` (AES-256-CBC + HMAC-SHA256) | OpenSSL directo, libsodium |
| Marcador de cifrado | Prefijo `enc:v1:<base64>` | Columna booleana, sufijo, sin marcador |

---

## Arquitectura

El cifrado vive **encapsulado dentro de `SystemSettingsService`**. Los consumidores siguen llamando `get('smtp_password')` y reciben el valor en claro como hoy. La columna `setting_value` no cambia de tipo; solo cambia el contenido para las claves sensibles.

```
[Admin UI / regenerateApiKey]
        │  (texto plano)
        ▼
SystemSettingsService::set()
        │  ¿clave en ENCRYPTED_KEYS?
        ├── sí → _encrypt() → 'enc:v1:<base64>' → BD
        └── no → valor tal cual → BD

[Consumer: CakeMailerAdapter / N8nService]
        │
        ▼
SystemSettingsService::get() / getGroup()
        │  ¿clave en ENCRYPTED_KEYS y valor empieza con 'enc:v1:'?
        ├── sí → _decrypt() → texto plano → consumer
        ├── sin prefijo (legacy) → valor tal cual → consumer
        └── no es ENCRYPTED_KEY → valor tal cual → consumer
```

---

## Componentes

### Archivos modificados

#### 1. `src/Service/SystemSettingsService.php` (única pieza con lógica nueva)

Cambios:

- **Constantes nuevas:**
  ```php
  private const ENCRYPTED_KEYS = [
      'smtp_password',
      'notifications_api_key',
  ];
  private const CIPHER_PREFIX = 'enc:v1:';
  ```
- **Métodos privados nuevos:**
  - `_encrypt(string $plain): string` — Aplica `Security::encrypt($plain, Security::getSalt())`, retorna `self::CIPHER_PREFIX . base64_encode($cipher)`.
  - `_decrypt(string $stored): ?string` — Si no empieza con `CIPHER_PREFIX`, retorna `$stored` tal cual (legacy). Si empieza con el prefijo, hace `base64_decode` + `Security::decrypt()`. Si `Security::decrypt()` retorna `false`, loguea el error con `\Cake\Log\Log::error()` (sin loguear el cipher) y retorna `null`.
- **`set()` modificado:** si `in_array($key, self::ENCRYPTED_KEYS, true)` y `$value !== null && $value !== ''`, usa `_encrypt($value)` antes de persistir.
- **`get()` modificado:** si `in_array($key, self::ENCRYPTED_KEYS, true)` y el valor leído no es `null`/`''`, usa `_decrypt($value)`. Cache (`$this->cache`) almacena el valor **en claro**.
- **`getGroup()` modificado:** mismo tratamiento por cada setting del grupo cuyo `setting_key` esté en `ENCRYPTED_KEYS`.

#### 2. `src/Controller/SystemSettingsController.php`

**Sin cambios funcionales.** La lógica `if ($key === 'smtp_password' && empty($data[$key])) continue;` (no sobrescribir si el campo viene vacío en el form) sigue siendo correcta.

#### 3. Consumidores

**Sin cambios.** `CakeMailerAdapter`, `N8nService`, `NotificationsController` y cualquier futuro consumidor reciben el valor en claro como siempre.

### Archivos NO tocados

- Migración existente `config/Migrations/20260221000002_CreateSystemSettings.php` — sin cambios.
- No hay migración nueva.
- No hay comando CLI nuevo.
- No hay templates nuevos.

---

## Flujo de datos

### Escritura

```
Controller::index()
  → SystemSettingsService::set('smtp_password', 'MiPass123', 'smtp')
      → ¿ENCRYPTED_KEY? sí
      → _encrypt('MiPass123')
          → Security::encrypt('MiPass123', Security::getSalt())
          → base64_encode(...)
          → 'enc:v1:eyJ...'
      → Table::save(setting_value='enc:v1:eyJ...')
      → unset cache['smtp_password']
```

### Lectura

```
CakeMailerAdapter::send()
  → SystemSettingsService::getGroup('smtp')
      → Table::find()->where(['setting_group' => 'smtp'])->all()
      → para cada setting:
          → si setting_key in ENCRYPTED_KEYS y empieza con 'enc:v1:':
              → _decrypt('enc:v1:eyJ...') → 'MiPass123'
          → cache[key] = valor_en_claro
      → retorna ['smtp_host' => '...', 'smtp_password' => 'MiPass123', ...]
  → mailer arma transport con la pass en claro y envía
```

### Cache

Vive solo en memoria de la request. Se reinicia en cada request. Almacena valores **en claro** para no desencriptar dos veces dentro de la misma request.

---

## Manejo de errores y casos límite

### Caso 1 — Valor sin prefijo `enc:v1:` para clave en `ENCRYPTED_KEYS`

Significa "valor legacy en texto plano" (estado transitorio durante el deploy o edición manual de la columna). `_decrypt()` retorna el valor tal cual. La app no se rompe; la próxima escritura desde la UI cifrará el valor.

### Caso 2 — `Security::decrypt()` retorna `false`

Cipher corrupto, salt rotada, valor truncado. `_decrypt()` retorna `null` y escribe en log con `\Cake\Log\Log::error('SystemSettings decryption failed for key: ' . $key)` (sin incluir el cipher). El consumidor recibe `null`, equivalente a "credencial no configurada":

- `CakeMailerAdapter` enviará con password vacía y SMTP fallará con mensaje claro.
- `N8nService::isConfigured()` retornará `false`.

### Caso 3 — Valor `null` o cadena vacía

`set()` con `null`/`''` no cifra (guarda tal cual). `get()` retorna `null`/`''` sin pasar por `_decrypt()`. Comportamiento idéntico al actual.

### Caso 4 — `SECURITY_SALT` con menos de 32 bytes

`Security::encrypt()` lanza `\Cake\Core\Exception\CakeException`. No se captura: si la app está mal configurada, falla ruidosamente al primer `set()`. La validación efectiva la hace CakePHP en boot.

### Riesgo aceptado — Rotación de `SECURITY_SALT`

Rotar el salt invalida todos los valores cifrados (caen al Caso 2). Se resuelve reingresando los secretos por la UI. Es **out of scope** de este diseño y queda documentado como riesgo conocido.

---

## Validación manual (post-deploy)

Sin tests automatizados (per `CLAUDE.md`). Pasos a ejecutar tras el merge:

### 1. Estado limpio (lectura sin valores cifrados)

- Levantar `php bin/cake server`.
- Loguearse como admin → `/system-settings`.
- El campo "SMTP Password" debe mostrarse vacío (seed inserta `null`).
- `logs/error.log` no debe tener errores nuevos.

### 2. Escritura cifrada

- Cargar `smtp_password` con un valor real desde la UI y guardar.
- `SELECT setting_value FROM system_settings WHERE setting_key = 'smtp_password';`
- Confirmar que el valor empieza con `enc:v1:` y NO es la contraseña en texto plano.

### 3. Lectura cifrada en consumidor real

- Click en "Probar conexión SMTP" (`/system-settings/test-smtp`).
- Si las credenciales son válidas, debe llegar el correo de prueba. Esto demuestra que `CakeMailerAdapter` recibe la contraseña descifrada correctamente.

### 4. API key

- Click en "Regenerar API key" (`/system-settings/regenerate-api-key`).
- `SELECT setting_value FROM system_settings WHERE setting_key = 'notifications_api_key';` → debe empezar con `enc:v1:`.
- Disparar un endpoint de la API de notificaciones que valide la key (n8n llamando con el header). Debe responder 200.

### 5. Valores no sensibles siguen en claro

- `SELECT setting_key, setting_value FROM system_settings WHERE setting_group = 'smtp' AND setting_key != 'smtp_password';`
- `smtp_host`, `smtp_port`, `smtp_from_email`, etc. deben estar legibles en texto plano.

### 6. Defensa contra valores legacy (Caso 1)

- `UPDATE system_settings SET setting_value = 'PlaintextLegacy' WHERE setting_key = 'smtp_password';`
- Recargar la página de configuración. La app NO debe crashear; el form muestra el campo password vacío (por seguridad de la UI) y los logs no deben tener errores de descifrado.
- Reescribir la pass desde la UI y verificar que en BD vuelve a tener prefijo `enc:v1:`.

---

## Resumen del impacto

- **Líneas de código nuevas estimadas:** ~30 (en `SystemSettingsService.php`).
- **Archivos modificados:** 1.
- **Archivos creados:** 0.
- **Migraciones de BD:** 0.
- **Cambios en API pública:** 0.
- **Cambios en UI:** 0.
- **Riesgo de regresión:** bajo — el comportamiento observable desde los consumidores es idéntico al actual.

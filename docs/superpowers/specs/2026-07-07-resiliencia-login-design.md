# Diseño: Consolidación de la resiliencia del módulo de login

- **Fecha:** 2026-07-07
- **Estado:** Aprobado (pendiente de plan de implementación)
- **Autor:** Alexander + Claude
- **Rama:** dev
- **Clasificación:** Hardening de resiliencia/seguridad sobre un controller existente + un
  service stateless. **No** es un módulo de dominio (flujo ni catálogo/CRUD/log); no aplica el
  rubric de paridad de módulos.

## 1. Contexto y problema

El módulo de login (`UsersController::login`) tiene dos protecciones superpuestas contra
abuso, y ambas presentan defectos de resiliencia reportados en producción:

- **Novedad 1 — el rate limit no se libera tras un login exitoso.** Un usuario legítimo
  (p. ej. un aprobador que entra y sale varias veces para aprobar facturas) agota la cuota
  con tráfico legítimo y recibe el error de "demasiados intentos".
- **Novedad 2 — el mensaje de credenciales incorrectas no se ve.** Al fallar el login, "se
  refresca el sitio y no pasa nada": no aparece el flash con la indicación.

Durante la auditoría se encontró además un **tercer defecto silencioso** (bug latente de
seguridad) descrito en §2.4.

## 2. Auditoría: causas raíz

### 2.1 Estado actual del módulo

Existen **dos mecanismos** de protección:

1. **Middleware `RateLimitMiddleware(5, 300)`** aplicado a la ruta `/login`
   (`config/routes.php:60,69`). Cuenta **todos** los requests a `/login` por IP
   (bucket key = `hash(ip|path|ventana)`), máximo 5 en 5 minutos. Corre **antes** del
   controller, dentro del `RoutingMiddleware` y antes del `AuthenticationMiddleware`.
2. **Lockout por email "B-7"** dentro de `UsersController::login()` (líneas 29-42): pretende
   contar solo fallos por cuenta (10 en 15 min) y mostrar un mensaje de bloqueo.

### 2.2 Novedad 2 — flash invisible (causa raíz)

El controller **sí** setea el flash en un POST fallido (`UsersController.php:52`). El fallo es
de CSS:

- El elemento `flash/error.php` → `flash/_toast.php` renderiza `<div class="toast danger">`
  **sin** la clase `.show`.
- Bootstrap (que el layout de login carga) aplica `.toast:not(.show){display:none}`,
  ocultando el toast.
- El layout autenticado `default.php:201` neutraliza esto envolviendo el flash en
  `<div id="spi-flash-container">` — la regla `#spi-flash-container .toast{display:flex;opacity:1}`
  (`components.css:1982`) gana por especificidad — **y** cargando `spi-common.js`.
- El layout de login `login.php:190` hace `$this->Flash->render()` **sin** ese wrapper y
  **sin** `spi-common.js`. → El mensaje queda en el DOM pero invisible.

### 2.3 Novedad 1 — el rate limit no se libera tras el éxito

- El **middleware** cuenta GET (cargar la página) + POST (enviar) + logins **exitosos** por
  igual. Al correr antes del controller **no conoce el resultado y no puede resetearse**. Con
  5 requests / 5 min, un aprobador que entra/sale agota la cuota con tráfico legítimo → 429.
- El **lockout por email** tampoco se resetea tras un login correcto.

### 2.4 Defecto silencioso adicional (bug latente)

El lockout por cuenta lee `getData('email')` (`UsersController.php:31`), pero el formulario
envía el campo **`username`** (`templates/Users/login.php:15`), que además es el campo de
identidad configurado en `Application.php:171`. Por eso `$email` **siempre es cadena vacía**
y **ese lockout nunca se activa**: es código muerto. La protección por cuenta hoy no existe.

### 2.5 Cobertura

**Cero tests** del login / rate-limit a nivel de controller. Solo existe
`RateLimitMiddlewareTest` (el middleware genérico).

## 3. Estándar de referencia

Cómo debe comportarse este tipo de implementación:

1. Limitar por **intentos fallidos**, no por todos los requests.
2. El bloqueo debe **denegar de verdad** los intentos, no solo cambiar un mensaje. Durante el
   bloqueo, la respuesta no debe revelar si las credenciales eran correctas (cerrar el oráculo
   éxito/fallo).
3. **Resetear el contador al autenticar con éxito** (el éxito prueba legitimidad y libera la
   cuota).
4. Separar ejes: **por cuenta** (credential-stuffing dirigido) y **por IP/red** (DoS / flooding).
5. **Feedback claro**: mensaje visible al fallar credenciales y al bloquear.

## 4. Decisiones tomadas

| # | Decisión | Elección |
|---|----------|----------|
| D1 | Modelo de conteo | **Consolidar en el controller**: contar solo fallos, resetear al login exitoso. |
| D2 | Middleware genérico en `/login` | **Aflojarlo** a umbral anti-DoS holgado (no quitarlo): defensa de borde por IP. |
| D3 | UX del error de login | **Toast consistente** (reusar el sistema de flash), no banner inline. |
| D4 | Fuerza del lockout por cuenta | **Enforcement real**: superado el umbral, se deniega todo intento sobre esa cuenta durante la ventana, con el mismo mensaje para éxito y fallo (oráculo cerrado). El eje por IP queda en el middleware de borde (evita el problema NAT del lockout por IP). |

## 5. Diseño

### 5.1 Arquitectura

```
Request /login
  └─ RateLimitMiddleware(30, 300)    ← anti-DoS/flooding de borde por IP (afloja de 5→30)
  └─ AuthenticationMiddleware         ← evalúa credenciales
  └─ UsersController::login()
        └─ LoginThrottleService       ← lockout real por FALLOS de CUENTA (enforcement)
```

Dos ejes deliberadamente separados:

- **Por IP / red:** el middleware de borde (`RateLimitMiddleware(30,300)`). Cuenta todos los
  requests por IP; barrera barata contra flooding. Al no ser por cuenta, no sufre el falso
  positivo de oficinas NAT que comparten IP (más allá del umbral holgado de 30).
- **Por cuenta:** `LoginThrottleService`, dentro del controller (único punto que conoce el
  resultado del login). Cuenta **fallos** por `username` y, superado el umbral, **deniega**
  todo intento sobre esa cuenta durante la ventana.

### 5.2 Componente nuevo: `LoginThrottleService`

`src/Service/LoginThrottleService.php`. Encapsula el conteo de **fallos por cuenta** sobre la
tabla existente `rate_limit_buckets` (sin migración). **Solo eje cuenta** (el eje IP vive en el
middleware).

Obtención de la tabla — **importante (M1)**: NO se recibe `RateLimitBucketsTable` por
constructor. El container DI autowirearía una instancia sin conexión y las queries fallarían.
Se resuelve vía `TableRegistry::getTableLocator()->get('RateLimitBuckets')` **dentro de los
métodos** (patrón del repo: `InvoiceHistoryService` y demás services obtienen tablas así,
nunca por constructor autowireado). Se cachea en una propiedad privada lazy.

API pública:

- `isBlocked(string $username): bool` — `true` si los fallos por cuenta alcanzan/superan el
  umbral en la ventana actual.
- `registerFailure(string $username): void` — incrementa el contador de la cuenta.
- `clear(string $username): void` — **borra** el contador de la cuenta en la ventana actual
  (reset tras login exitoso). Resuelve la Novedad 1.

Detalles:

- Bucket key namespaced para no colisionar con los del middleware:
  `hash('sha256', 'login_user|' . $username . '|' . $windowStart)`.
- `$username` normalizado con `strtolower(trim(...))` por el llamador (el controller) antes de
  invocar; el service asume el valor ya normalizado y no vacío.
- Ventana fija: `$windowStart = (int) floor(time() / WINDOW_SECONDS) * WINDOW_SECONDS`.
- Constantes (ajustables): `WINDOW_SECONDS = 900`, `MAX_FAILURES_PER_ACCOUNT = 10`.

### 5.3 `RateLimitBucketsTable`: cambios

1. **Método nuevo `clearKey(string $bucketKey): int`** que borra la fila del bucket dado
   (`deleteAll(['bucket_key' => $bucketKey])`; `bucket_key` es UNIQUE, borra exactamente 1
   fila). Lo usa `LoginThrottleService::clear()`. Mantiene el SQL encapsulado, coherente con
   `incrementAndGet` / `getCount` / `garbageCollect`.

2. **Piso de retención en `garbageCollect` (M2).** Hoy `garbageCollect($olderThanSeconds)`
   borra por umbral absoluto `window_start < now - olderThanSeconds`, agnóstico al tipo de
   bucket. El GC del middleware de `/approve` (`RateLimitMiddleware(10,60)` →
   `garbageCollect(300)`, `RateLimitMiddleware.php:54`) puede **evictar un bucket de login a
   mitad de su ventana de 900 s**, reseteando el contador de forma no determinista. Se añade un
   piso: internamente `$effective = max($olderThanSeconds, self::MIN_RETENTION_SECONDS)` con
   `MIN_RETENTION_SECONDS = 3600`. Así ningún bucket de ventanas ≤ 1 h (incluida la de login de
   15 min) se borra mientras está activo. Efecto colateral: la tabla retiene filas de los
   middlewares un poco más (hasta 1 h); la tabla es pequeña, es seguro.

### 5.4 Refactor de `UsersController::login()`

Reemplaza la lógica B-7 rota (§2.4). Lee `username` (no `email`). Obtiene el service con
`$this->getContainer()->get(LoginThrottleService::class)`.

Lógica — **enforcement real por cuenta con oráculo cerrado**:

```
$result = $this->Authentication->getResult();

if ($this->request->is('post')):
    $username = strtolower(trim((string) $this->request->getData('username')));

    // Enforcement: cuenta bloqueada → denegar TODO intento (éxito o fallo) con el mismo
    // mensaje. Cierra el oráculo: el atacante no distingue acierto de fallo.
    if ($username !== '' && $throttle->isBlocked($username)):
        if ($result && $result->isValid()):
            $this->Authentication->logout();   // revierte la sesión ya persistida por el middleware
        Flash->error('Demasiados intentos fallidos para esta cuenta. Intentá de nuevo en unos minutos.');
        return;   // ni redirect ni registrar

    if ($result && $result->isValid()):
        if ($username !== '') $throttle->clear($username);   // libera cuota
        return redirect(_safeLoginRedirect());               // login exitoso entra

    // fallo, cuenta no bloqueada:
    if ($username !== ''):
        $throttle->registerFailure($username);
    if ($username !== '' && $throttle->isBlocked($username)):
        Flash->error('Demasiados intentos fallidos para esta cuenta. Intentá de nuevo en unos minutos.');
    else:
        Flash->error('Usuario o contraseña incorrectos.');
else: // GET
    if ($result && $result->isValid()):
        return redirect(_safeLoginRedirect());   // ya autenticado
```

Comportamiento y consecuencias:

- **Corrige el bug del campo** (`username`, no `email`): la protección por cuenta ahora sí
  cuenta y bloquea.
- **Enforcement real:** superado el umbral, todos los intentos sobre esa cuenta se deniegan con
  el mismo mensaje (éxito o fallo), durante la ventana. Un login exitoso durante el bloqueo hace
  `logout()` defensivo para no dejar sesión activa.
- **No penaliza al aprobador ni sufre NAT:** el bloqueo es por `username`, no por IP; cada login
  exitoso limpia el contador, así que un usuario que conoce su clave nunca acumula fallos. Solo
  se bloquea quien falla la contraseña `MAX_FAILURES_PER_ACCOUNT` veces seguidas.
- **POST sin `username`:** no se cuenta por cuenta (no hay cuenta) ni se evalúa bloqueo por
  cuenta; se muestra "Usuario o contraseña incorrectos.". El flooding sin usuario lo limita el
  middleware de borde por IP.
- **No enumera cuentas:** el bloqueo se dispara por fallos sobre un `username` exista o no; el
  mensaje de bloqueo no confirma la existencia de la cuenta. El mensaje de fallo normal es
  genérico.

`_safeLoginRedirect()` se mantiene sin cambios.

### 5.5 Registrar el service en el container

En `Application::services()`: `$container->addShared(LoginThrottleService::class);` **sin
argumentos** (resuelve la tabla vía `TableRegistry` internamente; ver M1 en §5.2).

### 5.6 Aflojar el middleware (routes.php)

`rateLimitLogin`: `new RateLimitMiddleware(5, 300)` → **`new RateLimitMiddleware(30, 300)`**,
con comentario de que es anti-DoS/flooding de borde por IP y que el anti-fuerza-bruta fino por
cuenta vive en el controller vía `LoginThrottleService`. Solo afecta el scope `/login`
(verificado: `rateLimit` y `rateLimitUpload` son instancias independientes).

### 5.7 Fix del flash invisible (Novedad 2)

En `templates/layout/login.php`:

- Mover `$this->Flash->render()` fuera de `.spi-login-card` y envolverlo en
  `<div id="spi-flash-container"><?= $this->Flash->render() ?></div>`, ubicado antes de
  `</body>` (mismo patrón que `default.php:201`). El CSS existente (`components.css:1982`) lo
  hace visible venciendo a Bootstrap; el toast flota abajo-derecha.
- Cargar `spi-common.js` antes de `</body>` (después del bundle de Bootstrap ya presente) →
  habilita el botón de cerrar y `initToasts`. Verificado tolerante: el bloque de upload-meta
  tiene fallback y los listeners son delegados; no rompe sin los demás elementos. El toast
  `danger` no auto-descarta (correcto para un error).

## 6. Seguridad

- **Oráculo cerrado** durante el bloqueo: misma respuesta para éxito y fallo (§5.4).
- Mensaje de bloqueo no enumera cuentas.
- `username` normalizado antes de hashear el bucket key.
- Eje IP/DoS en el middleware de borde; eje cuenta con enforcement real.
- Sin secretos nuevos ni nuevas superficies de entrada. CSRF y autenticación intactos. La
  action `login` sigue siendo `#[NoAuthGate]` / `allowUnauthenticated` (correcto).

## 7. Testing

El repo usa **Factories** (`tests/Factory/`), no fixtures de CakePHP (`tests/Fixture/` solo
tiene `.gitkeep`). Objetivo: cobertura ≥ 80% del código nuevo/tocado.

- `tests/TestCase/Service/LoginThrottleServiceTest.php` (integration ligero contra la BD de
  test, sin mocks — el service resuelve la tabla vía `TableRegistry`):
  - `registerFailure` incrementa; `isBlocked` es `true` al alcanzar `MAX_FAILURES_PER_ACCOUNT`.
  - `clear` resetea (deja de estar bloqueado).
  - Normalización de username (`Ana@x` y `ana@x ` cuentan al mismo bucket).
  - Cuentas distintas son independientes (bloquear una no afecta a otra).
  - Sembrado: `RateLimitBucketsFactory` nueva (o inserts directos vía la tabla). Limpieza entre
    tests con el truncado estándar del `TestCase`.
- `tests/TestCase/Controller/UsersControllerTest.php` (integration, `IntegrationTestTrait`,
  patrón de `InvoicesControllerTest`; usuario sembrado con `UserFactory` + password hasheada +
  rol):
  - GET `/login` → 200 y renderiza el formulario.
  - POST credenciales incorrectas → 200 (no redirect) + `assertResponseContains('Usuario o
    contraseña incorrectos.')` (verifica que el flash ya no es invisible en el HTML).
  - POST credenciales correctas → redirect al destino seguro + contador del usuario limpio.
  - Tras `MAX_FAILURES_PER_ACCOUNT` fallos: el intento siguiente (aun con credenciales
    correctas) es denegado con el mensaje de bloqueo (enforcement + oráculo cerrado).
  - Login exitoso re-habilita intentos (escenario del aprobador que entra/sale).
- `garbageCollect` con piso de retención: test unit en `RateLimitBucketsTableTest` (o extender
  el existente) verificando que un bucket con `window_start` de hace 10 min **no** se borra con
  `garbageCollect(300)`.

## 8. Archivos afectados

| Acción | Archivo |
|--------|---------|
| NEW | `src/Service/LoginThrottleService.php` |
| EDIT | `src/Model/Table/RateLimitBucketsTable.php` (`clearKey` + piso en `garbageCollect`) |
| EDIT | `src/Controller/UsersController.php` (refactor `login`) |
| EDIT | `src/Application.php` (registrar service) |
| EDIT | `config/routes.php` (aflojar `rateLimitLogin` 5→30) |
| EDIT | `templates/layout/login.php` (wrapper flash + `spi-common.js`) |
| NEW | `tests/TestCase/Service/LoginThrottleServiceTest.php` |
| NEW | `tests/TestCase/Controller/UsersControllerTest.php` |
| NEW (si aplica) | `tests/Factory/RateLimitBucketsFactory.php` |

**Sin migración de base de datos** (se reutiliza `rate_limit_buckets`).

## 9. Fuera de alcance (YAGNI)

- Backoff progresivo / demoras crecientes.
- Tabla dedicada de intentos de login.
- CAPTCHA.
- Enforcement por IP dentro del service (se deja al middleware de borde, para no reintroducir
  falsos positivos de NAT).
- Cambiar el diseño de la pantalla de login (banner inline): se descartó a favor del toast
  consistente (D3).

## 10. Criterios de éxito

1. Un usuario que inicia sesión correctamente puede volver a iniciar sesión de inmediato
   cuantas veces necesite sin toparse con el error de intentos (contador limpio tras el éxito).
2. Un POST con credenciales incorrectas muestra un mensaje visible en la pantalla de login.
3. Tras superar el umbral de fallos sobre una cuenta, todo intento sobre ella se **deniega** con
   el mismo mensaje (éxito o fallo) hasta que pase la ventana; el bloqueo no revela si las
   credenciales eran correctas.
4. La protección por cuenta (antes muerta por el bug `email`/`username`) queda activa y con
   enforcement real.
5. El contador de fallos de login no se resetea por el GC de otros limitadores a mitad de
   ventana.
6. `composer cs-check` y `vendor/bin/phpunit` en verde, con los nuevos tests cubriendo los
   escenarios de §7.

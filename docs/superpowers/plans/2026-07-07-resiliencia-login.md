# Resiliencia del módulo de login — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consolidar la resiliencia del login: liberar el rate limit tras un login exitoso, hacer visible el flash de credenciales incorrectas, y activar un lockout real por cuenta.

**Architecture:** El anti-fuerza-bruta fino se mueve al controller mediante un `LoginThrottleService` que cuenta **fallos por cuenta** sobre la tabla existente `rate_limit_buckets` y se **resetea al login exitoso** (enforcement real: superado el umbral, el controller deniega todo intento sobre esa cuenta). El middleware `RateLimitMiddleware` de `/login` queda como barrera anti-DoS de borde por IP (aflojado a 30/300). El flash se hace visible envolviéndolo en `#spi-flash-container` y cargando `spi-common.js` en el layout de login.

**Tech Stack:** PHP 8.4+, CakePHP 5.3, cakephp/authentication 4.x, PHPUnit + `IntegrationTestTrait`, dereuromark/cakephp-fixture-factories.

## Global Constraints

- **PHP:** `>=8.4`. **Framework:** CakePHP 5.3. No introducir dependencias nuevas.
- **Services obtienen tablas vía** `TableRegistry::getTableLocator()->get('TableName')`, **nunca** por constructor autowireado ni `$this->TableName` (si el container autowirea un `RateLimitBucketsTable` por constructor, inyecta una instancia sin conexión y las queries fallan).
- **Inyección DI:** services se registran con `$container->addShared(Clase::class)`.
- **Factories de test:** la librería es `dereuromark/cakephp-fixture-factories`. Persistir con `->save()` (NO existe `->persist()`). `UserFactory::new()` auto-crea el Role requerido y dispara `User::_setPassword` (hashea); pasar la contraseña en **texto plano**.
- **Slugs técnicos internos** (bucket keys) en **inglés** (`login_user|…`), coherente con la convención del proyecto.
- **Sin migración de base de datos:** se reutiliza la tabla `rate_limit_buckets` (columna `bucket_key` UNIQUE, `varchar(64)`, alcanza para un `sha256` de 64 hex).
- **Copy de UI en español rioplatense** ya presente en el proyecto (p. ej. "Intentá de nuevo en unos minutos.").
- **Estilo de código:** `composer cs-check` debe pasar (CakePHP standard); usar `composer cs-fix` para autoformatear (también normaliza el orden de `use`).
- **Tests:** correr con `vendor/bin/phpunit` (no `composer test`). La suite completa puede tardar; usar `--filter` para tests puntuales.
- **Commits:** conventional commits (`feat:`, `fix:`, `test:`, `refactor:`, `docs:`).

**Spec de referencia:** `docs/superpowers/specs/2026-07-07-resiliencia-login-design.md`

## File Structure

| Archivo | Responsabilidad | Task |
|---------|-----------------|------|
| `src/Model/Table/RateLimitBucketsTable.php` (modify) | `clearKey()` (reset de un bucket) + piso de retención en `garbageCollect()`. | 1 |
| `src/Service/LoginThrottleService.php` (create) | Lockout por fallos de cuenta. Encapsula keys/ventana/umbral. Tabla lazy vía TableRegistry. | 2 |
| `config/routes.php` (modify) | Aflojar `rateLimitLogin` de 5/300 a 30/300 (borde por IP). | 3 |
| `src/Application.php` (modify) | Registrar `LoginThrottleService` en el container DI. | 4 |
| `src/Controller/UsersController.php` (modify) | Orquesta el login: normaliza, consulta/registra fallos, enforcement, reset al éxito, flash. | 4 |
| `templates/layout/login.php` (modify) | Hacer visible el flash: wrapper `#spi-flash-container` + `spi-common.js`. | 5 |
| `tests/TestCase/Model/Table/RateLimitBucketsTableTest.php` (create) | Cubre `clearKey` y el piso de `garbageCollect`. | 1 |
| `tests/TestCase/Service/LoginThrottleServiceTest.php` (create) | Umbral, reset, independencia por cuenta, normalización. | 2 |
| `tests/TestCase/Controller/UsersControllerTest.php` (create) | Integration: borde del middleware, flash visible, redirect+reset, enforcement. | 3, 4, 5 |

**Orden de build (dependencias):** Task 1 (tabla) → Task 2 (service) → Task 3 (aflojar middleware + crear el test file con el test de borde) → Task 4 (registro DI + refactor controller + tests de credenciales/lockout — **requieren el middleware ya aflojado**) → Task 5 (flash) → Task 6 (verificación).

---

## Task 1: `RateLimitBucketsTable` — `clearKey()` + piso de retención en `garbageCollect()`

**Files:**
- Modify: `src/Model/Table/RateLimitBucketsTable.php`
- Test: `tests/TestCase/Model/Table/RateLimitBucketsTableTest.php` (create)

**Interfaces:**
- Consumes: nada nuevo (usa `incrementAndGet`, `getCount`, `deleteAll` ya existentes).
- Produces:
  - `clearKey(string $bucketKey): int` — borra la fila del bucket dado, devuelve nº de filas borradas.
  - `garbageCollect(int $olderThanSeconds): int` — misma firma; ahora aplica un piso de retención de `3600` s.

- [ ] **Step 1: Escribir el test de la tabla (falla)**

Crear `tests/TestCase/Model/Table/RateLimitBucketsTableTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\RateLimitBucketsTable;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use DateTime;

class RateLimitBucketsTableTest extends TestCase
{
    private RateLimitBucketsTable $buckets;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \App\Model\Table\RateLimitBucketsTable $table */
        $table = TableRegistry::getTableLocator()->get('RateLimitBuckets');
        $this->buckets = $table;
        $this->buckets->deleteAll('1=1');
    }

    public function testClearKeyRemovesTheBucketRow(): void
    {
        $windowStart = (int)floor(time() / 900) * 900;
        $this->buckets->incrementAndGet('k-test', $windowStart);
        $this->assertSame(1, $this->buckets->getCount('k-test'));

        $removed = $this->buckets->clearKey('k-test');

        $this->assertSame(1, $removed);
        $this->assertSame(0, $this->buckets->getCount('k-test'));
    }

    public function testGarbageCollectKeepsBucketsYoungerThanRetentionFloor(): void
    {
        // Bucket cuya ventana empezó hace 10 min (600 s): dentro del piso de 1 h.
        $tenMinAgo = (new DateTime())->modify('-600 seconds')->format('Y-m-d H:i:s');
        $this->buckets->getConnection()->execute(
            'INSERT INTO rate_limit_buckets (bucket_key, window_start, count, created, modified)
             VALUES (?, ?, 1, ?, ?)',
            ['k-recent', $tenMinAgo, $tenMinAgo, $tenMinAgo],
        );

        // Un limitador de ventana corta pide GC de 300 s; el piso lo eleva a 3600 s.
        $this->buckets->garbageCollect(300);

        $this->assertSame(1, $this->buckets->getCount('k-recent'));
    }
}
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `vendor/bin/phpunit tests/TestCase/Model/Table/RateLimitBucketsTableTest.php`
Expected: FAIL — `Error: Call to undefined method …::clearKey()` (y el segundo test aún no protege el piso).

- [ ] **Step 3: Agregar `clearKey()` y el piso en `garbageCollect()`**

En `src/Model/Table/RateLimitBucketsTable.php`, agregar la constante de retención dentro de la clase (por ejemplo justo después de `class RateLimitBucketsTable extends Table\n{`):

```php
    /**
     * Piso de retención de la GC. Los buckets de limitadores con ventanas más
     * largas (p. ej. el throttle de login, 900 s) deben sobrevivir toda su
     * ventana aunque un limitador de ventana corta dispare la GC.
     */
    private const MIN_RETENTION_SECONDS = 3600;
```

Reemplazar el método `garbageCollect()` por:

```php
    /**
     * Delete bucket rows whose window started more than $olderThanSeconds ago,
     * never borrando buckets más recientes que MIN_RETENTION_SECONDS.
     */
    public function garbageCollect(int $olderThanSeconds): int
    {
        $effective = max($olderThanSeconds, self::MIN_RETENTION_SECONDS);
        $cutoff = (new DateTime())->modify("-{$effective} seconds")->format('Y-m-d H:i:s');

        return $this->deleteAll(['window_start <' => $cutoff]);
    }
```

Agregar el método nuevo (por ejemplo después de `garbageCollect()`):

```php
    /**
     * Delete the single bucket row for the given key. Usado por el throttle de
     * login para resetear el contador de una cuenta tras un login exitoso.
     */
    public function clearKey(string $bucketKey): int
    {
        return $this->deleteAll(['bucket_key' => $bucketKey]);
    }
```

- [ ] **Step 4: Correr el test para verificar que pasa**

Run: `vendor/bin/phpunit tests/TestCase/Model/Table/RateLimitBucketsTableTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Verificar estilo y commitear**

Run: `composer cs-fix` y luego:

```bash
git add src/Model/Table/RateLimitBucketsTable.php tests/TestCase/Model/Table/RateLimitBucketsTableTest.php
git commit -m "feat: clearKey y piso de retención en RateLimitBucketsTable"
```

---

## Task 2: `LoginThrottleService`

**Files:**
- Create: `src/Service/LoginThrottleService.php`
- Test: `tests/TestCase/Service/LoginThrottleServiceTest.php` (create)

**Interfaces:**
- Consumes: `RateLimitBucketsTable::incrementAndGet(string, int)`, `getCount(string)`, `clearKey(string)` (Task 1).
- Produces:
  - `isBlocked(string $username): bool`
  - `registerFailure(string $username): void`
  - `clear(string $username): void`
  - **Desviación intencional del spec §5.4:** el spec asignaba la normalización del username al controller; aquí el **service normaliza internamente** (`strtolower(trim(...))`) para que la bucket key sea única sea cual sea el llamador. El controller igual hace `trim` para su chequeo de "username vacío". Funcionalmente equivalente y más robusto (lo valida `testUsernameIsNormalized`).

- [ ] **Step 1: Escribir el test del service (falla)**

Crear `tests/TestCase/Service/LoginThrottleServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\LoginThrottleService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class LoginThrottleServiceTest extends TestCase
{
    private LoginThrottleService $throttle;

    protected function setUp(): void
    {
        parent::setUp();
        TableRegistry::getTableLocator()->get('RateLimitBuckets')->deleteAll('1=1');
        $this->throttle = new LoginThrottleService();
    }

    public function testNotBlockedInitially(): void
    {
        $this->assertFalse($this->throttle->isBlocked('ana'));
    }

    public function testBlocksAtThreshold(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->throttle->registerFailure('ana');
        }
        $this->assertTrue($this->throttle->isBlocked('ana'));
    }

    public function testNineFailuresStayBelowThreshold(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->throttle->registerFailure('ana');
        }
        $this->assertFalse($this->throttle->isBlocked('ana'));
    }

    public function testClearResetsCounter(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->throttle->registerFailure('ana');
        }
        $this->throttle->clear('ana');
        $this->assertFalse($this->throttle->isBlocked('ana'));
    }

    public function testAccountsAreIndependent(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->throttle->registerFailure('ana');
        }
        $this->assertTrue($this->throttle->isBlocked('ana'));
        $this->assertFalse($this->throttle->isBlocked('bob'));
    }

    public function testUsernameIsNormalized(): void
    {
        // 'Ana', ' ana ' y 'ana' apuntan al mismo bucket → 10 fallos → bloqueada.
        $this->throttle->registerFailure('Ana');
        $this->throttle->registerFailure(' ana ');
        for ($i = 0; $i < 8; $i++) {
            $this->throttle->registerFailure('ana');
        }
        $this->assertTrue($this->throttle->isBlocked('ANA'));
    }
}
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `vendor/bin/phpunit tests/TestCase/Service/LoginThrottleServiceTest.php`
Expected: FAIL — `Error: Class "App\Service\LoginThrottleService" not found`.

- [ ] **Step 3: Implementar el service**

Crear `src/Service/LoginThrottleService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Table\RateLimitBucketsTable;
use Cake\ORM\TableRegistry;

/**
 * Lockout de login por FALLOS de cuenta sobre la tabla rate_limit_buckets.
 *
 * El eje por IP vive en RateLimitMiddleware (borde). Este service es el eje por
 * cuenta con enforcement real: superado el umbral, el controller deniega todo
 * intento sobre esa cuenta durante la ventana. Un login exitoso limpia el
 * contador (el usuario legítimo no arrastra fallos).
 *
 * La tabla se resuelve vía TableRegistry (no por constructor) para no ser
 * autowireada por el container con una instancia sin conexión.
 */
class LoginThrottleService
{
    private const WINDOW_SECONDS = 900;
    private const MAX_FAILURES_PER_ACCOUNT = 10;

    private ?RateLimitBucketsTable $buckets = null;

    private function buckets(): RateLimitBucketsTable
    {
        /** @var \App\Model\Table\RateLimitBucketsTable $table */
        $table = TableRegistry::getTableLocator()->get('RateLimitBuckets');

        return $this->buckets ??= $table;
    }

    private function windowStart(): int
    {
        return (int)floor(time() / self::WINDOW_SECONDS) * self::WINDOW_SECONDS;
    }

    private function keyFor(string $username): string
    {
        $normalized = strtolower(trim($username));

        return hash('sha256', 'login_user|' . $normalized . '|' . $this->windowStart());
    }

    public function isBlocked(string $username): bool
    {
        return $this->buckets()->getCount($this->keyFor($username)) >= self::MAX_FAILURES_PER_ACCOUNT;
    }

    public function registerFailure(string $username): void
    {
        $this->buckets()->incrementAndGet($this->keyFor($username), $this->windowStart());
    }

    public function clear(string $username): void
    {
        $this->buckets()->clearKey($this->keyFor($username));
    }
}
```

- [ ] **Step 4: Correr el test para verificar que pasa**

Run: `vendor/bin/phpunit tests/TestCase/Service/LoginThrottleServiceTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Verificar estilo y commitear**

Run: `composer cs-fix` y luego:

```bash
git add src/Service/LoginThrottleService.php tests/TestCase/Service/LoginThrottleServiceTest.php
git commit -m "feat: LoginThrottleService (lockout por fallos de cuenta)"
```

---

## Task 3: Aflojar el middleware `rateLimitLogin` + crear el test de integration

Esta tarea afloja el borde por IP **antes** de introducir los tests de lockout (Task 4), que disparan >5 requests por test y no podrían pasar con el límite viejo de 5.

**Files:**
- Modify: `config/routes.php` (registro de `rateLimitLogin`, ~línea 58-61)
- Test: `tests/TestCase/Controller/UsersControllerTest.php` (create — con `setUp` completo + test de borde)

**Interfaces:**
- Consumes: `UserFactory` (repo), `RateLimitBucketsTable` (para limpiar en `setUp`).
- Produces: `/login` tolera hasta 30 requests por IP en 5 min; archivo de test con `setUp` que las Tasks 4 y 5 reutilizan.

- [ ] **Step 1: Crear el test file con el test de borde (falla)**

Crear `tests/TestCase/Controller/UsersControllerTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class UsersControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private const PASSWORD = 'Secreta123!';

    protected function setUp(): void
    {
        parent::setUp();
        TableRegistry::getTableLocator()->get('RateLimitBuckets')->deleteAll('1=1');
        UserFactory::new([
            'username' => 'aprobador',
            'password' => self::PASSWORD,
            'active' => true,
        ])->save();
        $this->enableCsrfToken();
        $this->enableRetainFlashMessages();
    }

    private function accountBucketCount(string $username): int
    {
        $windowStart = (int)floor(time() / 900) * 900;
        $key = hash('sha256', 'login_user|' . strtolower(trim($username)) . '|' . $windowStart);

        return TableRegistry::getTableLocator()->get('RateLimitBuckets')->getCount($key);
    }

    public function testLoginToleratesSeveralRequestsUnderEdgeLimit(): void
    {
        // Con el límite viejo (5/300) el 6º GET daría 429; con 30/300 pasa.
        for ($i = 0; $i < 6; $i++) {
            $this->get('/login');
            $this->assertResponseOk();
        }
    }
}
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `vendor/bin/phpunit tests/TestCase/Controller/UsersControllerTest.php`
Expected: FAIL — el 6º request devuelve 429 (límite actual 5).

- [ ] **Step 3: Aflojar el middleware**

En `config/routes.php`, reemplazar el registro de `rateLimitLogin`:

```php
        $builder->registerMiddleware(
            'rateLimitLogin',
            new RateLimitMiddleware(5, 300),
        );
```

por:

```php
        // Borde anti-DoS/flooding por IP en /login. El anti-fuerza-bruta fino
        // por cuenta vive en el controller vía LoginThrottleService, así que
        // este límite es holgado para no penalizar el flujo legítimo (NAT).
        $builder->registerMiddleware(
            'rateLimitLogin',
            new RateLimitMiddleware(30, 300),
        );
```

- [ ] **Step 4: Correr el test para verificar que pasa**

Run: `vendor/bin/phpunit tests/TestCase/Controller/UsersControllerTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commitear**

```bash
git add config/routes.php tests/TestCase/Controller/UsersControllerTest.php
git commit -m "fix: aflojar rateLimitLogin a 30/300 (borde por IP)"
```

---

## Task 4: Refactor de `UsersController::login()` + registro en el container

**Files:**
- Modify: `src/Application.php` (imports de service + sección `services()`)
- Modify: `src/Controller/UsersController.php` (método `login`, ~líneas 21-54; imports del encabezado)
- Test: `tests/TestCase/Controller/UsersControllerTest.php` (agregar métodos)

**Interfaces:**
- Consumes: `LoginThrottleService::isBlocked/registerFailure/clear` (Task 2); `AppController::getContainer()` (existente); `$this->Authentication->getResult()/logout()` (existente); middleware ya aflojado (Task 3).
- Produces: comportamiento de `GET/POST /login` descrito en el spec §5.4.

- [ ] **Step 1: Registrar el service en el container**

En `src/Application.php`, agregar el import junto a los demás `use App\Service\...` (orden alfabético: entre `use App\Service\LiquidationDocPaymentService;` y `use App\Service\N8nService;`):

```php
use App\Service\LoginThrottleService;
```

Dentro de `public function services(ContainerInterface $container): void`, en la sección `// === Auth / Authorization ===`, agregar:

```php
        $container->addShared(LoginThrottleService::class);
```

- [ ] **Step 2: Agregar los tests de credenciales y lockout (fallan)**

En `tests/TestCase/Controller/UsersControllerTest.php`, agregar estos métodos dentro de la clase:

```php
    public function testGetRendersLoginForm(): void
    {
        $this->get('/login');
        $this->assertResponseOk();
        $this->assertResponseContains('name="username"');
    }

    public function testWrongCredentialsShowFlashMessage(): void
    {
        $this->post('/login', ['username' => 'aprobador', 'password' => 'mala']);
        $this->assertResponseOk();
        $this->assertResponseContains('Usuario o contraseña incorrectos.');
    }

    public function testCorrectCredentialsRedirectAndClearCounter(): void
    {
        // Tres fallos previos: el éxito debe limpiar el contador.
        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['username' => 'aprobador', 'password' => 'mala']);
        }
        $this->assertSame(3, $this->accountBucketCount('aprobador'));

        $this->post('/login', ['username' => 'aprobador', 'password' => self::PASSWORD]);
        $this->assertResponseCode(302);
        $this->assertSame(0, $this->accountBucketCount('aprobador'));
    }

    public function testAccountLockoutDeniesEvenCorrectPassword(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/login', ['username' => 'aprobador', 'password' => 'mala']);
        }
        // Cuenta bloqueada: incluso la contraseña correcta se deniega (oráculo cerrado).
        $this->post('/login', ['username' => 'aprobador', 'password' => self::PASSWORD]);
        $this->assertResponseOk();
        $this->assertResponseContains('Demasiados intentos fallidos');
    }
```

- [ ] **Step 3: Correr los tests para verificar que fallan**

Run: `vendor/bin/phpunit tests/TestCase/Controller/UsersControllerTest.php`
Expected: FAIL — `testCorrectCredentialsRedirectAndClearCounter` y `testAccountLockoutDeniesEvenCorrectPassword` fallan porque el controller aún cuenta por `email` (bucket siempre vacío) y no aplica enforcement ni reset. (`testGetRendersLoginForm` y `testWrongCredentialsShowFlashMessage` ya pasan: el controller viejo también setea ese flash.)

- [ ] **Step 4: Reescribir el método `login()`**

En `src/Controller/UsersController.php`:

1. En el encabezado de imports, **quitar** `use Cake\ORM\TableRegistry;` y **agregar** `use App\Service\LoginThrottleService;` en orden alfabético (después de `use App\Attribute\Permission;` y antes de `use Cake\Event\EventInterface;`). El bloque de `use` queda:

```php
use App\Attribute\NoAuthGate;
use App\Attribute\Permission;
use App\Service\LoginThrottleService;
use Cake\Event\EventInterface;
```

2. Reemplazar el método `login()` completo (desde el atributo `#[NoAuthGate...]` hasta el cierre `}` del método) por:

```php
    #[NoAuthGate(reason: 'External flow before authentication')]
    public function login()
    {
        $this->viewBuilder()->setLayout('login');
        $this->request->allowMethod(['get', 'post']);

        $result = $this->Authentication->getResult();
        /** @var \App\Service\LoginThrottleService $throttle */
        $throttle = $this->getContainer()->get(LoginThrottleService::class);

        if ($this->request->is('post')) {
            $username = trim((string)$this->request->getData('username'));

            // Enforcement: cuenta bloqueada → denegar todo intento (éxito o fallo)
            // con el mismo mensaje. Cierra el oráculo éxito/fallo.
            if ($username !== '' && $throttle->isBlocked($username)) {
                if ($result && $result->isValid()) {
                    $this->Authentication->logout();
                }
                $this->Flash->error('Demasiados intentos fallidos para esta cuenta. Intentá de nuevo en unos minutos.');

                return null;
            }

            if ($result && $result->isValid()) {
                if ($username !== '') {
                    $throttle->clear($username);
                }

                return $this->redirect($this->_safeLoginRedirect());
            }

            if ($username !== '') {
                $throttle->registerFailure($username);
            }

            if ($username !== '' && $throttle->isBlocked($username)) {
                $this->Flash->error('Demasiados intentos fallidos para esta cuenta. Intentá de nuevo en unos minutos.');
            } else {
                $this->Flash->error('Usuario o contraseña incorrectos.');
            }

            return null;
        }

        if ($result && $result->isValid()) {
            return $this->redirect($this->_safeLoginRedirect());
        }

        return null;
    }
```

- [ ] **Step 5: Correr los tests para verificar que pasan**

Run: `vendor/bin/phpunit tests/TestCase/Controller/UsersControllerTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Verificar estilo y commitear**

Run: `composer cs-fix` y luego:

```bash
git add src/Controller/UsersController.php src/Application.php tests/TestCase/Controller/UsersControllerTest.php
git commit -m "feat: lockout real por cuenta + reset al éxito en login"
```

---

## Task 5: Hacer visible el flash en el layout de login

**Files:**
- Modify: `templates/layout/login.php` (quitar el `Flash->render()` de la tarjeta ~línea 190; agregar wrapper + script antes de `</body>` ~línea 204)
- Test: `tests/TestCase/Controller/UsersControllerTest.php` (agregar un método)

**Interfaces:**
- Consumes: infra de test de Tasks 3-4; CSS existente `#spi-flash-container .toast` (`webroot/css/components.css:1982`).
- Produces: el flash se renderiza dentro de `#spi-flash-container` y la página carga `spi-common.js`.

- [ ] **Step 1: Agregar el test del wrapper (falla)**

En `tests/TestCase/Controller/UsersControllerTest.php`, agregar:

```php
    public function testLoginFlashIsWrappedForVisibility(): void
    {
        $this->post('/login', ['username' => 'aprobador', 'password' => 'mala']);
        $this->assertResponseOk();
        // El toast debe estar dentro del contenedor que lo hace visible (vence a
        // .toast:not(.show){display:none} de Bootstrap) y la página carga el JS.
        $this->assertResponseContains('id="spi-flash-container"');
        $this->assertResponseContains('spi-common');
    }
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `vendor/bin/phpunit tests/TestCase/Controller/UsersControllerTest.php --filter testLoginFlashIsWrappedForVisibility`
Expected: FAIL — el layout de login no contiene `id="spi-flash-container"` ni `spi-common`.

- [ ] **Step 3: Editar el layout**

En `templates/layout/login.php`:

1. **Quitar** la línea que renderiza el flash dentro de la tarjeta (dentro de `.spi-login-card`):

```php
            <?= $this->Flash->render() ?>
```

(Dejar el resto de la tarjeta intacto: el `<?= $this->fetch('content') ?>` permanece.)

2. **Antes de** `<script src="<?= $this->Url->build('/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>` (o justo antes de `</body>`), agregar el contenedor del flash:

```php
    <div id="spi-flash-container">
        <?= $this->Flash->render() ?>
    </div>
```

3. **Después** de la etiqueta `<script>` del bundle de Bootstrap (y antes de `</body>`), cargar el JS común:

```php
    <?= $this->Html->script('spi-common', ['block' => false]) ?>
```

- [ ] **Step 4: Correr el test para verificar que pasa**

Run: `vendor/bin/phpunit tests/TestCase/Controller/UsersControllerTest.php --filter testLoginFlashIsWrappedForVisibility`
Expected: PASS.

- [ ] **Step 5: Verificación manual (recomendada)**

Levantar el server (`php bin/cake server`), ir a `/login`, ingresar credenciales incorrectas y confirmar que el toast rojo "Usuario o contraseña incorrectos." es visible abajo-derecha y se puede cerrar.

- [ ] **Step 6: Commitear**

```bash
git add templates/layout/login.php tests/TestCase/Controller/UsersControllerTest.php
git commit -m "fix: hacer visible el flash en la pantalla de login"
```

---

## Task 6: Verificación final de la suite

**Files:** ninguno (solo verificación).

- [ ] **Step 1: Correr los tests tocados juntos**

Run:
```bash
vendor/bin/phpunit tests/TestCase/Model/Table/RateLimitBucketsTableTest.php tests/TestCase/Service/LoginThrottleServiceTest.php tests/TestCase/Controller/UsersControllerTest.php
```
Expected: PASS (todos).

- [ ] **Step 2: Correr la suite completa para descartar regresiones**

Run: `vendor/bin/phpunit`
Expected: sin fallos nuevos respecto al baseline (ver memoria del proyecto sobre notices preexistentes). Si aparecen errores en cascada, re-correr limpio antes de concluir.

- [ ] **Step 3: Estilo**

Run: `composer cs-check`
Expected: sin violaciones. Si las hay, `composer cs-fix` y volver a commitear.

---

## Self-Review (completado por el autor del plan)

**Cobertura del spec:**
- §5.2 `LoginThrottleService` → Task 2. ✓
- §5.3 `clearKey` + piso `garbageCollect` → Task 1. ✓
- §5.4 refactor controller (enforcement, reset, POST sin username, logout defensivo) → Task 4. ✓
- §5.5 registro en container → Task 4, Step 1. ✓
- §5.6 aflojar middleware → Task 3. ✓
- §5.7 fix del flash → Task 5. ✓
- §7 testing (service, controller integration, GC) → Tasks 1-5 + Task 6. ✓
- §10 criterios de éxito → cubiertos por los tests de Tasks 3-5 y la verificación de Task 6. ✓

**Placeholders:** ninguno; todos los steps de código muestran el código real.

**Consistencia de tipos/nombres:** `clearKey(string): int`, `isBlocked(string): bool`, `registerFailure(string): void`, `clear(string): void` se usan idénticos en el service (Task 2) y el controller (Task 4). El bucket key `login_user|{username_normalizado}|{windowStart}` es idéntico en el service y en el helper `accountBucketCount` del test (que también hace `strtolower(trim(...))`). `RateLimitBuckets` resuelto vía TableRegistry en todos los sitios.

**Correcciones aplicadas tras revisión (spi-plan-reviewer):**
- Factory: `->save()` (no `->persist()`, inexistente en dereuromark/cakephp-fixture-factories).
- Orden de build: aflojar el middleware (Task 3) **antes** de los tests de lockout (Task 4), que disparan >5 requests.
- Password: el factory hashea vía `User::_setPassword`; se pasa texto plano (sin pre-hash, evita doble hash).
- Normalización movida al service documentada como desviación intencional del spec.

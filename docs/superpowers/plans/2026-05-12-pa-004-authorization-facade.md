# PA-004 — AuthorizationFacade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introducir un contrato común `AuthorizationFacade` que unifique los gateways de chequeo de permisos (CRUD + pipeline), eliminando la dependencia directa de controllers y services a las dos clases concretas existentes.

**Architecture:** Crear capa nueva (`UserContext` VO, `CrudAction` enum, `AuthorizationFacade` interface + `DefaultAuthorizationFacade` impl) que compone los services existentes. Migrar 19 archivos operativos del gate al Facade. `RolesController` y `AppController::_setUserPermissions` mantienen dependencia directa a los services internos para matrices/save (decisión explícita del audit). 5 commits, 1 PR.

**Tech Stack:** PHP 8.4, CakePHP 5.3, league/container (DI). Sin tests (política del proyecto — validación 100% manual).

**Spec:** `docs/superpowers/specs/2026-05-12-pa-004-authorization-facade-design.md`

**Note on TDD:** Este proyecto **no** usa tests automatizados (ver `CLAUDE.md` → "Testing Policy"). Los steps de validación reemplazan los "run test to verify" del patrón TDD por validación manual con `bin/cake server` o inspección estática (`composer cs-check`, `php -l`).

---

## Commit 1 — Contract layer

Introduce los archivos nuevos sin tocar comportamiento existente. Después de este commit, `bin/cake server` debe seguir funcionando idéntico (la capa nueva queda registrada en DI pero ningún consumer la usa todavía).

### Task 1.1: Crear `UserContext` value object

**Files:**
- Create: `src/ValueObject/UserContext.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\ValueObject;

use ArrayAccess;

/**
 * Identificador del actor para chequeos de autorización.
 *
 * `roleName` se conserva solo para el admin bypass actual
 * (`AuthorizationService::ADMIN_BYPASS_MODULES`). Cuando PA-007 caiga, el
 * campo desaparece. Si el caller solo necesita `canOperate` (no `canCrud`),
 * puede dejar `roleName` vacío — el campo no se consulta en esa ruta.
 */
final readonly class UserContext
{
    public function __construct(
        public int $roleId,
        public string $roleName = '',
    ) {
    }

    /**
     * @param \ArrayAccess<string, mixed>|object $identity Identidad de Authentication o entidad User.
     */
    public static function fromIdentity(ArrayAccess|object $identity): self
    {
        $roleId = is_object($identity) && !($identity instanceof ArrayAccess)
            ? (int)($identity->role_id ?? 0)
            : (int)$identity['role_id'];

        $roleName = is_object($identity) && !($identity instanceof ArrayAccess)
            ? (string)($identity->role?->name ?? '')
            : (string)($identity['role']['name'] ?? '');

        return new self($roleId, $roleName);
    }
}
```

- [ ] **Step 2: Validar sintaxis**

Run: `php -l src/ValueObject/UserContext.php`
Expected: `No syntax errors detected`

### Task 1.2: Crear `CrudAction` enum

**Files:**
- Create: `src/Authorization/CrudAction.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\Authorization;

/**
 * Acciones CRUD reconocidas por `AuthorizationFacade::canCrud`.
 *
 * Los valores string coinciden con los slugs usados en la tabla `permissions`
 * y en el atributo `#[Permission(action: '...')]` para preservar compat.
 */
enum CrudAction: string
{
    case View = 'view';
    case Add = 'add';
    case Edit = 'edit';
    case Delete = 'delete';
}
```

- [ ] **Step 2: Validar sintaxis**

Run: `php -l src/Authorization/CrudAction.php`
Expected: `No syntax errors detected`

### Task 1.3: Crear interfaz `AuthorizationFacade`

**Files:**
- Create: `src/Authorization/AuthorizationFacade.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\Authorization;

use App\ValueObject\UserContext;

/**
 * Fachada unificada para chequeos de permisos.
 *
 * Implementación canónica: `DefaultAuthorizationFacade`. Las matrices
 * (`getPermissionsForRoleAsMatrix`, `getPermissionsMatrix`) y `save*` quedan
 * fuera del contrato — solo `RolesController` y
 * `AppController::_setUserPermissions` dependen de los services internos
 * (ver audit PA-004).
 */
interface AuthorizationFacade
{
    public function canCrud(UserContext $u, string $module, CrudAction $a): bool;

    public function canOperate(UserContext $u, string $pipeline, string $step): bool;

    /**
     * @return array<string> Steps del pipeline donde el rol puede operar.
     */
    public function operableSteps(UserContext $u, string $pipeline): array;

    public function invalidate(int $roleId): void;
}
```

- [ ] **Step 2: Validar sintaxis**

Run: `php -l src/Authorization/AuthorizationFacade.php`
Expected: `No syntax errors detected`

### Task 1.4: Crear `DefaultAuthorizationFacade`

**Files:**
- Create: `src/Authorization/DefaultAuthorizationFacade.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\Authorization;

use App\Service\AuthorizationService;
use App\Service\PipelineAuthorizationService;
use App\ValueObject\UserContext;

/**
 * Implementación canónica del `AuthorizationFacade`. Compone los services
 * existentes (`AuthorizationService` para CRUD, `PipelineAuthorizationService`
 * para steps de pipeline) sin replicar su lógica interna.
 */
final class DefaultAuthorizationFacade implements AuthorizationFacade
{
    public function __construct(
        private readonly AuthorizationService $crud,
        private readonly PipelineAuthorizationService $pipeline,
    ) {
    }

    public function canCrud(UserContext $u, string $module, CrudAction $a): bool
    {
        return $this->crud->isAllowed($u->roleId, $u->roleName, $module, $a->value);
    }

    public function canOperate(UserContext $u, string $pipeline, string $step): bool
    {
        return $this->pipeline->canOperate($u->roleId, $pipeline, $step);
    }

    public function operableSteps(UserContext $u, string $pipeline): array
    {
        return $this->pipeline->getOperableSteps($u->roleId, $pipeline);
    }

    public function invalidate(int $roleId): void
    {
        $this->crud->invalidate($roleId);
        $this->pipeline->invalidate($roleId);
    }
}
```

- [ ] **Step 2: Validar sintaxis**

Run: `php -l src/Authorization/DefaultAuthorizationFacade.php`
Expected: `No syntax errors detected`

**Nota:** Este archivo referencia `AuthorizationService::invalidate()` y `PipelineAuthorizationService::invalidate()`. Esos métodos se añaden en Commit 2. **No** ejecutar el server entre Commit 1 y Commit 2 — el container fallará al construir el Facade. Si necesitas validar antes de Commit 2, comentar temporalmente las dos líneas dentro de `invalidate()`.

### Task 1.5: Registrar Facade en `Application::services()`

**Files:**
- Modify: `src/Application.php` (añadir use + 4 líneas en `services()`)

- [ ] **Step 1: Añadir imports**

En `src/Application.php`, debajo del bloque de `use App\Service\AuthorizationService;` (~línea 15), añadir:

```php
use App\Authorization\AuthorizationFacade;
use App\Authorization\DefaultAuthorizationFacade;
```

- [ ] **Step 2: Registrar Facade en el container**

En `src/Application.php`, dentro de `services()` en la sección `=== Auth / Authorization ===` (~línea 185-187), justo después de:

```php
$container->addShared(AuthorizationService::class);
$container->addShared(PipelineAuthorizationService::class);
```

añadir:

```php
$container->addShared(AuthorizationFacade::class, DefaultAuthorizationFacade::class)
    ->addArguments([
        AuthorizationService::class,
        PipelineAuthorizationService::class,
    ]);
```

- [ ] **Step 3: Validar sintaxis**

Run: `php -l src/Application.php`
Expected: `No syntax errors detected`

### Task 1.6: Commit 1

- [ ] **Step 1: Verificar status**

Run: `git status`
Expected: 4 archivos nuevos (`src/ValueObject/UserContext.php`, `src/Authorization/CrudAction.php`, `src/Authorization/AuthorizationFacade.php`, `src/Authorization/DefaultAuthorizationFacade.php`) + 1 modificado (`src/Application.php`).

- [ ] **Step 2: Commit**

```bash
git add src/ValueObject/UserContext.php src/Authorization/ src/Application.php
git commit -m "$(cat <<'EOF'
feat(auth): add UserContext, CrudAction, AuthorizationFacade contract

PA-004 paso 1/5 — introduce capa contractual sin tocar consumidores
existentes. `DefaultAuthorizationFacade` referencia `invalidate()` en los
services internos que se exponen como público en el siguiente commit;
no levantar el server entre commits 1 y 2.

Spec: docs/superpowers/specs/2026-05-12-pa-004-authorization-facade-design.md

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Commit 2 — Expose `invalidate()` on internal services

Mueve la invalidación de cache (hoy inline en `save*`) a un método público dedicado. El `save*` sigue funcionando idéntico (puede llamar al método nuevo o seguir con `unset` directo).

### Task 2.1: Añadir `AuthorizationService::invalidate()`

**Files:**
- Modify: `src/Service/AuthorizationService.php` (añadir método + ajustar `savePermissionsForRole`)

- [ ] **Step 1: Añadir método público**

En `src/Service/AuthorizationService.php`, justo antes del cierre de la clase (después de `savePermissionsForRole`, ~línea 151), añadir:

```php
    /**
     * Invalida la cache per-request para un rol específico. Llamado tras
     * `savePermissionsForRole` y desde `AuthorizationFacade::invalidate`.
     */
    public function invalidate(int $roleId): void
    {
        unset($this->cache[$roleId]);
    }
```

- [ ] **Step 2: Reemplazar el `unset` inline en `savePermissionsForRole`**

En el mismo archivo, ~línea 150, reemplazar:

```php
        // Clear cache for this role
        unset($this->cache[$roleId]);
```

por:

```php
        $this->invalidate($roleId);
```

- [ ] **Step 3: Validar sintaxis**

Run: `php -l src/Service/AuthorizationService.php`
Expected: `No syntax errors detected`

### Task 2.2: Añadir `PipelineAuthorizationService::invalidate()`

**Files:**
- Modify: `src/Service/PipelineAuthorizationService.php`

- [ ] **Step 1: Añadir método público**

En `src/Service/PipelineAuthorizationService.php`, antes de `private function _loadForRole` (~línea 128), añadir:

```php
    /**
     * Invalida la cache per-request para un rol específico. Llamado tras
     * `savePermissions` y desde `AuthorizationFacade::invalidate`.
     */
    public function invalidate(int $roleId): void
    {
        unset($this->cache[$roleId]);
    }
```

- [ ] **Step 2: Reemplazar el `unset` inline en `savePermissions`**

En el mismo archivo, ~línea 121, reemplazar:

```php
        unset($this->cache[$roleId]);
```

por:

```php
        $this->invalidate($roleId);
```

- [ ] **Step 3: Validar sintaxis**

Run: `php -l src/Service/PipelineAuthorizationService.php`
Expected: `No syntax errors detected`

### Task 2.3: Validación manual de Commit 1 + 2

- [ ] **Step 1: Arranque limpio del server**

Run: `php bin/cake server`
Expected: `Server running on http://0.0.0.0:8765` sin warnings ni excepciones.

Detener el server con Ctrl+C.

- [ ] **Step 2: Acceder a `/roles/edit/{id}`, modificar un checkbox, guardar**

(Con el server corriendo en otra terminal.)

Abrir `/roles/edit/2` (o cualquier rol no-admin), togglear un checkbox de permisos, click "Guardar".

Expected: redirect a `/roles` con flash de éxito. Recargar `/roles/edit/2` → el cambio persiste.

Esto valida que `savePermissionsForRole` y `savePermissions` siguen invalidando la cache correctamente vía el nuevo método `invalidate()`.

### Task 2.4: Commit 2

- [ ] **Step 1: Commit**

```bash
git add src/Service/AuthorizationService.php src/Service/PipelineAuthorizationService.php
git commit -m "$(cat <<'EOF'
feat(auth): expose invalidate() on Authorization/PipelineAuthorization services

PA-004 paso 2/5 — extrae `unset(\$this->cache[\$roleId])` de los métodos
`save*` a un método público dedicado. Habilita la delegación desde
`AuthorizationFacade::invalidate`. Comportamiento sin cambios.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Commit 3 — Migrate AppController + 9 services to Facade

Migra el AppController (el gate `_enforcePermission` + el helper `_checkPermission`) y los 9 services/policies del Grupo A al `AuthorizationFacade`. Los services del Grupo B (`RolesController`, `_setUserPermissions`) no se tocan.

### Task 3.1: Añadir helper `_userContext()` y propiedad `$authFacade` en `AppController`

**Files:**
- Modify: `src/Controller/AppController.php`

- [ ] **Step 1: Añadir imports**

En `src/Controller/AppController.php`, en el bloque de `use` (~línea 4-21), añadir:

```php
use App\Authorization\AuthorizationFacade;
use App\Authorization\CrudAction;
use App\ValueObject\UserContext;
```

- [ ] **Step 2: Añadir propiedad `$authFacade`**

En `src/Controller/AppController.php`, después de la propiedad `$pipelineAuth` (~línea 31), añadir:

```php
    protected AuthorizationFacade $authFacade;
```

**No** eliminar `$authService` ni `$pipelineAuth` todavía — `RolesController` los necesita y `_setUserPermissions` también. Quedan vivos.

- [ ] **Step 3: Resolver el Facade en `initialize()`**

En `src/Controller/AppController.php`, en `initialize()` (~línea 55-65), después de `$this->pipelineAuth = ...`, añadir:

```php
        $this->authFacade = $this->getContainer()->get(AuthorizationFacade::class);
```

- [ ] **Step 4: Añadir helper `_userContext()`**

En `src/Controller/AppController.php`, después de `_getUserRoleName` (~línea 252), añadir:

```php
    /**
     * Compone un `UserContext` desde el identity actual. Útil para pasarlo
     * a `AuthorizationFacade::can*` o a services/policies que reciben el VO.
     */
    protected function _userContext(): ?UserContext
    {
        $identity = $this->Authentication->getIdentity();
        if ($identity === null) {
            return null;
        }

        return UserContext::fromIdentity($identity->getOriginalData());
    }
```

- [ ] **Step 5: Migrar `_applyAuthAttribute` (chequeo de PipelineAction)**

En `src/Controller/AppController.php`, en el bloque del `if ($attribute instanceof PipelineAction)` (~línea 226-243), reemplazar:

```php
            $roleId = (int)$user->role_id;
            if (!$this->pipelineAuth->canOperate($roleId, $attribute->pipeline, $attribute->step)) {
```

por:

```php
            $context = UserContext::fromIdentity($user);
            if (!$this->authFacade->canOperate($context, $attribute->pipeline, $attribute->step)) {
```

- [ ] **Step 6: Migrar `_checkPermission`**

En `src/Controller/AppController.php`, en `_checkPermission` (~línea 294-305), reemplazar el cuerpo:

```php
        $identity = $this->Authentication->getIdentity();
        if (!$identity) {
            return false;
        }

        $user = $identity->getOriginalData();
        $roleName = $this->_getUserRoleName($user);

        return $this->authService->isAllowed((int)$user->role_id, $roleName, $module, $action);
```

por:

```php
        $context = $this->_userContext();
        if ($context === null) {
            return false;
        }

        return $this->authFacade->canCrud($context, $module, CrudAction::from($action));
```

**Nota:** `CrudAction::from($action)` lanza `\ValueError` si llega un string no reconocido. El `$action` viene del atributo `#[Permission(action: '...')]`, que solo acepta `'view'|'add'|'edit'|'delete'` por convención. Si llega un valor inválido es un bug que queremos que falle loud, no silencioso. **No** envolver con try/catch.

- [ ] **Step 7: Validar sintaxis**

Run: `php -l src/Controller/AppController.php`
Expected: `No syntax errors detected`

### Task 3.2: Migrar `InvoicePipelineService`

**Files:**
- Modify: `src/Service/InvoicePipelineService.php`

- [ ] **Step 1: Añadir imports**

En el bloque de `use`, añadir:

```php
use App\Authorization\AuthorizationFacade;
use App\ValueObject\UserContext;
```

- [ ] **Step 2: Reemplazar la dependencia del constructor**

En `__construct(...)`, reemplazar:

```php
        private readonly PipelineAuthorizationService $pipelineAuth,
```

por:

```php
        private readonly AuthorizationFacade $auth,
```

- [ ] **Step 3: Reemplazar los 3 call-sites**

Buscar las 3 ocurrencias y migrarlas:

- **Línea ~44** (`getVisibleStatuses`):
  ```php
  return $this->pipelineAuth->getOperableSteps(
      $roleId,
      PipelineStepConstants::PIPELINE_INVOICES,
  );
  ```
  →
  ```php
  return $this->auth->operableSteps(
      new UserContext($roleId),
      PipelineStepConstants::PIPELINE_INVOICES,
  );
  ```

- **Línea ~135 y ~203** (`canAdvance`/`denialReasonFor*` — patrón: `!$this->pipelineAuth->canOperate(...)`):
  ```php
  !$this->pipelineAuth->canOperate(
      $roleId,
      PipelineStepConstants::PIPELINE_INVOICES,
      $currentStatus,
  )
  ```
  →
  ```php
  !$this->auth->canOperate(
      new UserContext($roleId),
      PipelineStepConstants::PIPELINE_INVOICES,
      $currentStatus,
  )
  ```

  Si el contexto exacto del segundo argumento difiere (`$nextStatus`, etc.), conservarlo — solo cambia el `$roleId` → `new UserContext($roleId)` y el método `canOperate`.

- [ ] **Step 4: Quitar el use de `PipelineAuthorizationService`**

Si tras el reemplazo `PipelineAuthorizationService` ya no se referencia en el archivo, eliminar el `use App\Service\PipelineAuthorizationService;`. Confirmar con grep:

Run: `grep -n PipelineAuthorizationService src/Service/InvoicePipelineService.php`
Expected: solo aparece en el `use` (o nada). Si solo está en el `use`, eliminarlo.

- [ ] **Step 5: Validar sintaxis**

Run: `php -l src/Service/InvoicePipelineService.php`
Expected: `No syntax errors detected`

### Task 3.3: Migrar `RefundService`

**Files:**
- Modify: `src/Service/RefundService.php`

Mismo patrón que Task 3.2. Cambios:

- [ ] **Step 1: Añadir imports**

```php
use App\Authorization\AuthorizationFacade;
use App\ValueObject\UserContext;
```

- [ ] **Step 2: Reemplazar la dependencia**

El constructor actual (~línea 30-46) tiene:

```php
        ?PipelineAuthorizationService $pipelineAuth = null,
    ) {
        ...
        $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
    }
```

Reemplazar por inyección directa (sin fallback — el container siempre provee):

```php
        private readonly AuthorizationFacade $auth,
    ) {
        ...
    }
```

Y eliminar la propiedad `private PipelineAuthorizationService $pipelineAuth;` (~línea 25).

**Nota:** El fallback `?? new PipelineAuthorizationService()` corresponde al hallazgo PA-012 (severidad sugerencia). Eliminarlo aquí es resolución colateral aceptada — el container siempre tiene el Facade registrado.

- [ ] **Step 3: Reemplazar los 3 call-sites**

- **Línea ~56** (`getVisibleStatuses` o similar):
  ```php
  return $this->pipelineAuth->getOperableSteps($roleId, ...);
  ```
  →
  ```php
  return $this->auth->operableSteps(new UserContext($roleId), ...);
  ```

- **Líneas ~133 y ~367**:
  ```php
  !$this->pipelineAuth->canOperate($roleId, ...)
  ```
  →
  ```php
  !$this->auth->canOperate(new UserContext($roleId), ...)
  ```

- [ ] **Step 4: Quitar el use de `PipelineAuthorizationService`**

Run: `grep -n PipelineAuthorizationService src/Service/RefundService.php`
Expected: solo en el `use` (o nada). Si solo en `use`, eliminarlo.

- [ ] **Step 5: Validar sintaxis**

Run: `php -l src/Service/RefundService.php`
Expected: `No syntax errors detected`

### Task 3.4: Migrar `PettyCashService`

**Files:**
- Modify: `src/Service/PettyCashService.php`

Mismo patrón que Task 3.3. El servicio tiene 5 call-sites de `canOperate` (líneas ~65, 249, 482, 555, 667, 731 según el grep — usa 6 ocurrencias incluyendo `getOperableSteps`).

- [ ] **Step 1: Añadir imports**

```php
use App\Authorization\AuthorizationFacade;
use App\ValueObject\UserContext;
```

- [ ] **Step 2: Reemplazar la dependencia del constructor**

Reemplazar (~línea 35-54):

```php
        ?PipelineAuthorizationService $pipelineAuth = null,
    ) {
        ...
        $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
    }
```

por:

```php
        private readonly AuthorizationFacade $auth,
    ) {
        ...
    }
```

Eliminar la propiedad `private PipelineAuthorizationService $pipelineAuth;` (~línea 27).

- [ ] **Step 3: Reemplazar los 6 call-sites**

Cada `$this->pipelineAuth->canOperate($roleId, ...)` → `$this->auth->canOperate(new UserContext($roleId), ...)`.

Cada `$this->pipelineAuth->getOperableSteps($roleId, ...)` → `$this->auth->operableSteps(new UserContext($roleId), ...)`.

Líneas afectadas (referencia): 65, 249, 482, 555, 667, 731.

- [ ] **Step 4: Quitar el use**

Run: `grep -n PipelineAuthorizationService src/Service/PettyCashService.php`
Expected: solo en `use` (o nada). Si solo en `use`, eliminarlo.

- [ ] **Step 5: Validar sintaxis**

Run: `php -l src/Service/PettyCashService.php`
Expected: `No syntax errors detected`

### Task 3.5: Migrar `PaymentSchedulingService`

**Files:**
- Modify: `src/Service/PaymentSchedulingService.php`

Mismo patrón. 3 call-sites: líneas ~40, 66, 88.

- [ ] **Step 1: Añadir imports**

```php
use App\Authorization\AuthorizationFacade;
use App\ValueObject\UserContext;
```

- [ ] **Step 2: Reemplazar dependencia del constructor**

Reemplazar (~línea 27-32):

```php
        ?PipelineAuthorizationService $pipelineAuth = null,
    ) {
        ...
        $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
    }
```

por:

```php
        private readonly AuthorizationFacade $auth,
    ) {
        ...
    }
```

Eliminar la propiedad `private PipelineAuthorizationService $pipelineAuth;` (~línea 20).

- [ ] **Step 3: Reemplazar los 3 call-sites**

`$this->pipelineAuth->canOperate($roleId, ...)` → `$this->auth->canOperate(new UserContext($roleId), ...)`.
`$this->pipelineAuth->getOperableSteps($roleId, ...)` → `$this->auth->operableSteps(new UserContext($roleId), ...)`.

- [ ] **Step 4: Quitar el use**

Run: `grep -n PipelineAuthorizationService src/Service/PaymentSchedulingService.php`
Expected: solo en `use` o nada.

- [ ] **Step 5: Validar sintaxis**

Run: `php -l src/Service/PaymentSchedulingService.php`
Expected: `No syntax errors detected`

### Task 3.6: Migrar `NoveltyService`

**Files:**
- Modify: `src/Service/NoveltyService.php`

5 call-sites: líneas ~398, 409, 421, 438, 461.

- [ ] **Step 1: Añadir imports**

```php
use App\Authorization\AuthorizationFacade;
use App\ValueObject\UserContext;
```

- [ ] **Step 2: Reemplazar dependencia del constructor**

Reemplazar (~línea 39-50):

```php
    private PipelineAuthorizationService $pipelineAuth;
    ...
    public function __construct(
        ...
        ?PipelineAuthorizationService $pipelineAuth = null,
    ) {
        $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
    }
```

por inyección directa con `private readonly AuthorizationFacade $auth`. Eliminar la propiedad antigua.

- [ ] **Step 3: Reemplazar los 5 call-sites**

Patrón idéntico a tasks anteriores.

- [ ] **Step 4: Quitar el use**

Run: `grep -n PipelineAuthorizationService src/Service/NoveltyService.php`
Expected: solo en `use` o nada.

- [ ] **Step 5: Validar sintaxis**

Run: `php -l src/Service/NoveltyService.php`
Expected: `No syntax errors detected`

### Task 3.7: Migrar `RefundPaymentService`

**Files:**
- Modify: `src/Service/RefundPaymentService.php`

3 call-sites: líneas ~59, 193, 368.

- [ ] **Step 1: Añadir imports**

```php
use App\Authorization\AuthorizationFacade;
use App\ValueObject\UserContext;
```

- [ ] **Step 2: Reemplazar dependencia del constructor**

Reemplazar (~línea 26-36):

```php
    private PipelineAuthorizationService $pipelineAuth;
    ...
    public function __construct(
        ...
        ?PipelineAuthorizationService $pipelineAuth = null,
    ) {
        $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
    }
```

por inyección directa con `private readonly AuthorizationFacade $auth`. Eliminar la propiedad antigua.

- [ ] **Step 3: Reemplazar los 3 call-sites**

Patrón idéntico.

- [ ] **Step 4: Quitar el use**

Run: `grep -n PipelineAuthorizationService src/Service/RefundPaymentService.php`
Expected: solo en `use` o nada.

- [ ] **Step 5: Validar sintaxis**

Run: `php -l src/Service/RefundPaymentService.php`
Expected: `No syntax errors detected`

### Task 3.8: Migrar `InvoiceFieldAccessPolicy`

**Files:**
- Modify: `src/Service/InvoiceFieldAccessPolicy.php`

2 call-sites: líneas ~76, 101 (`getOperableSteps`).

- [ ] **Step 1: Añadir imports**

```php
use App\Authorization\AuthorizationFacade;
use App\ValueObject\UserContext;
```

- [ ] **Step 2: Reemplazar dependencia del constructor**

Reemplazar (~línea 55-62):

```php
    private PipelineAuthorizationService $pipelineAuth;
    ...
    public function __construct(?PipelineAuthorizationService $pipelineAuth = null)
    {
        $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
    }
```

por:

```php
    public function __construct(
        private readonly AuthorizationFacade $auth,
    ) {
    }
```

- [ ] **Step 3: Reemplazar los 2 call-sites**

`$this->pipelineAuth->getOperableSteps($roleId, ...)` → `$this->auth->operableSteps(new UserContext($roleId), ...)`.

- [ ] **Step 4: Quitar el use**

Run: `grep -n PipelineAuthorizationService src/Service/InvoiceFieldAccessPolicy.php`
Expected: solo en `use` o nada.

- [ ] **Step 5: Validar sintaxis**

Run: `php -l src/Service/InvoiceFieldAccessPolicy.php`
Expected: `No syntax errors detected`

### Task 3.9: Migrar `InvoiceTransitionValidator`

**Files:**
- Modify: `src/Service/InvoiceTransitionValidator.php`

1 call-site: línea ~98 (`canOperate`).

- [ ] **Step 1: Añadir imports**

```php
use App\Authorization\AuthorizationFacade;
use App\ValueObject\UserContext;
```

- [ ] **Step 2: Reemplazar dependencia del constructor**

En `__construct(...)` (~línea 30-40), reemplazar:

```php
        private readonly PipelineAuthorizationService $pipelineAuth,
```

por:

```php
        private readonly AuthorizationFacade $auth,
```

- [ ] **Step 3: Reemplazar el call-site (línea ~98)**

```php
$statusVisible = $this->pipelineAuth->canOperate(
    $roleId,
    ...
);
```

→

```php
$statusVisible = $this->auth->canOperate(
    new UserContext($roleId),
    ...
);
```

- [ ] **Step 4: Quitar el use**

Run: `grep -n PipelineAuthorizationService src/Service/InvoiceTransitionValidator.php`
Expected: solo en `use` o nada.

- [ ] **Step 5: Validar sintaxis**

Run: `php -l src/Service/InvoiceTransitionValidator.php`
Expected: `No syntax errors detected`

### Task 3.10: Migrar `AdvanceLegalizationActionPolicy`

**Files:**
- Modify: `src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php`

1 call-site visible (línea ~93) en el método privado `_canOperate`. Las 13 firmas públicas (`canLinkInvoices`, etc.) reciben `int $roleId` y llaman a `_canOperate($roleId, $leg->status)`.

- [ ] **Step 1: Añadir imports**

```php
use App\Authorization\AuthorizationFacade;
use App\ValueObject\UserContext;
```

- [ ] **Step 2: Reemplazar dependencia del constructor**

Reemplazar (~línea 21-24):

```php
    public function __construct(
        private PipelineAuthorizationService $pipelineAuth,
    ) {
    }
```

por:

```php
    public function __construct(
        private AuthorizationFacade $auth,
    ) {
    }
```

- [ ] **Step 3: Reemplazar el call-site dentro de `_canOperate`**

En `_canOperate` (~línea 90-95), reemplazar:

```php
return $this->pipelineAuth->canOperate(
    $roleId,
    PipelineStepConstants::PIPELINE_ADVANCE_LEGALIZATIONS,
    $status,
);
```

por:

```php
return $this->auth->canOperate(
    new UserContext($roleId),
    PipelineStepConstants::PIPELINE_ADVANCE_LEGALIZATIONS,
    $status,
);
```

(El nombre exacto de la constante puede variar — preservar el segundo argumento original.)

**Nota:** Las firmas públicas `canX(AdvanceLegalization $leg, int $roleId)` **no cambian**. La conversión a `UserContext` queda interna al Policy.

- [ ] **Step 4: Quitar el use**

Run: `grep -n PipelineAuthorizationService src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php`
Expected: solo en `use` o nada.

- [ ] **Step 5: Validar sintaxis**

Run: `php -l src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php`
Expected: `No syntax errors detected`

### Task 3.11: Actualizar DI de los services migrados

**Files:**
- Modify: `src/Application.php`

- [ ] **Step 1: Cambiar argumentos de los services migrados**

En `src/Application.php`, dentro de `services()`, reemplazar las referencias a `PipelineAuthorizationService::class` por `AuthorizationFacade::class` en los siguientes bloques:

- `InvoiceFieldAccessPolicy` (~línea 209):
  ```php
  ->addArgument(PipelineAuthorizationService::class);
  ```
  →
  ```php
  ->addArgument(AuthorizationFacade::class);
  ```

- `InvoiceTransitionValidator` (~línea 211-217) — en el array `addArguments`, reemplazar `PipelineAuthorizationService::class` por `AuthorizationFacade::class`.

- `AdvanceLegalizationActionPolicy` (~línea 234-235):
  ```php
  ->addArgument(PipelineAuthorizationService::class);
  ```
  →
  ```php
  ->addArgument(AuthorizationFacade::class);
  ```

- `InvoicePipelineService` (~línea 236-247) — en el array `addArguments`, reemplazar `PipelineAuthorizationService::class` por `AuthorizationFacade::class` (último argumento).

- `NoveltyService` (~línea 319-320):
  ```php
  ->addArgument(PipelineAuthorizationService::class);
  ```
  →
  ```php
  ->addArgument(AuthorizationFacade::class);
  ```

- `PettyCashService` (~línea 327-331) — en el array `addArguments`, reemplazar `PipelineAuthorizationService::class` por `AuthorizationFacade::class`.

- `RefundService` (~línea 333-337) — idem.

**Servicios que NO cambian en DI:**
- `PaymentSchedulingService` actualmente no inyecta `PipelineAuthorizationService` en `Application.php` (depende solo de `InvoicePaymentService` en el container). Como ahora el constructor sí requiere `AuthorizationFacade`, hay que **añadir** el argumento. Cambiar (~línea 339):
  ```php
  $container->addShared(PaymentSchedulingService::class)
      ->addArgument(InvoicePaymentService::class);
  ```
  →
  ```php
  $container->addShared(PaymentSchedulingService::class)
      ->addArguments([
          InvoicePaymentService::class,
          AuthorizationFacade::class,
      ]);
  ```
  Confirmar primero el orden de parámetros del constructor de `PaymentSchedulingService` y ajustar para que coincida.

- `RefundPaymentService` actualmente se registra sin argumentos (~línea 338):
  ```php
  $container->addShared(RefundPaymentService::class);
  ```
  Cambiar a:
  ```php
  $container->addShared(RefundPaymentService::class)
      ->addArgument(AuthorizationFacade::class);
  ```
  Si el constructor de `RefundPaymentService` tiene más argumentos requeridos (no nullable), añadirlos en `addArguments([...])`.

- [ ] **Step 2: Verificar que ningún service migrado siga inyectando `PipelineAuthorizationService` por DI**

Run: `grep -n "PipelineAuthorizationService::class" src/Application.php`
Expected: solo aparece en la línea de registro del propio service (`$container->addShared(PipelineAuthorizationService::class);`).

- [ ] **Step 3: Validar sintaxis**

Run: `php -l src/Application.php`
Expected: `No syntax errors detected`

### Task 3.12: Validación manual de Commit 3

- [ ] **Step 1: Arranque del server sin errores de DI**

Run: `php bin/cake server`
Expected: server arranca sin excepciones. Si el container falla al construir algún service, revisar Task 3.11.

- [ ] **Step 2: Smoke test — login y dashboard**

Login como cualquier rol → dashboard carga → sidebar muestra los módulos esperados para el rol.

- [ ] **Step 3: Smoke test — pipeline de facturas**

Como rol con permiso de pipeline en `aprobacion` (Registro/Revisión), abrir una factura en `aprobacion`, click "Avanzar".

Expected: avanza a `contabilidad` (o muestra denial correcto si faltan campos). El gate del atributo `#[PipelineAction]` ahora pasa por el Facade.

- [ ] **Step 4: Smoke test — gate CRUD**

Como rol sin `can_view` en `users`, navegar a `/users`.

Expected: 403 ForbiddenException. El gate del atributo `#[Permission(action: 'view')]` ahora pasa por `_checkPermission` → Facade.

- [ ] **Step 5: Smoke test — admin bypass**

Login como Administrador → `/users` y `/roles`.

Expected: permitido (el `roleName` viaja en `UserContext` y `AuthorizationService::isAllowed` lo consulta).

- [ ] **Step 6: `composer cs-check`**

Run: `composer cs-check`
Expected: sin nuevas violaciones de PSR-12.

### Task 3.13: Commit 3

- [ ] **Step 1: Commit**

```bash
git add src/Controller/AppController.php src/Service/ src/Application.php
git commit -m "$(cat <<'EOF'
refactor(auth): migrate AppController and services to AuthorizationFacade

PA-004 paso 3/5 — AppController (gate + helpers) y 9 services/policies
migran a `AuthorizationFacade`. Cada call-site `pipelineAuth->canOperate`
o `pipelineAuth->getOperableSteps` queda como `auth->canOperate(new
UserContext(\$roleId), ...)` / `auth->operableSteps(...)`. Resolución
colateral del fallback `?? new PipelineAuthorizationService()` (PA-012)
en 5 services.

`RolesController` y `AppController::_setUserPermissions` mantienen
dependencia directa a `AuthorizationService` / `PipelineAuthorizationService`
para matrices/save (fuera del contrato por decisión del audit).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Commit 4 — Migrate pipeline controllers

Migra los 7 controllers que llaman `$this->pipelineAuth->canOperate(...)` o `$this->authService->isAllowed(...)` inline (sin contar `AppController` y `RolesController`).

### Task 4.1: Migrar `InvoicePaymentsController`

**Files:**
- Modify: `src/Controller/InvoicePaymentsController.php`

6 call-sites: líneas ~58, 104, 149, 189, 226, 270.

- [ ] **Step 1: Añadir import**

```php
use App\Authorization\AuthorizationFacade;
```

- [ ] **Step 2: Reemplazar propiedad y resolución**

En la declaración de propiedades (~línea 15), reemplazar:

```php
    private PipelineAuthorizationService $pipelineAuth;
```

por:

```php
    private AuthorizationFacade $authFacade;
```

En `initialize()` (~línea 24), reemplazar:

```php
        $this->pipelineAuth = $this->getContainer()->get(PipelineAuthorizationService::class);
```

por:

```php
        $this->authFacade = $this->getContainer()->get(AuthorizationFacade::class);
```

- [ ] **Step 3: Reemplazar los 6 call-sites**

Cada bloque del tipo:

```php
$this->pipelineAuth->canOperate(
    $roleId,
    PIPELINE,
    STEP,
)
```

→

```php
$this->authFacade->canOperate(
    $this->_userContext(),
    PIPELINE,
    STEP,
)
```

Algunos call-sites podrían tener `$user->role_id` u otra forma de obtener el roleId. Si el contexto ya tiene un `$user` cargado, usar `UserContext::fromIdentity($user)`. Si el contexto solo tiene `$roleId`, usar `new UserContext($roleId)`. El helper `$this->_userContext()` aplica para chequeos del usuario actual.

- [ ] **Step 4: Quitar el use innecesario**

Run: `grep -n PipelineAuthorizationService src/Controller/InvoicePaymentsController.php`
Expected: solo en `use` o nada. Si solo en `use`, eliminarlo.

- [ ] **Step 5: Validar sintaxis**

Run: `php -l src/Controller/InvoicePaymentsController.php`
Expected: `No syntax errors detected`

### Task 4.2: Migrar `InvoicesController`

**Files:**
- Modify: `src/Controller/InvoicesController.php`

3 call-sites de `canOperate`: líneas ~364, 369, 374 (variables `$canConfirmPayment`, `$canRegisterPayment`, `$canAuthorizePayment` para la vista).

- [ ] **Step 1: Añadir import**

```php
use App\Authorization\AuthorizationFacade;
```

- [ ] **Step 2: Reemplazar propiedad y resolución**

En la declaración (~línea 52):

```php
    private PipelineAuthorizationService $pipelineAuth;
```

→

```php
    private AuthorizationFacade $authFacade;
```

En `initialize()` (~línea 63):

```php
        $this->pipelineAuth = $container->get(PipelineAuthorizationService::class);
```

→

```php
        $this->authFacade = $container->get(AuthorizationFacade::class);
```

- [ ] **Step 3: Reemplazar los 3 call-sites (líneas ~364, 369, 374)**

Cada `$this->pipelineAuth->canOperate($roleId, PIPELINE, STEP)` →
`$this->authFacade->canOperate($this->_userContext(), PIPELINE, STEP)`.

Si en el contexto local no hay un `_userContext()` (porque el helper viene de `AppController`), está disponible vía herencia. Si los 3 call-sites están dentro del mismo método y comparten el contexto, calcular `$context = $this->_userContext()` una vez al inicio del método y reusarlo.

- [ ] **Step 4: Quitar el use innecesario**

Run: `grep -n PipelineAuthorizationService src/Controller/InvoicesController.php`
Expected: solo en `use` o nada.

- [ ] **Step 5: Validar sintaxis**

Run: `php -l src/Controller/InvoicesController.php`
Expected: `No syntax errors detected`

### Task 4.3: Migrar `RefundsController`

**Files:**
- Modify: `src/Controller/RefundsController.php`

5 call-sites: líneas ~61, 461, 466, 471, 606.

- [ ] **Step 1: Añadir import**

```php
use App\Authorization\AuthorizationFacade;
```

- [ ] **Step 2: Reemplazar propiedad y resolución**

(~línea 32 y ~44) — mismo patrón que tasks anteriores.

- [ ] **Step 3: Reemplazar los 5 call-sites**

Mismo patrón. El call-site de línea ~61 (`return $this->pipelineAuth->canOperate(...)` dentro de un helper privado) puede recibir `$roleId` por parámetro — preservar la firma del helper, solo cambiar el cuerpo.

- [ ] **Step 4: Quitar el use innecesario**

Run: `grep -n PipelineAuthorizationService src/Controller/RefundsController.php`
Expected: solo en `use` o nada.

- [ ] **Step 5: Validar sintaxis**

Run: `php -l src/Controller/RefundsController.php`
Expected: `No syntax errors detected`

### Task 4.4: Migrar `PettyCashRecordsController`

**Files:**
- Modify: `src/Controller/PettyCashRecordsController.php`

4 call-sites: líneas ~314, 319, 324, 474.

- [ ] **Step 1: Añadir import**

```php
use App\Authorization\AuthorizationFacade;
```

- [ ] **Step 2: Reemplazar propiedad y resolución**

(~línea 32 y ~43) — mismo patrón.

- [ ] **Step 3: Reemplazar los 4 call-sites**

Mismo patrón. Los call-sites 314/319/324 son contiguos (construcción de variables para la vista) — extraer `$context = $this->_userContext()` y reusar.

- [ ] **Step 4: Quitar el use innecesario**

Run: `grep -n PipelineAuthorizationService src/Controller/PettyCashRecordsController.php`
Expected: solo en `use` o nada.

- [ ] **Step 5: Validar sintaxis**

Run: `php -l src/Controller/PettyCashRecordsController.php`
Expected: `No syntax errors detected`

### Task 4.5: Migrar `PaymentSchedulingsController`

**Files:**
- Modify: `src/Controller/PaymentSchedulingsController.php`

4 call-sites: líneas ~176, 182, 283, 342.

- [ ] **Step 1: Añadir import**

```php
use App\Authorization\AuthorizationFacade;
```

- [ ] **Step 2: Reemplazar propiedad y resolución**

(~línea 34 y ~43) — mismo patrón.

- [ ] **Step 3: Reemplazar los 4 call-sites**

Mismo patrón.

- [ ] **Step 4: Quitar el use innecesario**

Run: `grep -n PipelineAuthorizationService src/Controller/PaymentSchedulingsController.php`
Expected: solo en `use` o nada.

- [ ] **Step 5: Validar sintaxis**

Run: `php -l src/Controller/PaymentSchedulingsController.php`
Expected: `No syntax errors detected`

### Task 4.6: Migrar `LiquidationDocPaymentsController`

**Files:**
- Modify: `src/Controller/LiquidationDocPaymentsController.php`

4 call-sites: líneas ~64, 102, 136, 170.

- [ ] **Step 1: Añadir import**

```php
use App\Authorization\AuthorizationFacade;
```

- [ ] **Step 2: Reemplazar propiedad y resolución**

(~línea 16 y ~25) — mismo patrón.

- [ ] **Step 3: Reemplazar los 4 call-sites**

Mismo patrón.

- [ ] **Step 4: Quitar el use innecesario**

Run: `grep -n PipelineAuthorizationService src/Controller/LiquidationDocPaymentsController.php`
Expected: solo en `use` o nada.

- [ ] **Step 5: Validar sintaxis**

Run: `php -l src/Controller/LiquidationDocPaymentsController.php`
Expected: `No syntax errors detected`

### Task 4.7: Migrar `NoveltyLiquidationDocsController`

**Files:**
- Modify: `src/Controller/NoveltyLiquidationDocsController.php`

3 call-sites: líneas ~236, 241, 246 (variables `$canOpTesoreria`, `$canOpAutPago`, `$canConfirmPayment`).

- [ ] **Step 1: Añadir import**

```php
use App\Authorization\AuthorizationFacade;
```

- [ ] **Step 2: Reemplazar propiedad y resolución**

(~línea 39 y ~52) — mismo patrón.

- [ ] **Step 3: Reemplazar los 3 call-sites**

Mismo patrón. Como son contiguos, extraer `$context = $this->_userContext()` y reusar.

- [ ] **Step 4: Quitar el use innecesario**

Run: `grep -n PipelineAuthorizationService src/Controller/NoveltyLiquidationDocsController.php`
Expected: solo en `use` o nada.

- [ ] **Step 5: Validar sintaxis**

Run: `php -l src/Controller/NoveltyLiquidationDocsController.php`
Expected: `No syntax errors detected`

### Task 4.8: Migrar `EmailLogsController`

**Files:**
- Modify: `src/Controller/EmailLogsController.php`

2 call-sites de `$this->authService->isAllowed(...)`: líneas ~142, 146 (chequeos CRUD inline).

- [ ] **Step 1: Añadir imports**

```php
use App\Authorization\AuthorizationFacade;
use App\Authorization\CrudAction;
use App\ValueObject\UserContext;
```

- [ ] **Step 2: Reemplazar los 2 call-sites**

Línea ~142:

```php
return $this->authService->isAllowed($roleId, $roleName, 'invoices', 'edit');
```

→

```php
return $this->authFacade->canCrud(
    new UserContext($roleId, $roleName),
    'invoices',
    CrudAction::Edit,
);
```

Línea ~146:

```php
return $this->authService->isAllowed($roleId, $roleName, 'employee_novelties', 'edit');
```

→

```php
return $this->authFacade->canCrud(
    new UserContext($roleId, $roleName),
    'employee_novelties',
    CrudAction::Edit,
);
```

`$this->authFacade` viene heredado de `AppController` (añadido en Task 3.1). `$this->authService` también sigue disponible (`AppController` no lo eliminó) — pero **no** lo usamos aquí porque ya tenemos el Facade.

- [ ] **Step 3: Validar sintaxis**

Run: `php -l src/Controller/EmailLogsController.php`
Expected: `No syntax errors detected`

### Task 4.9: Validación manual de Commit 4

- [ ] **Step 1: Arranque del server**

Run: `php bin/cake server`
Expected: arranca limpio.

- [ ] **Step 2: Flow E2E factura por rol**

Con Tesorería:
1. Abrir una factura en `tesoreria`.
2. Verificar que los botones de "Registrar Pago" / "Autorizar" / "Confirmar Pago" aparecen correctamente (variables `$canRegisterPayment` etc. ahora vienen del Facade).
3. Avanzar la factura.

Esperado: comportamiento idéntico al baseline pre-refactor.

- [ ] **Step 3: Flow E2E reintegro**

Con un rol con permiso en alguno de los pasos de reintegros, navegar al detalle de un reintegro en `tesoreria`, verificar que los botones de pago aparecen, registrar un pago.

Esperado: idéntico al baseline.

- [ ] **Step 4: Flow E2E caja menor**

Mismo patrón con caja menor.

- [ ] **Step 5: Flow E2E programación de pagos**

Mismo patrón.

- [ ] **Step 6: Email logs retry**

Como rol con permiso de `invoices.edit`, en `/email-logs` intentar reenviar un email log de un invoice fallido. Esperado: permitido.

Como rol sin permiso, idem. Esperado: denegado.

- [ ] **Step 7: `composer cs-check`**

Run: `composer cs-check`
Expected: sin nuevas violaciones de PSR-12.

### Task 4.10: Commit 4

- [ ] **Step 1: Commit**

```bash
git add src/Controller/
git commit -m "$(cat <<'EOF'
refactor(auth): migrate pipeline controllers to AuthorizationFacade

PA-004 paso 4/5 — 7 controllers (InvoicePayments, Invoices, Refunds,
PettyCashRecords, PaymentSchedulings, LiquidationDocPayments,
NoveltyLiquidationDocs) más EmailLogs migran sus call-sites inline de
`pipelineAuth->canOperate` y `authService->isAllowed` a
`authFacade->canOperate` / `canCrud`.

`AppController::\$authService` y `\$pipelineAuth` se conservan como
propiedades heredadas porque `RolesController` y `_setUserPermissions`
todavía los consumen para matrices/save.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Commit 5 — Document direct-injection scope

Documenta que las dependencias directas a los services internos son legales solo en `RolesController` y `AppController::_setUserPermissions`. El resto del código debe depender de `AuthorizationFacade`.

### Task 5.1: Docblock `@internal` en los services

**Files:**
- Modify: `src/Service/AuthorizationService.php`
- Modify: `src/Service/PipelineAuthorizationService.php`

- [ ] **Step 1: Añadir docblock a `AuthorizationService`**

En `src/Service/AuthorizationService.php`, justo encima de `class AuthorizationService` (~línea 9), reemplazar el comentario existente (si lo hay — ahora no tiene docblock) por:

```php
/**
 * Servicio CRUD de permisos. Consulta directa a `permissions` con cache
 * per-request.
 *
 * @internal Depender de `App\Authorization\AuthorizationFacade` en su lugar.
 * Esta clase concreta solo debe inyectarse en `RolesController` y
 * `AppController::_setUserPermissions` (matrices y save quedan fuera del
 * contrato del Facade — ver audit PA-004).
 */
```

- [ ] **Step 2: Añadir docblock a `PipelineAuthorizationService`**

En `src/Service/PipelineAuthorizationService.php`, reemplazar el docblock existente de la clase (~línea 9-15) por:

```php
/**
 * Resuelve si un rol puede operar (avanzar, regresar, editar campos, ver
 * sección) en un paso específico de un pipeline. Cache per-request.
 *
 * @internal Depender de `App\Authorization\AuthorizationFacade` en su lugar.
 * Esta clase concreta solo debe inyectarse en `RolesController` y
 * `AppController::_setUserPermissions` (matrices y save quedan fuera del
 * contrato del Facade — ver audit PA-004).
 */
```

- [ ] **Step 3: Validar sintaxis**

Run: `php -l src/Service/AuthorizationService.php && php -l src/Service/PipelineAuthorizationService.php`
Expected: ambos `No syntax errors detected`.

### Task 5.2: Comentario en `RolesController` y `_setUserPermissions`

**Files:**
- Modify: `src/Controller/RolesController.php`
- Modify: `src/Controller/AppController.php`

- [ ] **Step 1: Comentario en `RolesController::initialize`**

En `src/Controller/RolesController.php`, encima de la línea de resolución de `$this->pipelineAuth` (~línea 23), añadir:

```php
        // PA-004: dependencia directa a PipelineAuthorizationService legal aquí
        // porque RolesController consume matrices y save*, que quedan fuera del
        // contrato de AuthorizationFacade. El resto del código usa el Facade.
```

- [ ] **Step 2: Comentario en `AppController::_setUserPermissions`**

En `src/Controller/AppController.php`, encima de la línea (~131) `$perms = $this->authService->getPermissionsForRoleAsMatrix(...)`, añadir:

```php
        // PA-004: uso directo de AuthorizationService legal aquí porque
        // getPermissionsForRoleAsMatrix queda fuera del contrato de
        // AuthorizationFacade (matriz para sidebar). El resto del gate
        // pasa por $this->authFacade.
```

- [ ] **Step 3: Validar sintaxis**

Run: `php -l src/Controller/RolesController.php && php -l src/Controller/AppController.php`
Expected: ambos `No syntax errors detected`.

### Task 5.3: Actualizar `docs/audits/permissions-audit-2026-05-11.md`

**Files:**
- Modify: `docs/audits/permissions-audit-2026-05-11.md`

- [ ] **Step 1: Marcar PA-004 como resuelto en la tabla de estado**

En `docs/audits/permissions-audit-2026-05-11.md`, línea 42 (la fila de PA-004 en "Estado de remediación"), reemplazar:

```
| PA-004 | 🟠 Major | `AuthorizationService` y `PipelineAuthorizationService` duplican shape (cache, matrix, save, isAllowed/canOperate) sin contrato común | ⏳ Pendiente | — |
```

por:

```
| PA-004 | 🟠 Major | `AuthorizationService` y `PipelineAuthorizationService` duplican shape (cache, matrix, save, isAllowed/canOperate) sin contrato común | ✅ Resuelto | commits `<SHA1>..<SHA5>` (2026-05-12) |
```

(Reemplazar `<SHA1>..<SHA5>` con los SHAs reales de los 5 commits — se obtienen con `git log --oneline -n 5` tras hacer el commit 5.)

- [ ] **Step 2: Añadir nota de cierre en la sección PA-004**

En el archivo, después del header `## PA-004 — Sin abstracción común entre los dos servicios 🟠` (~línea 171), antes de "**Ubicación:**", añadir:

```markdown
> **Cierre:** commits `<SHA1>..<SHA5>` (2026-05-12) introdujeron `AuthorizationFacade` + `UserContext` VO + `CrudAction` enum. `DefaultAuthorizationFacade` compone `AuthorizationService` (CRUD) + `PipelineAuthorizationService` (pipeline) sin replicar lógica. 19 archivos operativos migrados al Facade; `RolesController` y `AppController::_setUserPermissions` conservan dependencia directa a los services internos (matrices y save quedan fuera del contrato por decisión del audit). Resolución colateral del fallback `?? new PipelineAuthorizationService()` (PA-012) en 5 services (RefundService, NoveltyService, PaymentSchedulingService, PettyCashService, RefundPaymentService, InvoiceFieldAccessPolicy).

> Validación manual: server arranca, save de `/roles/edit/{id}` invalida cache, flow E2E completo en facturas/reintegros/caja menor/programación de pagos sin regresiones, admin bypass acotado intacto, `composer cs-check` limpio.

```

Plan: `docs/superpowers/plans/2026-05-12-pa-004-authorization-facade.md`

### Task 5.4: Commit 5

- [ ] **Step 1: Commit**

```bash
git add src/Service/AuthorizationService.php src/Service/PipelineAuthorizationService.php src/Controller/RolesController.php src/Controller/AppController.php docs/audits/permissions-audit-2026-05-11.md
git commit -m "$(cat <<'EOF'
chore(auth): document direct-injection scope and close PA-004

PA-004 paso 5/5 — docblocks `@internal` en AuthorizationService y
PipelineAuthorizationService delimitan que la inyección directa solo
es legal en RolesController y AppController::_setUserPermissions.
Actualiza el documento de auditoría para marcar PA-004 como resuelto.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Validación final end-to-end

Tras los 5 commits, ejecutar los 10 pasos de validación manual del spec antes de mergear a `main`:

- [ ] **1. Sidebar por rol** — login como cada rol (Admin, Contabilidad, Tesorería, Contador, Registro/Revisión, Auxiliar de Personal, Asistente de Personal, Coordinador). Sidebar idéntico al baseline.

- [ ] **2. Matriz de roles** — `/roles/view/{id}` para cada rol no-admin. Matrix CRUD + pipeline idénticas.

- [ ] **3. Save invalida cache** — `/roles/edit/{id}` → togglear checkbox → guardar → cambio persiste sin reinicio del server.

- [ ] **4. Flow E2E factura por rol con permiso** — Crear → `aprobacion` → `contabilidad` → `tesoreria` → registrar pago → `autorizacion_pago` → autorizar → `verificacion_pago` → `pagada`. Cada paso permitido idéntico al baseline.

- [ ] **5. Flow E2E factura por rol sin permiso** — 403 / denial idéntico al baseline.

- [ ] **6. Otros dominios pipeline** — avance+regreso en: reintegro, caja menor, anticipo (legalización), novedad, programación de pago. Idéntico para todos los roles probados.

- [ ] **7. Admin bypass acotado** — no-admin sin `users.can_view` → `/users` → 403. Admin → `/users` y `/roles` → permitido. PA-007 sigue funcionando igual.

- [ ] **8. Atributos `#[PipelineAction]`** — verificar que advanceStatus, regressStatus, authorizePayment, confirmPayment, rejectPayment, registerPayment, markSigned, markExact siguen leyendo `pipeline_permissions` (no caen al gate CRUD).

- [ ] **9. `bin/cake server` arranca limpio** — sin warnings ni excepciones de DI.

- [ ] **10. `composer cs-check`** — sin nuevas violaciones de PSR-12.

Si los 10 pasos pasan, abrir la PR.

---

## Notas para el implementador

1. **`UserContext` con `roleName=''`** — En services que solo hacen `canOperate` (no `canCrud`), construir `new UserContext($roleId)` sin name. El name solo se consulta en `canCrud` para el admin bypass; pasar `''` es seguro y explícito.

2. **`UserContext::fromIdentity($user)` vs `new UserContext(...)`** — En controllers que tienen el `$user` entity disponible (vía `$this->Authentication->getIdentity()->getOriginalData()`), usar `UserContext::fromIdentity($user)` para que el name viaje correctamente y el admin bypass funcione. En services que reciben `int $roleId` por parámetro (sin name disponible), usar `new UserContext($roleId)`.

3. **El helper `$this->_userContext()`** vive en `AppController` y devuelve `?UserContext`. Si el método llamador requiere autenticación (que es el caso normal tras `beforeFilter`), el `null` no debería darse — pero hacer `if ($context === null) return false;` cuando se use en checks externos por seguridad.

4. **Orden de los commits no es opcional.** El Commit 1 deja el código en estado donde `DefaultAuthorizationFacade::invalidate()` referencia métodos que no existen aún. **No arrancar el server entre Commit 1 y Commit 2**. Si necesitas validar Commit 1 solo, las dos líneas en `invalidate()` pueden comentarse temporalmente — pero el flujo normal es hacer Commits 1 y 2 sin pausa.

5. **Tests no aplican** (política del proyecto). Cualquier validación es manual o estática (`php -l`, `composer cs-check`).

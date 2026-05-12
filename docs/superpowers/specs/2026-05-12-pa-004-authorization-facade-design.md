# PA-004 — AuthorizationFacade unificada

**Fecha:** 2026-05-12
**Auditoría origen:** `docs/audits/permissions-audit-2026-05-11.md` (hallazgo PA-004, severidad 🟠 Major)
**Estado actual:** ⏳ Pendiente
**Severidad:** Major (DX alto, esfuerzo M)
**Predecesor:** PA-001, PA-002, PA-003, PA-005, PA-006, PA-010 (todos ✅ resueltos)
**Sucesor planeado:** PA-011 (migración a Modelo A Policy uniforme — depende del Facade)

---

## Contexto

`AuthorizationService` y `PipelineAuthorizationService` duplican shape sin compartir contrato:

- ambos guardan `private array $cache = []` per-request;
- ambos exponen `getXxxMatrix(roleId)` para alimentar la UI de `roles/edit`;
- ambos exponen `save*(roleId, $data)` con la misma estructura POST;
- ambos exponen un gate de chequeo (`isAllowed` / `canOperate`);
- ambos invalidan cache con `unset($this->cache[$roleId])` dentro del `save*`.

Si surge una tercera dimensión (p. ej. permisos por centro de operación), nace un tercer service paralelo con el mismo patrón duplicado. Hoy los controllers/services dependen directamente de la clase concreta, no de una abstracción.

Inventario actual (post resolución de PA-002/PA-003): **19 archivos operativos** llaman a alguno de los dos services (18 al gate + `RolesController` a matrices/save), con ~62 call-sites en total entre `pipelineAuth->canOperate`, `auth->isAllowed`, matrices y save. `AppController` cae en ambos grupos (`_enforcePermission` migra al Facade, `_setUserPermissions` se queda con dependencia directa a `AuthorizationService`).

## Objetivo

Introducir un contrato común `AuthorizationFacade` que unifique los gateways de chequeo. La duplicación de cache, matrices y save queda como detalle de implementación de los services existentes — **no fuga al resto del código**.

Resultado esperado:

- Una sola fachada inyectable para todos los controllers/services que solo necesitan chequear permisos.
- Los services concretos (`AuthorizationService`, `PipelineAuthorizationService`) siguen existiendo y siendo inyectables solo en `RolesController` y `AppController::_setUserPermissions`, los dos lugares que sí consumen matrices / save.
- Base preparada para PA-011 (las 5 Policies nuevas de Refund/PettyCash/Invoice/Novelty/PaymentScheduling nacerán sobre el contrato del Facade).

## No-objetivos

- **No** unificar `getPermissionsForRoleAsMatrix` ni `savePermissionsForRole` dentro del contrato. El audit es explícito: "la duplicación de cache y `*Matrix`/`save*` queda como detalle de implementación".
- **No** eliminar `AuthorizationService` ni `PipelineAuthorizationService`. Siguen vivos como dependencias internas del Facade y como dependencias directas de `RolesController` / `_setUserPermissions`.
- **No** tocar PA-007 (admin bypass duplicado). `UserContext` lleva `roleName` para que `canCrud` siga funcionando con el bypass actual; cuando PA-007 caiga se elimina el campo.
- **No** introducir un modelo de actor más amplio que `roleId` + `roleName`. Si en el futuro hace falta `userId` / `tenantId`, se añaden al value object entonces.

---

## Diseño

### Capa nueva — 4 archivos

**`src/ValueObject/UserContext.php`** (~20 LoC)

```php
namespace App\ValueObject;

final readonly class UserContext
{
    public function __construct(
        public int $roleId,
        public string $roleName,
    ) {}

    public static function fromIdentity(\ArrayAccess $identity): self
    {
        return new self(
            roleId: (int)$identity['role_id'],
            roleName: (string)($identity['role']['name'] ?? ''),
        );
    }
}
```

`roleName` queda en el contexto porque `AuthorizationService::isAllowed` lo consulta para el admin bypass (`ADMIN_BYPASS_MODULES = ['users', 'roles']`). Cuando PA-007 caiga se elimina del VO en una PR aparte.

**`src/Authorization/CrudAction.php`** (~10 LoC)

```php
namespace App\Authorization;

enum CrudAction: string
{
    case View = 'view';
    case Add = 'add';
    case Edit = 'edit';
    case Delete = 'delete';
}
```

El atributo `#[Permission(action: 'view')]` sigue aceptando string en esta PR — el enum es para el contrato del Facade. La migración del atributo a enum es un cleanup opcional que **no entra en este spec**.

**`src/Authorization/AuthorizationFacade.php`** (interfaz, ~20 LoC)

```php
namespace App\Authorization;

use App\ValueObject\UserContext;

interface AuthorizationFacade
{
    public function canCrud(UserContext $u, string $module, CrudAction $a): bool;
    public function canOperate(UserContext $u, string $pipeline, string $step): bool;

    /** @return array<string> Steps del pipeline donde el rol puede operar. */
    public function operableSteps(UserContext $u, string $pipeline): array;

    public function invalidate(int $roleId): void;
}
```

`invalidate` recibe `int $roleId` (no `UserContext`) porque se llama desde `savePermissions*` cuando se modifica un rol arbitrario, no el del usuario actual. Decisión explícita del audit.

**`src/Authorization/DefaultAuthorizationFacade.php`** (~50 LoC)

```php
namespace App\Authorization;

use App\Service\AuthorizationService;
use App\Service\PipelineAuthorizationService;
use App\ValueObject\UserContext;

final class DefaultAuthorizationFacade implements AuthorizationFacade
{
    public function __construct(
        private readonly AuthorizationService $crud,
        private readonly PipelineAuthorizationService $pipeline,
    ) {}

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

### Cambios en services existentes

**`AuthorizationService`**: exponer método público `invalidate(int $roleId): void` que hace `unset($this->cache[$roleId])`. El `savePermissionsForRole` actual sigue invalidando inline (puede llamar al nuevo método o seguir con el `unset` directo — neutro).

**`PipelineAuthorizationService`**: idem (`invalidate(int $roleId): void`).

Ambos services añaden docblock `@internal` indicando que las dependencias directas a la clase concreta solo están permitidas en `RolesController` y `AppController::_setUserPermissions`. El resto del código depende de `AuthorizationFacade`.

### Helper en `AppController`

```php
protected function _userContext(): ?UserContext
{
    $identity = $this->Authentication?->getIdentity();

    return $identity ? UserContext::fromIdentity($identity) : null;
}
```

Disponible para `_enforcePermission` y para cualquier controller que necesite componer el contexto.

### Migración de call-sites — 3 grupos

**Grupo A — Migran a `AuthorizationFacade` (gateways de chequeo):**

| Archivo | Cambio |
|---|---|
| `AppController::_enforcePermission` | Lee atributos `#[Permission]` / `#[PipelineAction]` y llama a `$this->authFacade->canCrud(...)` o `canOperate(...)`. |
| `InvoicePipelineService` | Constructor `?PipelineAuthorizationService` → `?AuthorizationFacade`. Reemplazo de `$this->pipelineAuth->canOperate($roleId, ...)` por `$this->auth->canOperate($userContext, ...)`. |
| `RefundService` | Idem. |
| `PettyCashService` | Idem. |
| `PaymentSchedulingService` | Idem. |
| `NoveltyService` | Idem. |
| `RefundPaymentService` | Idem. |
| `InvoiceFieldAccessPolicy` | Idem. |
| `InvoiceTransitionValidator` | Idem. |
| `AdvanceLegalizationActionPolicy` | Idem (ya está en Modelo A, solo cambia la dependencia inyectada). |
| `InvoicesController` | `$this->pipelineAuth->canOperate(...)` inline → `$this->authFacade->canOperate($this->_userContext(), ...)`. |
| `InvoicePaymentsController` | Idem. |
| `RefundsController` | Idem. |
| `PettyCashRecordsController` | Idem. |
| `PaymentSchedulingsController` | Idem. |
| `LiquidationDocPaymentsController` | Idem. |
| `NoveltyLiquidationDocsController` | Idem. |
| `EmailLogsController` | Idem. |

**Grupo B — Mantienen dependencia directa a services internos:**

| Archivo | Justificación |
|---|---|
| `RolesController` (`view` / `add` / `edit` / POST save) | Consume `getPermissionsForRoleAsMatrix`, `getPermissionsMatrix`, `savePermissionsForRole`, `savePermissions`. Matrices y save **fuera del contrato** del Facade por decisión del audit. |
| `AppController::_setUserPermissions` (carga del sidebar) | Usa `getPermissionsForRoleAsMatrix` para construir el menú. Idem. |

**Grupo C — Sin cambios funcionales:**

- `Application.php` — solo añade una línea de DI registry.
- `AdvanceConstants.php` — referencias estáticas a strings, no toca services.
- `Attribute/Permission.php`, `Attribute/PipelineAction.php` — atributos puros.

### Container DI (`Application::services(ContainerInterface $container)`)

```php
$container->add(AuthorizationService::class)->setShared(true);
$container->add(PipelineAuthorizationService::class)->setShared(true);
$container->add(AuthorizationFacade::class, DefaultAuthorizationFacade::class)
    ->addArguments([AuthorizationService::class, PipelineAuthorizationService::class])
    ->setShared(true);
```

Los services concretos se registran como compartidos (per-request) para que el Facade y los consumers directos de matrices (`RolesController`) compartan la misma instancia — misma cache.

---

## Estrategia de commits — 1 PR, 5 commits

| # | Commit | Archivos | LoC aprox |
|---|---|---|---|
| 1 | `feat(auth): add UserContext, CrudAction, AuthorizationFacade contract` | 4 archivos nuevos + 1 línea DI | +90 |
| 2 | `feat(auth): expose invalidate() on Authorization/PipelineAuthorization services` | 2 archivos editados + docblock `@internal` | +20 |
| 3 | `refactor(auth): migrate AppController and services to AuthorizationFacade` | `AppController` + 9 services/policies | ~10 archivos editados |
| 4 | `refactor(auth): migrate pipeline controllers to AuthorizationFacade` | 8 controllers | ~8 archivos editados |
| 5 | `chore(auth): document direct-injection scope` | docblocks/comentarios en `RolesController` y `_setUserPermissions` | +10 |

El commit 1 introduce la abstracción sin tocar comportamiento. 2 expone `invalidate` (sin migrar callers todavía). 3-4 migran call-sites. 5 documenta el scope acotado de la dependencia directa.

---

## Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Orden de registro DI: el Facade requiere ambos services. | Registrar primero los services concretos, luego el Facade en `Application::services`. |
| Call-sites que reciben `int $roleId` por parámetro de método (no por DI) y no tienen `UserContext` disponible. | Inspección caso a caso en commit 3. Si el llamador externo solo tiene `$roleId`, componer `new UserContext($roleId, $roleName)` en el sitio (el `roleName` está disponible vía el identity en controllers, o vía `RolesTable->get($roleId)` en services). Si esto se vuelve recurrente, evaluar overload `fromRoleId(int)` en el VO (no en este spec). |
| Tests no aplican (política del proyecto). | Validación 100% manual — pasos detallados abajo. |
| `roleName` deja de ser fiable si se renombran roles en BD entre login y request. | No es regresión (el código actual ya depende de eso). Cuando PA-007 caiga, el campo desaparece. |

## Criterios de validación manual

Tras mergear la PR, ejecutar en orden y confirmar **comportamiento idéntico al baseline pre-refactor**:

1. **Sidebar por rol**
   - Login como Admin, Contabilidad, Tesorería, Contador, Registro/Revisión, Auxiliar de Personal, Asistente de Personal, Coordinador Administrativo y Financiero.
   - **Esperado:** sidebar muestra los mismos módulos que antes para cada rol.

2. **Matriz de roles**
   - `/roles/view/{id}` para cada rol no-admin.
   - **Esperado:** matriz CRUD + matriz pipeline idénticas antes/después.

3. **Save de permisos invalida cache**
   - `/roles/edit/{id}` → modificar un checkbox (CRUD y pipeline) → guardar → recargar `view`.
   - **Esperado:** cambio persiste; nuevo login con un usuario de ese rol refleja el cambio sin reinicio del server.

4. **Flow E2E factura por rol con permiso**
   - Crear factura → avanzar a `contabilidad` (Registro) → contabilizar (Contabilidad) → avanzar a `tesoreria` → registrar pago (Tesorería) → autorizar (Contador) → verificar → quedar en `pagada`.
   - **Esperado:** cada paso permitido o denegado idéntico al baseline. Mensajes `Flash` con motivo correcto.

5. **Flow E2E factura por rol sin permiso**
   - Mismo flujo con rol que no tiene `can_operate` en algún step.
   - **Esperado:** 403 / mensaje denial idéntico al baseline.

6. **Otros dominios pipeline**
   - Un avance+regreso completo en: reintegro, caja menor, anticipo (legalización), novedad, programación de pago.
   - **Esperado:** comportamiento idéntico para todos los roles probados.

7. **Admin bypass acotado**
   - Login como rol no-admin sin `can_view` en `users` → `/users` → 403.
   - Login como admin → `/users` y `/roles` → permitido (bypass).
   - **Esperado:** PA-007 sigue funcionando igual (no se toca en este spec).

8. **Acciones con `#[PipelineAction]`**
   - Verificar que las 8 acciones del refactor PA-002 (advanceStatus, regressStatus, authorizePayment, confirmPayment, rejectPayment, registerPayment, markSigned, markExact) siguen leyendo `pipeline_permissions` y no caen al gate CRUD.

9. **`bin/cake server` arranca sin errores**
   - Cargar la página principal y al menos un controller de cada grupo.

10. **`composer cs-check`**
    - Sin nuevas violaciones de PSR-12.

---

## Cierre del hallazgo

Tras validación manual exitosa:

- Actualizar `docs/audits/permissions-audit-2026-05-11.md`:
  - PA-004: `⏳ Pendiente` → `✅ Resuelto`, con commit SHA del merge.
  - Añadir nota de cierre en la sección PA-004 con la fecha y referencia al spec.
- Sin cambios en `CLAUDE.md` (la doc de `AuthorizationFacade` queda implícita en el código; si la abstracción se vuelve invisible para devs nuevos, considerar añadir una línea en la sección "Auth & Permissions").

## Siguiente paso

Una vez cerrado PA-004, abrir el spec de **PA-011** (migración de Refund/PettyCash/Invoice/Novelty/PaymentScheduling al Modelo A Policy uniforme). Las 5 Policies nacerán dependiendo de `AuthorizationFacade`.

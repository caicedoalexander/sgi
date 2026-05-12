# PA-002 + PA-005 — Atributos de gating y `DenialReason` (Implementation Plan)

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) o `superpowers:executing-plans` para implementar tarea por tarea. Los pasos usan checkbox (`- [ ]`) para tracking.

**Goal:** Reducir los 3 lugares de olvido al registrar acciones de pipeline a uno (atributo PHP). Reemplazar `bool` revuelto de `canAdvance/canRegress` por `?DenialReason`.

**Architecture:** 8 commits secuenciales en una sola PR. Pure-additive primero (commits 1–3), migración incremental con fallback (commits 4–5), punto de no retorno (commit 6), limpieza post-migración (commits 7–8). Cada commit deja la app funcional y validable manualmente.

**Tech Stack:** PHP 8.4 attributes, CakePHP 5.3, `ReflectionMethod`, enum nativo. Sin tests automatizados (política `CLAUDE.md` §Testing Policy).

**Spec origen:** `docs/superpowers/specs/2026-05-12-pa-002-pa-005-pipeline-attributes-design.md` (commit `d3af8c5`).

**Estado pre-plan:** rama `main` limpia tras commits `0d84bd7` (PA-001), `1c73514` (PA-003).

---

## Estructura de archivos

**Crear:**
- `src/Attribute/Permission.php` — atributo CRUD.
- `src/Attribute/PipelineAction.php` — atributo pipeline.
- `src/Attribute/NoAuthGate.php` — atributo exención.
- `src/Constants/Domain/Pipeline/DenialReason.php` — enum motivo de denegación.

**Modificar (por tarea):**
- `src/Service/InvoicePipelineService.php` — añadir `denialReasonForAdvance/Regress` (Task 1).
- `src/Service/PettyCashService.php` — añadir `denialReasonForAdvance/Regress` (Task 1).
- `src/Service/PaymentSchedulingService.php` — añadir `denialReasonForAdvance/Regress` (Task 1).
- `src/Service/RefundService.php` — añadir `denialReasonForRegress` (Task 1).
- `src/Service/NoveltyService.php` — añadir `denialReasonForAdvance` (Task 1).
- `src/Controller/AppController.php` — refactor `_enforcePermission` (Task 3, 6).
- ~22 controllers no-pipeline — añadir atributos en métodos públicos (Task 4).
- 7 controllers pipeline — añadir atributos (Task 5).
- Templates y ViewModels que llaman `canAdvance/canRegress` (Task 7).

**Eliminar (Task 6 y 8):**
- `$pipelineActions` en 7 controllers.
- Método `_actionToPermission` en `AppController`.
- Bypass hardcoded de `Users::login/logout` y `EmailLogs::retry` en `_enforcePermission`.
- Métodos `canAdvance/canRegress/canAdvanceFromStatus/canReject` en 5 services (Task 8).

---

## Convenciones para validación manual

Cada tarea termina con un commit y un bloque "Validación manual" con pasos concretos. **No hay tests automatizados** (`CLAUDE.md` §Testing Policy). El sustituto del ciclo TDD es:

1. Implementar el cambio del paso.
2. `composer cs-check` — debe pasar.
3. `php bin/cake server` — debe arrancar sin error.
4. Ejercitar el endpoint/escenario descrito y verificar contra la expectativa.

Si algún paso de validación falla, **NO continuar** al siguiente commit hasta resolverlo.

---

## Task 1: Enum `DenialReason` + métodos en 5 services (commit 1)

**Files:**
- Create: `src/Constants/Domain/Pipeline/DenialReason.php`
- Modify: `src/Service/InvoicePipelineService.php` (añadir métodos después de `canRegress` en línea 175)
- Modify: `src/Service/PettyCashService.php` (añadir métodos cerca de líneas 232/710)
- Modify: `src/Service/PaymentSchedulingService.php` (añadir métodos cerca de líneas 55/68/81)
- Modify: `src/Service/RefundService.php` (añadir método cerca de línea 356)
- Modify: `src/Service/NoveltyService.php` (añadir método cerca de línea 453)

**Semántica para commit 1:** `denialReasonForAdvance/Regress` retorna SOLO `TERMINAL_STATE` y `UNAUTHORIZED`. **No** detecta `REJECTED` ni `MISSING_FIELDS` aún — esos casos se añadirán en Task 7 cuando se migren los callers para evitar cambio de comportamiento en `canAdvance/canRegress` legacy.

- [ ] **Step 1.1: Crear el enum**

Crear `src/Constants/Domain/Pipeline/DenialReason.php`:

```php
<?php
declare(strict_types=1);

namespace App\Constants\Domain\Pipeline;

/**
 * Motivos por los que un rol no puede avanzar/regresar un registro de pipeline.
 *
 * `null` (en métodos `denialReasonFor*`) ⇒ puede operar. Cualquier caso de este
 * enum ⇒ no puede, con motivo discriminable por el caller.
 */
enum DenialReason: string
{
    case TERMINAL_STATE = 'terminal_state';
    case UNAUTHORIZED = 'unauthorized';
    case REJECTED = 'rejected';
    case MISSING_FIELDS = 'missing_fields';

    public function message(): string
    {
        return match ($this) {
            self::TERMINAL_STATE => 'El registro ya está en su estado final.',
            self::UNAUTHORIZED => 'No tiene permisos para avanzar este registro.',
            self::REJECTED => 'El registro fue rechazado y no puede avanzar.',
            self::MISSING_FIELDS => 'Faltan campos requeridos para avanzar.',
        };
    }
}
```

- [ ] **Step 1.2: Añadir `denialReasonForAdvance/Regress` en `InvoicePipelineService`**

Editar `src/Service/InvoicePipelineService.php`. Añadir `use App\Constants\Domain\Pipeline\DenialReason;` cerca del top con los otros use. Justo después del método `canRegress` (línea 175–186) añadir:

```php
    /**
     * Retorna el motivo por el que la factura no puede avanzar, o null si puede.
     *
     * En commit 1 detecta sólo TERMINAL_STATE y UNAUTHORIZED. REJECTED y
     * MISSING_FIELDS se añadirán en Task 7 al migrar callers.
     */
    public function denialReasonForAdvance(Invoice $invoice, int $roleId): ?DenialReason
    {
        if ($this->getNextStatus($invoice->pipeline_status, $invoice->document_type) === null) {
            return DenialReason::TERMINAL_STATE;
        }

        if (!$this->pipelineAuth->canOperate(
            $roleId,
            PipelineStepConstants::PIPELINE_INVOICES,
            $invoice->pipeline_status,
        )) {
            return DenialReason::UNAUTHORIZED;
        }

        return null;
    }

    public function denialReasonForRegress(Invoice $invoice, int $roleId): ?DenialReason
    {
        if ($this->getPreviousStatus($invoice->pipeline_status) === null) {
            return DenialReason::TERMINAL_STATE;
        }

        if (!$this->pipelineAuth->canOperate(
            $roleId,
            PipelineStepConstants::PIPELINE_INVOICES,
            $invoice->pipeline_status,
        )) {
            return DenialReason::UNAUTHORIZED;
        }

        return null;
    }
```

Asegurarse de que `Invoice` esté importado (debería estarlo ya — verificar con grep `use App\Model\Entity\Invoice;`).

- [ ] **Step 1.3: Hacer que `canAdvance/canRegress` deleguen al nuevo método**

Sustituir el cuerpo de `canAdvance` (líneas 120–131) por:

```php
    public function canAdvance(int $roleId, string $currentStatus, ?string $documentType = null): bool
    {
        $stub = new Invoice([
            'pipeline_status' => $currentStatus,
            'document_type' => $documentType,
        ]);
        $stub->setNew(false);  // evitar marker de entity new

        return $this->denialReasonForAdvance($stub, $roleId) === null;
    }
```

Sustituir el cuerpo de `canRegress` (líneas 175–186) por:

```php
    public function canRegress(int $roleId, string $currentStatus): bool
    {
        $stub = new Invoice(['pipeline_status' => $currentStatus]);
        $stub->setNew(false);

        return $this->denialReasonForRegress($stub, $roleId) === null;
    }
```

- [ ] **Step 1.4: Añadir `denialReasonForAdvance/Regress` en `PettyCashService`**

Editar `src/Service/PettyCashService.php`. Añadir `use App\Constants\Domain\Pipeline\DenialReason;` cerca de los otros use. Justo después del método `canAdvance` (línea 232) y `canRegress` (línea 710) — o agrupados al final de la clase, según preferencia del codebase actual —, añadir:

```php
    public function denialReasonForAdvance(PettyCashRecord $record, int $roleId): ?DenialReason
    {
        $currentEnum = PettyCashPipelineStatus::tryFrom($record->status);
        if ($currentEnum === null) {
            return DenialReason::TERMINAL_STATE;
        }

        $state = $this->stateRegistry->get($currentEnum);
        if ($state->getNextStatus() === null) {
            return DenialReason::TERMINAL_STATE;
        }

        if (!$this->pipelineAuth->canOperate(
            $roleId,
            PipelineStepConstants::PIPELINE_PETTY_CASH,
            $record->status,
        )) {
            return DenialReason::UNAUTHORIZED;
        }

        return null;
    }

    public function denialReasonForRegress(PettyCashRecord $record, int $roleId): ?DenialReason
    {
        $currentEnum = PettyCashPipelineStatus::tryFrom($record->status);
        if ($currentEnum === null) {
            return DenialReason::TERMINAL_STATE;
        }

        $state = $this->stateRegistry->get($currentEnum);
        if ($state->getPreviousStatus() === null) {
            return DenialReason::TERMINAL_STATE;
        }

        if (!$this->pipelineAuth->canOperate(
            $roleId,
            PipelineStepConstants::PIPELINE_PETTY_CASH,
            $record->status,
        )) {
            return DenialReason::UNAUTHORIZED;
        }

        return null;
    }
```

Reescribir `canAdvance` (línea 232) y `canRegress` (línea 710) para delegar:

```php
    public function canAdvance(int $roleId, string $currentStatus): bool
    {
        $stub = new PettyCashRecord(['status' => $currentStatus]);
        $stub->setNew(false);

        return $this->denialReasonForAdvance($stub, $roleId) === null;
    }

    public function canRegress(int $roleId, string $currentStatus): bool
    {
        $stub = new PettyCashRecord(['status' => $currentStatus]);
        $stub->setNew(false);

        return $this->denialReasonForRegress($stub, $roleId) === null;
    }
```

- [ ] **Step 1.5: Añadir `denialReasonForAdvance/Regress` en `PaymentSchedulingService`**

Editar `src/Service/PaymentSchedulingService.php`. Añadir el `use` de `DenialReason`. Tras los 3 métodos `canAdvance/canReject/canRegress` (líneas 55–93) añadir:

```php
    public function denialReasonForAdvance(PaymentScheduling $scheduling, int $roleId): ?DenialReason
    {
        // Adaptar lógica equivalente a canAdvance actual: si no hay next status
        // ⇒ TERMINAL_STATE; si no tiene canOperate ⇒ UNAUTHORIZED.
        // Inspeccionar implementación actual en canAdvance(roleId, currentStatus)
        // y portar la condición de "next null".
        if ($this->_getNextStatus($scheduling->status) === null) {
            return DenialReason::TERMINAL_STATE;
        }

        if (!$this->pipelineAuth->canOperate(
            $roleId,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            $scheduling->status,
        )) {
            return DenialReason::UNAUTHORIZED;
        }

        return null;
    }

    public function denialReasonForRegress(PaymentScheduling $scheduling, int $roleId): ?DenialReason
    {
        if ($this->_getPreviousStatus($scheduling->status) === null) {
            return DenialReason::TERMINAL_STATE;
        }

        if (!$this->pipelineAuth->canOperate(
            $roleId,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            $scheduling->status,
        )) {
            return DenialReason::UNAUTHORIZED;
        }

        return null;
    }
```

> Nota: si `_getNextStatus` / `_getPreviousStatus` no existen con ese nombre en `PaymentSchedulingService`, inspeccionar el cuerpo actual de `canAdvance/canRegress` (líneas 55/81) y reutilizar el método o expresión que ya usen (probablemente vía `StateRegistry`).

Reescribir `canAdvance` (línea 55), `canReject` (línea 68) y `canRegress` (línea 81) para delegar al método nuevo equivalente. `canReject` puede delegar a `denialReasonForRegress` si su semántica es "regresar al borrador" — verificar contra la lógica actual.

- [ ] **Step 1.6: Añadir `denialReasonForRegress` en `RefundService`**

Editar `src/Service/RefundService.php`. Añadir el `use` de `DenialReason`. Cerca del método `canRegress` (línea 356) añadir:

```php
    public function denialReasonForRegress(Refund $refund, int $roleId): ?DenialReason
    {
        // Portar la lógica actual de canRegress(int $roleId, string $currentStatus)
        // a un método que recibe la entidad. Inspeccionar líneas 356–~370 para
        // identificar el chequeo de "previous null".
        if ($this->_getPreviousStatus($refund->pipeline_status) === null) {
            return DenialReason::TERMINAL_STATE;
        }

        if (!$this->pipelineAuth->canOperate(
            $roleId,
            PipelineStepConstants::PIPELINE_REFUNDS,
            $refund->pipeline_status,
        )) {
            return DenialReason::UNAUTHORIZED;
        }

        return null;
    }
```

Reescribir `canRegress` para delegar.

- [ ] **Step 1.7: Añadir `denialReasonForAdvance` en `NoveltyService`**

Editar `src/Service/NoveltyService.php`. Añadir el `use` de `DenialReason`. Cerca del método `canAdvanceFromStatus` (línea 453) añadir:

```php
    public function denialReasonForAdvance(EmployeeNovelty $novelty, int $roleId): ?DenialReason
    {
        // Portar la lógica actual de canAdvanceFromStatus(int $roleId, string $status)
        // de líneas 453–~465. Si la novedad ya está en su estado final, devolver
        // TERMINAL_STATE; si el rol no tiene pipeline_permission, UNAUTHORIZED.
        if ($this->_getNextStatus($novelty->status) === null) {
            return DenialReason::TERMINAL_STATE;
        }

        if (!$this->pipelineAuth->canOperate(
            $roleId,
            PipelineStepConstants::PIPELINE_NOVELTIES,
            $novelty->status,
        )) {
            return DenialReason::UNAUTHORIZED;
        }

        return null;
    }
```

Reescribir `canAdvanceFromStatus` para delegar.

- [ ] **Step 1.8: Verificar style + arranque**

```powershell
composer cs-check
php bin/cake server
```

Esperado: `cs-check` sin errores. Servidor arranca y responde en `http://localhost:8765`.

- [ ] **Step 1.9: Validación manual del delegado legacy**

Con el servidor corriendo, login como Tesorería (o cualquier rol con pipeline_permission para algún paso). Visitar `/invoices/index`. Filtrar por estado donde tengas permisos; confirmar que las facturas listadas siguen mostrando el botón "Avanzar" o "Regresar" igual que antes (los templates llaman `canAdvance/canRegress` legacy, que ahora delega — el comportamiento debe ser idéntico).

Repetir el smoke en `/refunds`, `/petty-cash-records`, `/employee-novelties`, `/payment-schedulings`. Si algún botón aparece/desaparece sin razón, revisar la implementación del `denialReasonForX` correspondiente.

- [ ] **Step 1.10: Commit**

```powershell
git add src/Constants/Domain/Pipeline/DenialReason.php src/Service/InvoicePipelineService.php src/Service/PettyCashService.php src/Service/PaymentSchedulingService.php src/Service/RefundService.php src/Service/NoveltyService.php
git commit -m "feat(pipeline): DenialReason enum + denialReasonForAdvance/Regress en 5 services"
```

---

## Task 2: Atributos PHP `Permission`/`PipelineAction`/`NoAuthGate` (commit 2)

**Files:**
- Create: `src/Attribute/Permission.php`
- Create: `src/Attribute/PipelineAction.php`
- Create: `src/Attribute/NoAuthGate.php`

- [ ] **Step 2.1: Crear `Permission`**

`src/Attribute/Permission.php`:

```php
<?php
declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/**
 * Marca una acción de controller como CRUD del módulo asociado.
 *
 * El módulo se infiere del nombre del controller via
 * `AppController::$controllerModuleMap`. El gate llama
 * `AuthorizationService::isAllowed(roleId, roleName, module, $this->action)`.
 *
 * Valores válidos para $action: 'view', 'add', 'edit', 'delete'.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Permission
{
    public function __construct(public readonly string $action)
    {
    }
}
```

- [ ] **Step 2.2: Crear `PipelineAction`**

`src/Attribute/PipelineAction.php`:

```php
<?php
declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/**
 * Marca una acción como operación de un paso de pipeline.
 *
 * - Con $step explícito ⇒ el gate llama
 *   `PipelineAuthorizationService::canOperate(roleId, pipeline, step)`
 *   y rechaza con 403 si falla. El CRUD del módulo no se chequea.
 *
 * - Sin $step (null) ⇒ acción dinámica (advance/regress, uploadDocument
 *   contextual, etc.): el gate SOLO salta el CRUD; la responsabilidad
 *   de llamar `canOperate` o `denialReasonForAdvance` queda dentro del
 *   método del controller.
 *
 * Valores válidos para $pipeline: ver constantes `PIPELINE_*` en
 * `PipelineStepConstants`.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class PipelineAction
{
    public function __construct(
        public readonly string $pipeline,
        public readonly ?string $step = null,
    ) {
    }
}
```

- [ ] **Step 2.3: Crear `NoAuthGate`**

`src/Attribute/NoAuthGate.php`:

```php
<?php
declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/**
 * Marca una acción como exenta del gate de permisos.
 *
 * Casos de uso: login/logout, error rendering, páginas estáticas,
 * acciones que delegan la autorización internamente a otro flujo.
 *
 * El motivo ($reason) es obligatorio y se documenta como prueba de
 * que la exención es intencional, no un olvido.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class NoAuthGate
{
    public function __construct(public readonly string $reason)
    {
    }
}
```

- [ ] **Step 2.4: Verificar style**

```powershell
composer cs-check
```

Esperado: sin errores.

- [ ] **Step 2.5: Verificar autoload**

```powershell
php -r "require 'vendor/autoload.php'; var_dump(class_exists('App\\Attribute\\Permission'));"
php -r "require 'vendor/autoload.php'; var_dump(class_exists('App\\Attribute\\PipelineAction'));"
php -r "require 'vendor/autoload.php'; var_dump(class_exists('App\\Attribute\\NoAuthGate'));"
```

Esperado: `bool(true)` en cada caso. Si alguno da `false`, correr `composer dump-autoload` y reintentar.

- [ ] **Step 2.6: Commit**

```powershell
git add src/Attribute/
git commit -m "feat(auth): atributos Permission/PipelineAction/NoAuthGate"
```

---

## Task 3: `_enforcePermission` lee atributos con fallback legacy (commit 3)

**Files:**
- Modify: `src/Controller/AppController.php:175–215` (refactor `_enforcePermission`).

**Estrategia:** el método nuevo intenta leer un atributo del método de la acción. Si lo encuentra ⇒ aplica la regla del atributo. Si no ⇒ cae al fallback legacy (la lógica actual). Esto permite que commits 4 y 5 migren incrementalmente.

- [ ] **Step 3.1: Añadir imports al top de `AppController.php`**

En `src/Controller/AppController.php`, añadir tras los `use` existentes (cerca de la línea 16):

```php
use App\Attribute\NoAuthGate;
use App\Attribute\Permission;
use App\Attribute\PipelineAction;
use App\Service\PipelineAuthorizationService;
use ReflectionMethod;
```

- [ ] **Step 3.2: Inyectar `PipelineAuthorizationService` en `initialize`**

Añadir propiedad cerca de las otras (línea ~24):

```php
    protected PipelineAuthorizationService $pipelineAuth;
```

En `initialize()` (línea ~50), añadir tras la asignación de `$counterService`:

```php
        $this->pipelineAuth = $this->getContainer()->get(PipelineAuthorizationService::class);
```

- [ ] **Step 3.3: Reescribir `_enforcePermission` con flujo dual**

Sustituir el cuerpo completo del método `_enforcePermission` (líneas 175–215) por:

```php
    /**
     * Aplica el gate de permisos según el atributo del método de la acción.
     *
     * Flujo:
     *  1. Resolver el método del controller actual.
     *  2. Buscar uno de los 3 atributos (#[NoAuthGate], #[Permission], #[PipelineAction]).
     *  3. Si hay atributo, aplicar su regla.
     *  4. Si no hay atributo, caer al fallback legacy (controllerModuleMap +
     *     _actionToPermission + $pipelineActions) — se eliminará en commit 6.
     */
    protected function _enforcePermission(object $user): void
    {
        $action = $this->request->getParam('action');

        $attribute = $this->_resolveAuthAttribute($action);
        if ($attribute !== null) {
            $this->_applyAuthAttribute($user, $attribute);

            return;
        }

        // ─── Fallback legacy — eliminar en commit 6 ────────────────────────
        $controllerName = $this->request->getParam('controller');

        if (!isset($this->controllerModuleMap[$controllerName])) {
            return;
        }

        if ($controllerName === 'Users' && in_array($action, ['login', 'logout'], true)) {
            return;
        }

        if ($controllerName === 'EmailLogs' && $action === 'retry') {
            return;
        }

        if (in_array($action, $this->pipelineActions, true)) {
            return;
        }

        $module = $this->controllerModuleMap[$controllerName];
        $permAction = $this->_actionToPermission($action);

        if (!$this->_checkPermission($module, $permAction)) {
            throw new ForbiddenException(
                sprintf('No tiene permisos para %s en %s.', $permAction, $module),
            );
        }
    }

    /**
     * @return Permission|PipelineAction|NoAuthGate|null
     */
    private function _resolveAuthAttribute(string $action): Permission|PipelineAction|NoAuthGate|null
    {
        if (!method_exists($this, $action)) {
            return null;
        }

        $method = new ReflectionMethod($this, $action);

        foreach ([NoAuthGate::class, Permission::class, PipelineAction::class] as $attrClass) {
            $attrs = $method->getAttributes($attrClass);
            if ($attrs !== []) {
                return $attrs[0]->newInstance();
            }
        }

        return null;
    }

    private function _applyAuthAttribute(object $user, object $attribute): void
    {
        if ($attribute instanceof NoAuthGate) {
            return;
        }

        if ($attribute instanceof Permission) {
            $controllerName = $this->request->getParam('controller');
            $module = $this->controllerModuleMap[$controllerName] ?? null;
            if ($module === null) {
                throw new LogicException(sprintf(
                    "Controller '%s' has #[Permission] but no entry in \$controllerModuleMap.",
                    $controllerName,
                ));
            }

            if (!$this->_checkPermission($module, $attribute->action)) {
                throw new ForbiddenException(
                    sprintf('No tiene permisos para %s en %s.', $attribute->action, $module),
                );
            }

            return;
        }

        if ($attribute instanceof PipelineAction) {
            if ($attribute->step === null) {
                // Acción dinámica — el método decide vía canOperate inline o
                // denialReasonForAdvance. Solo se salta el gate CRUD.
                return;
            }

            $roleId = (int)$user->role_id;
            if (!$this->pipelineAuth->canOperate($roleId, $attribute->pipeline, $attribute->step)) {
                throw new ForbiddenException(
                    sprintf(
                        'No tiene permisos para operar el paso "%s" del pipeline "%s".',
                        $attribute->step,
                        $attribute->pipeline,
                    ),
                );
            }
        }
    }
```

- [ ] **Step 3.4: Verificar style + arranque**

```powershell
composer cs-check
php bin/cake server
```

Esperado: `cs-check` sin errores. Servidor arranca.

- [ ] **Step 3.5: Smoke E2E con fallback activo**

Login como `admin` y como Tesorería. Visitar:
- `/invoices` — listado se carga.
- `/refunds` — listado se carga.
- `/petty-cash-records` — listado se carga.
- `/employee-novelties` — listado se carga.
- `/payment-schedulings` — listado se carga.

Como NINGUNA acción tiene atributo aún, todo cae al fallback legacy y debe funcionar idéntico al estado pre-commit. Si alguna ruta da 403 o 500 inesperado, hay un bug en `_resolveAuthAttribute` o el orden del try/fallback.

- [ ] **Step 3.6: Commit**

```powershell
git add src/Controller/AppController.php
git commit -m "feat(auth): _enforcePermission lee atributos con fallback legacy"
```

---

## Task 4: Anotar controllers no-pipeline con `#[Permission]`/`#[NoAuthGate]` (commit 4)

**Files (modificar):** todos los controllers que NO están en la lista pipeline. Lista completa:
- `src/Controller/UsersController.php`
- `src/Controller/RolesController.php`
- `src/Controller/ProvidersController.php`
- `src/Controller/OperationCentersController.php`
- `src/Controller/ExpenseTypesController.php`
- `src/Controller/CostCentersController.php`
- `src/Controller/ApproversController.php`
- `src/Controller/EmployeesController.php`
- `src/Controller/InvoiceHistoriesController.php`
- `src/Controller/MaritalStatusesController.php`
- `src/Controller/EducationLevelsController.php`
- `src/Controller/PositionsController.php`
- `src/Controller/DefaultFoldersController.php`
- `src/Controller/SystemSettingsController.php`
- `src/Controller/TemporaryOrganizationsController.php`
- `src/Controller/DianCrosschecksController.php`
- `src/Controller/EmployeeNoveltiesController.php` (no-pipeline aún — sólo las acciones de pipeline se anotan en Task 5; `index`, `view`, `add`, `edit`, `delete` van aquí)
- `src/Controller/NoveltyDocumentsController.php`
- `src/Controller/NoveltyTypesController.php`
- `src/Controller/NoveltyLiquidationDocsController.php` (idem — sólo las acciones CRUD en este task)
- `src/Controller/LeaveDocumentTemplatesController.php`
- `src/Controller/BankingEntitiesController.php`
- `src/Controller/PaymentRegistryController.php`
- `src/Controller/EmailLogsController.php`
- `src/Controller/DashboardController.php`
- `src/Controller/HealthController.php`
- `src/Controller/PagesController.php`
- `src/Controller/ErrorController.php`
- `src/Controller/ExternalApprovalsController.php`
- `src/Controller/Api/NotificationsController.php`

**Convención:** anotar **todos** los métodos públicos de acción. Skip métodos privados/protegidos y `initialize()`/`beforeFilter()`/`beforeRender()`.

- [ ] **Step 4.1: Añadir `use` de atributos a cada controller**

Para cada archivo de la lista, añadir tras el bloque `use` existente:

```php
use App\Attribute\NoAuthGate;
use App\Attribute\Permission;
```

(no se necesita `PipelineAction` en estos.)

- [ ] **Step 4.2: Anotar `UsersController`**

Sobre los métodos:
- `login()` → `#[NoAuthGate(reason: 'External flow before authentication')]`
- `logout()` → `#[NoAuthGate(reason: 'Always available to authenticated users')]`
- `index()`, `view()` → `#[Permission(action: 'view')]`
- `add()` → `#[Permission(action: 'add')]`
- `edit()`, `deactivate()`, `saveFields()`, `regenerateApiKey()` (si existen) → `#[Permission(action: 'edit')]`
- `delete()` → `#[Permission(action: 'delete')]`

Sintaxis del atributo (PHP 8 nativo):

```php
    #[Permission(action: 'view')]
    public function index(): void
    {
        // ...
    }
```

- [ ] **Step 4.3: Anotar `EmailLogsController`**

- `index()`, `view()` (si existen) → `#[Permission(action: 'view')]`
- `retry()` → `#[NoAuthGate(reason: 'Permission delegated internally to entity-specific module (invoices.can_edit or employee_novelties.can_edit)')]`
- `retryAllFailed()` → `#[Permission(action: 'edit')]`
- `resendApproval()` → `#[Permission(action: 'edit')]`

- [ ] **Step 4.4: Anotar `PagesController`**

- `display(...$path)` → `#[NoAuthGate(reason: 'Static content; no domain module attached')]`

- [ ] **Step 4.5: Anotar `ErrorController`**

Si tiene métodos públicos de render → `#[NoAuthGate(reason: 'Error renderer; never authorized via permissions table')]`. Inspeccionar y aplicar a cada acción pública.

- [ ] **Step 4.6: Anotar `ExternalApprovalsController`**

Las acciones de aprobación externa usan tokens, no sesión.

- `approve($token)`, `view($token)`, cualquier acción pública → `#[NoAuthGate(reason: 'External approval via SHA256 token, not session auth')]`

> Verificar: si este controller no extiende `AppController` (o no entra a `_enforcePermission` por la guarda de identity), las anotaciones son defensivas pero recomendadas para consistencia.

- [ ] **Step 4.7: Anotar `HealthController`**

- Acciones públicas de health check → `#[NoAuthGate(reason: 'Liveness/readiness probe; no auth required')]`

- [ ] **Step 4.8: Anotar `Api/NotificationsController`**

Verificar autenticación (probablemente sí requiere sesión). Si maneja CRUD de notificaciones del usuario actual:
- Métodos públicos → `#[Permission(action: 'view')]` o `#[Permission(action: 'edit')]` según corresponda.
- Si delega permiso internamente al usuario actual → `#[NoAuthGate(reason: 'Scoped to current user identity')]`

> Si el módulo de notificaciones no está en `controllerModuleMap`, añadirlo o usar `NoAuthGate` con motivo. Decidir al inspectar el controller.

- [ ] **Step 4.9: Anotar `DashboardController`**

- `index()` y cualquier acción pública → `#[Permission(action: 'view')]` (asumiendo módulo `dashboard` en `controllerModuleMap` — si no existe, añadirlo o usar `NoAuthGate(reason: 'Dashboard accesible to all authenticated users')`).

- [ ] **Step 4.10: Anotar los controllers de catálogos**

Todos los siguientes son CRUD estándar. Patrón uniforme — para cada uno:
- `index()`, `view()`, `export()`, `exportConfig()`, `all()`, `rejected()`, `exportPdf()`, `preview()`, `active()`, `legalization()`, `downloadDocument()`, `pendingLegalization()`, `overdue()`, `pending()` → `#[Permission(action: 'view')]`
- `add()`, `addFolder()`, `uploadDocument()` (no-pipeline), `import()`, `importExcel()`, `importUpload()`, `importProcess()`, `previewImport()`, `confirmImport()`, `addItem()`, `uploadAttachment()`, `uploadLiquidationDocument()` → `#[Permission(action: 'add')]`
- `edit()`, `addObservation()`, `testSmtp()`, `approve()`, `reject()` (catálogos/aprobación, no-pipeline), `deactivate()`, `saveFields()`, `removeInvoice()`, `advance()` (catálogos), `advanceGroup()`, `addSignature()`, `assignLiquidation()`, `getFlags()`, `sendApprovalLinks()`, `modifyApprovers()`, `resetFlow()`, `upload()`, `updateLiquidationDocument()` → `#[Permission(action: 'edit')]`
- `delete()`, `deleteDocument()` (no-pipeline), `removeItem()`, `deleteAttachment()` → `#[Permission(action: 'delete')]`

Aplicar a:

`ProvidersController`, `OperationCentersController`, `ExpenseTypesController`, `CostCentersController`, `ApproversController`, `EmployeesController`, `InvoiceHistoriesController`, `MaritalStatusesController`, `EducationLevelsController`, `PositionsController`, `DefaultFoldersController`, `SystemSettingsController`, `TemporaryOrganizationsController`, `DianCrosschecksController`, `NoveltyDocumentsController`, `NoveltyTypesController`, `LeaveDocumentTemplatesController`, `BankingEntitiesController`, `PaymentRegistryController`, `RolesController`.

> Importante: **NO anotar** las acciones que estén listadas en `$pipelineActions` del controller — esas se anotan en Task 5 con `#[PipelineAction]`. Por ejemplo, `RefundsController::uploadDocument` está en `$pipelineActions` así que NO se toca aquí; en cambio cualquier `uploadDocument()` de otros controllers (que no esté en $pipelineActions) sí va aquí con `#[Permission(action: 'add')]`.

- [ ] **Step 4.11: Anotar acciones CRUD de `EmployeeNoveltiesController`**

Anotar **sólo** las acciones que NO sean de pipeline. Las acciones de avance/retroceso de novedades se quedan sin anotar (sin atributo) para que caigan al fallback legacy en este commit — se anotan en Task 5.

Buscar todos los métodos públicos de `EmployeeNoveltiesController` y aplicar el mapping del Step 4.10. Si el controller tiene `advance`/`regressStatus` u otras acciones de pipeline, dejarlas SIN atributo en este commit.

- [ ] **Step 4.12: Anotar acciones CRUD de `NoveltyLiquidationDocsController`**

Mismo criterio: solo las acciones CRUD. Acciones de pipeline (si existen) sin atributo aún.

- [ ] **Step 4.13: Verificar style + arranque**

```powershell
composer cs-check
php bin/cake server
```

Esperado: sin errores; servidor arranca.

- [ ] **Step 4.14: Smoke matriz no-pipeline**

Con servidor corriendo, validar contra la matriz del spec (Bloque 2):

| Acción | Rol con permiso | Rol sin permiso |
|---|---|---|
| `Users::login` (sin sesión) | Carga | Carga |
| `Users::logout` (con sesión) | Cierra sesión | Cierra sesión |
| `EmailLogs::retry` (entity=invoice) | Rol con `invoices.can_edit` ⇒ ejecuta | Rol sin ⇒ 403 |
| `Providers::index` | Rol con `providers.can_view` | Rol sin ⇒ 403 |
| `Providers::add` | Rol con `providers.can_add` | Rol sin ⇒ 403 |
| `Providers::edit` | Rol con `providers.can_edit` | Rol sin ⇒ 403 |
| `Providers::delete` | Rol con `providers.can_delete` | Rol sin ⇒ 403 |
| `Pages::display` (`/pages/...`) | Sin sesión | Sin sesión |
| `Dashboard::index` | Sesión válida | (No aplica) |

Si alguna acción sin atributo da 403 inesperado, el fallback no se está activando correctamente. Si una acción con atributo CRUD pasa cuando no debería, el atributo está mal o el módulo no está en el map.

- [ ] **Step 4.15: Commit**

```powershell
git add src/Controller/
git commit -m "refactor(controllers): anotar controllers no-pipeline con #[Permission]/#[NoAuthGate]"
```

---

## Task 5: Anotar los 7 controllers de pipeline con `#[PipelineAction]` (commit 5)

**Files (modificar):**
- `src/Controller/InvoicesController.php`
- `src/Controller/InvoicePaymentsController.php`
- `src/Controller/LiquidationDocPaymentsController.php`
- `src/Controller/PettyCashRecordsController.php`
- `src/Controller/RefundsController.php`
- `src/Controller/PaymentSchedulingsController.php`
- `src/Controller/AdvancesController.php`
- `src/Controller/EmployeeNoveltiesController.php` (acciones de pipeline restantes)

**Constantes a importar en cada controller que las use:**

```php
use App\Attribute\PipelineAction;
use App\Constants\InvoiceConstants;     // si el pipeline es invoices
use App\Constants\NoveltyConstants;     // si el pipeline es novelties o liquidation_docs
use App\Constants\PaymentSchedulingConstants;
use App\Constants\PettyCashConstants;
use App\Constants\RefundConstants;
use App\Constants\AdvanceConstants;     // si el pipeline es legalizations
use App\Constants\PipelineStepConstants;
```

Y para las acciones CRUD coexistentes (edit/index/view/etc.):
```php
use App\Attribute\Permission;
```

- [ ] **Step 5.1: Anotar `InvoicesController`**

Acciones de pipeline ya declaradas en `$pipelineActions` (líneas 48–51):

- `advanceStatus(int $id)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES)]` (dinámica, sin step)
- `regressStatus(int $id)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES)]` (dinámica)

Resto de acciones (`index`, `view`, `add`, `edit`, `delete`, `export`, etc.) → `#[Permission(action: '...')]` con el mapping de Step 4.10.

- [ ] **Step 5.2: Anotar `InvoicePaymentsController`**

Pipeline: `PIPELINE_INVOICES`. Pipeline_status constants en `InvoiceConstants`.

- `addPayment(int $invoiceId)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES, step: InvoiceConstants::STATUS_TESORERIA)]`
- `editPayment(int $paymentId)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES, step: InvoiceConstants::STATUS_TESORERIA)]`
- `deletePayment(int $paymentId)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES, step: InvoiceConstants::STATUS_TESORERIA)]`
- `authorizePayment(int $paymentId)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES, step: InvoiceConstants::STATUS_AUTORIZACION_PAGO)]`
- `rejectPayment(int $paymentId)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES, step: InvoiceConstants::STATUS_AUTORIZACION_PAGO)]`
- `confirmPayment(int $paymentId)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES, step: InvoiceConstants::STATUS_VERIFICACION_PAGO)]`

Resto de acciones (si tiene `index`/`view`) → `#[Permission(action: '...')]`.

- [ ] **Step 5.3: Anotar `LiquidationDocPaymentsController`**

Pipeline: `PIPELINE_LIQUIDATION_DOCS`. Status constants en `NoveltyConstants`.

- `addPayment(int $liquidationDocId)` → `step: NoveltyConstants::STATUS_TESORERIA`
- `authorizePayment(int $paymentId)` → `step: NoveltyConstants::STATUS_AUTORIZACION_PAGO`
- `rejectPayment(int $paymentId)` → `step: NoveltyConstants::STATUS_AUTORIZACION_PAGO`
- `confirmPayment(int $paymentId)` → `step: NoveltyConstants::STATUS_VERIFICACION_PAGO`

Sintaxis con `pipeline: PipelineStepConstants::PIPELINE_LIQUIDATION_DOCS`.

- [ ] **Step 5.4: Anotar `PettyCashRecordsController`**

Pipeline: `PIPELINE_PETTY_CASH`. Status constants en `PettyCashConstants`.

- `advanceStatus(int $id)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PETTY_CASH)]` (dinámica)
- `regressStatus(int $id)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PETTY_CASH)]` (dinámica)
- `registerPayment(int $id)` → `step: PettyCashConstants::STATUS_TESORERIA`
- `authorizePayment(int $paymentId)` → `step: PettyCashConstants::STATUS_AUTORIZACION_PAGO`
- `rejectPayment(int $paymentId)` → `step: PettyCashConstants::STATUS_AUTORIZACION_PAGO`
- `confirmPayment(int $paymentId)` → `step: PettyCashConstants::STATUS_VERIFICACION_PAGO`

Resto CRUD → `#[Permission(...)]`.

- [ ] **Step 5.5: Anotar `RefundsController`**

Pipeline: `PIPELINE_REFUNDS`. Status constants en `RefundConstants`.

- `advanceStatus(int $id)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS)]` (dinámica)
- `regressStatus(int $id)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS)]` (dinámica)
- `registerPayment(int $id)` → `step: RefundConstants::STATUS_TESORERIA`
- `authorizePayment(int $paymentId)` → `step: RefundConstants::STATUS_AUTORIZACION_PAGO`
- `rejectPayment(int $paymentId)` → `step: RefundConstants::STATUS_AUTORIZACION_PAGO`
- `confirmPayment(int $paymentId)` → `step: RefundConstants::STATUS_VERIFICACION_PAGO`
- `uploadDocument(int $id)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS)]` (dinámica — step depende del estado actual del refund)
- `deleteDocument(int $documentId)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS)]` (dinámica)

Resto CRUD → `#[Permission(...)]`.

- [ ] **Step 5.6: Anotar `PaymentSchedulingsController`**

Pipeline: `PIPELINE_PAYMENT_SCHEDULINGS`. Status constants en `PaymentSchedulingConstants`.

- `advance(int $id)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS)]` (dinámica)
- `reject(int $id)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS)]` (dinámica — `reject` actúa sobre el step actual, equivalente a regresar)
- `regressStatus(int $id)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS)]` (dinámica)
- `confirmPayment(int $paymentId)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS, step: PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO)]`

Resto CRUD → `#[Permission(...)]`.

- [ ] **Step 5.7: Anotar `AdvancesController` — acciones con step verificable**

Pipeline: `PIPELINE_LEGALIZATIONS`. Status constants en `AdvanceConstants`. **Antes de anotar cada acción, abrir `src/Controller/AdvancesController.php` e inspeccionar el cuerpo del método para verificar contra qué step llama `canOperate` o `actionPolicy->canX(...)`. La política inline puede leerse en `src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php`.**

Aplicando la inspección, anotar:

- `moveToRevision(int $id)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS, step: AdvanceConstants::STATUS_REVISION_FIRMAS)]` (si la acción mueve DE revision_firmas) o el step previo si mueve HACIA. **Verificar en el cuerpo del método contra qué `step` llama `pipelineAuth->canOperate`.**
- `markSigned(int $id)` → `step: AdvanceConstants::STATUS_REVISION_FIRMAS`
- `returnToValidacion(int $id)` → `step: AdvanceConstants::STATUS_REVISION_FIRMAS` (operación de regresar desde revision_firmas)
- `markExact(int $id)` → step a verificar contra `AdvanceLegalizationActionPolicy::canMarkExact`
- `linkCandidates(int $id)` → step a verificar contra `AdvanceLegalizationActionPolicy::canLinkInvoices` (probable `STATUS_TESORERIA` o `STATUS_VERIFICACION_PAGO`)
- `linkInvoices(int $id)` → mismo step que `linkCandidates`
- `unlinkInvoice(int $id, int $invoiceId)` → mismo step
- `uploadRelationDocument(int $id)` → step a verificar contra `AdvanceLegalizationActionPolicy::canUploadRelationDocument`
- `registerShortage(int $id)` → step a verificar contra `AdvanceLegalizationActionPolicy::canRegisterShortage`
- `confirmShortage(int $id)` → step a verificar contra `AdvanceLegalizationActionPolicy::canConfirmShortage`
- `registerSurplus(int $id)` → step a verificar contra `AdvanceLegalizationActionPolicy::canRegisterSurplus`
- `registerRefund(int $id)` → step a verificar contra `AdvanceLegalizationActionPolicy::canRegisterRefund`
- `confirmRefundPayment(int $id)` → step a verificar contra `AdvanceLegalizationActionPolicy::canConfirmRefundPayment`

**Regla de decisión durante este step:**
- Si la acción del policy invoca `canOperate(pipeline, step)` con un step fijo ⇒ `#[PipelineAction(pipeline, step: STEP_FIJO)]`.
- Si la acción depende del estado actual del agregado (calcula step en runtime, p. ej. `canOperate(pipeline, $advance->status)`) ⇒ `#[PipelineAction(pipeline)]` sin step + el método mantiene la llamada inline.

Resto CRUD del controller (`index`, `view`, `add`, `edit`, `delete`, `pendingLegalization`, etc.) → `#[Permission(...)]`.

- [ ] **Step 5.8: Anotar las acciones de pipeline de `EmployeeNoveltiesController`**

Pipeline: `PIPELINE_NOVELTIES`. Status constants en `NoveltyConstants`.

Inspeccionar `EmployeeNoveltiesController` para identificar acciones de pipeline (probable: `advanceStatus`, `regressStatus`, `addObservation` no es pipeline, `markSigned` si existe, etc.). Para cada acción de pipeline:

- `advanceStatus(int $id)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_NOVELTIES)]` (dinámica)
- `regressStatus(int $id)` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_NOVELTIES)]` (dinámica)
- Cualquier otra acción detectada → resolver step contra el cuerpo del método.

- [ ] **Step 5.9: Verificar style + arranque**

```powershell
composer cs-check
php bin/cake server
```

Esperado: sin errores; servidor arranca.

- [ ] **Step 5.10: Smoke de matriz pipeline**

Con servidor corriendo, validar contra la matriz del spec (Bloque 2):

| Acción | Rol con permiso | Rol sin permiso |
|---|---|---|
| `Invoices::edit` (`aprobacion`) | Registro ejecuta | Tesorería ⇒ 403 |
| `Invoices::advanceStatus` (`tesoreria`) | Tesorería con pipeline_permission ⇒ avanza | Contabilidad ⇒ Flash + redirect |
| `InvoicePayments::authorizePayment` | Contador con pipeline_permission(autorizacion_pago) ⇒ ejecuta | Tesorería ⇒ 403 |
| `InvoicePayments::confirmPayment` | Tesorería con pipeline_permission(verificacion_pago) ⇒ ejecuta | Contador ⇒ 403 |
| `Advances::markSigned` | Rol con pipeline_permission(revision_firmas) ⇒ ejecuta | Otro ⇒ 403 |
| `Refunds::advanceStatus` (`tesoreria`) | Tesorería avanza | Otro ⇒ Flash + redirect |
| `PettyCashRecords::registerPayment` | Tesorería ejecuta | Otro ⇒ 403 |
| `PaymentSchedulings::advance` | Rol del step actual avanza | Otro ⇒ Flash + redirect |

Para las acciones estáticas, el 403 debe llegar **antes** de ejecutar el método (lanzado por el gate en `beforeFilter`). Para las dinámicas, el control fino lo hace el método mismo (Flash + redirect).

- [ ] **Step 5.11: Commit**

```powershell
git add src/Controller/
git commit -m "refactor(pipeline): anotar 7 controllers de pipeline con #[PipelineAction]"
```

---

## Task 6: Eliminar `$pipelineActions`, `_actionToPermission` y la rama legacy (commit 6)

**Files:**
- Modify: `src/Controller/AppController.php` (eliminar `$pipelineActions`, `_actionToPermission`, rama legacy de `_enforcePermission`)
- Modify: 7 controllers (eliminar declaración de `$pipelineActions`)

**Punto de no retorno: tras este commit, cualquier acción sin atributo lanza `LogicException 500` al primer hit.**

- [ ] **Step 6.1: Eliminar `$pipelineActions` de los 7 controllers**

Para cada uno de:
- `InvoicesController.php` (líneas 48–51)
- `RefundsController.php` (líneas 36–45)
- `PettyCashRecordsController.php` (líneas 34–41)
- `PaymentSchedulingsController.php` (líneas 35–40)
- `InvoicePaymentsController.php` (líneas 21–28)
- `LiquidationDocPaymentsController.php` (líneas 21–26)
- `AdvancesController.php` (líneas 33–47)

Eliminar la declaración completa de `protected array $pipelineActions = [...]` y el docblock asociado.

- [ ] **Step 6.2: Eliminar `$pipelineActions` y `_actionToPermission` de `AppController`**

En `src/Controller/AppController.php`:

- Eliminar el bloque de líneas ~62–74 (docblock + `protected array $pipelineActions = []`).
- Eliminar el método completo `_actionToPermission(string $action): string` (líneas ~113–127).

- [ ] **Step 6.3: Eliminar la rama legacy de `_enforcePermission`**

Sustituir el cuerpo completo del método `_enforcePermission` (refactorizado en Task 3) por:

```php
    /**
     * Aplica el gate de permisos leyendo el atributo del método de la acción.
     *
     * Atributos válidos: #[NoAuthGate], #[Permission], #[PipelineAction].
     * Falta de atributo ⇒ LogicException 500 (loud-and-clear).
     */
    protected function _enforcePermission(object $user): void
    {
        $action = $this->request->getParam('action');
        $controllerName = $this->request->getParam('controller');

        $attribute = $this->_resolveAuthAttribute($action);
        if ($attribute === null) {
            throw new LogicException(sprintf(
                "Action '%s::%s' has no auth attribute. " .
                "Annotate the method with #[Permission], #[PipelineAction] or #[NoAuthGate].",
                $controllerName,
                $action,
            ));
        }

        $this->_applyAuthAttribute($user, $attribute);
    }
```

`_resolveAuthAttribute` y `_applyAuthAttribute` (creados en Task 3) se conservan tal cual.

Eliminar también el import `use Cake\Http\Exception\ForbiddenException;` si ya no se usa directamente (sigue usándose dentro de `_applyAuthAttribute`, mantener).

- [ ] **Step 6.4: Verificar style**

```powershell
composer cs-check
```

Esperado: sin errores.

- [ ] **Step 6.5: Verificar arranque y barrido de rutas**

```powershell
php bin/cake server
```

Visitar cada controller en `index`:
- `/invoices`, `/refunds`, `/petty-cash-records`, `/employee-novelties`, `/payment-schedulings`, `/advances`
- `/users`, `/roles`, `/providers`, `/operation-centers`, `/expense-types`, `/cost-centers`, `/approvers`, `/employees`, `/marital-statuses`, `/education-levels`, `/positions`, `/default-folders`, `/system-settings`, `/temporary-organizations`, `/dian-crosschecks`, `/novelty-types`, `/leave-document-templates`, `/banking-entities`, `/payment-registry`, `/email-logs`, `/dashboard`

Si alguna ruta lanza `LogicException` ⇒ acción sin atributo. Identificar el método y anotarlo. Repetir hasta que todas carguen.

- [ ] **Step 6.6: Verificación de fallo loud (manual)**

Añadir temporalmente al final de `src/Controller/InvoicesController.php` (antes del último `}`):

```php
    public function dummyMissing(): \Cake\Http\Response
    {
        return $this->response->withStringBody('should never reach');
    }
```

Con sesión iniciada, visitar `/invoices/dummy-missing`. Esperado: response **500** con mensaje `LogicException: Action 'Invoices::dummyMissing' has no auth attribute...`.

Eliminar el método de prueba antes de continuar.

- [ ] **Step 6.7: Smoke E2E completo**

Ejecutar el happy path en cada pipeline (Bloque 5 del spec):

1. **Factura estándar**: crear → `aprobacion` → aprobación externa → `contabilidad` → `tesoreria` → registrar pago → `autorizacion_pago` → autorizar → `verificacion_pago` → confirmar → `pagada`.
2. **Factura legalización**: hasta `legalizada`.
3. **Refund**: agrupación → pagada.
4. **Petty Cash**: agrupación → pagada.
5. **Advance legalization**: validacion → legalizada (con `markSigned`/`markExact`).
6. **PaymentScheduling**: borrador → pagada.

Si algún flujo se rompe ⇒ acción sin atributo o atributo mal asignado. **NO seguir** sin resolverlo.

- [ ] **Step 6.8: Commit**

```powershell
git add src/Controller/
git commit -m "refactor(auth): eliminar \$pipelineActions, _actionToPermission y la rama legacy"
```

---

## Task 7: Migrar callers a `denialReasonForAdvance/Regress` (commit 7)

**Files (modificar — inventariar):**

Buscar todos los call-sites:

```powershell
# desde la raíz del repo
Get-ChildItem -Recurse -Include *.php,*.ctp,*.twig -Path src/, templates/ |
  Select-String -Pattern 'canAdvance\(|canRegress\(|canAdvanceFromStatus\(|canReject\('
```

Resultado esperado: lista de archivos (services, controllers, templates) que llaman estos métodos. Los services llaman entre sí los métodos legacy en sus propios cuerpos — **no migrar esas llamadas internas todavía** (se eliminan en Task 8 cuando borremos los métodos). Migrar solo los callers externos (controllers + templates).

**Decisión adicional para este commit:** expandir `denialReasonForAdvance` de `InvoicePipelineService` para detectar `REJECTED` y `MISSING_FIELDS`. Esto es legítimo aquí porque los callers que se migran van a usar el motivo específico, no `null/!null`.

- [ ] **Step 7.1: Expandir `denialReasonForAdvance` en `InvoicePipelineService` para REJECTED**

Editar el cuerpo del método (creado en Step 1.2). Añadir entre el chequeo de `getNextStatus` y el chequeo de `canOperate`:

```php
    public function denialReasonForAdvance(Invoice $invoice, int $roleId): ?DenialReason
    {
        if ($this->getNextStatus($invoice->pipeline_status, $invoice->document_type) === null) {
            return DenialReason::TERMINAL_STATE;
        }

        if ($this->isRejected($invoice)) {
            return DenialReason::REJECTED;
        }

        if (!$this->pipelineAuth->canOperate(
            $roleId,
            PipelineStepConstants::PIPELINE_INVOICES,
            $invoice->pipeline_status,
        )) {
            return DenialReason::UNAUTHORIZED;
        }

        return null;
    }
```

> Importante: `canAdvance` legacy (que delega a `denialReasonForAdvance(...) === null`) ahora retorna false para facturas rechazadas. Verificar que los callers legacy que aún quedan (los services que se llaman entre sí internamente) no rompan — los services no avanzan facturas rechazadas en ningún flujo, así que el cambio es seguro. Si en producción algún flujo dependiera de "avanzar rechazada", aparecería como Flash inesperado.

- [ ] **Step 7.2: Expandir similarmente en `denialReasonForRegress` de `InvoicePipelineService`**

Misma estructura — antes del chequeo de `canOperate`:

```php
        if ($this->isRejected($invoice)) {
            return DenialReason::REJECTED;
        }
```

Repetir el patrón en los otros services si su lógica de negocio tiene un concepto de "rechazo" análogo (refunds, novelties — inspeccionar si existe `isRejected` o `area_approval` en sus entities; si no existe, no añadir).

- [ ] **Step 7.3: Migrar `InvoicesController::advanceStatus`**

Inspeccionar el cuerpo actual. Sustituir cualquier patrón:

```php
if (!$this->pipeline->canAdvance($roleId, $invoice->pipeline_status, $invoice->document_type)) {
    $this->Flash->error('No puede avanzar.');
    return $this->redirect(...);
}
```

Por:

```php
$reason = $this->pipeline->denialReasonForAdvance($invoice, $roleId);
if ($reason !== null) {
    $this->Flash->error($reason->message());
    return $this->redirect(...);
}
```

- [ ] **Step 7.4: Migrar `InvoicesController::regressStatus`**

Mismo patrón con `denialReasonForRegress`.

- [ ] **Step 7.5: Migrar acciones dinámicas de los otros 6 controllers de pipeline**

Por cada uno (`RefundsController`, `PettyCashRecordsController`, `PaymentSchedulingsController`, `AdvancesController`, `EmployeeNoveltiesController`, `InvoicePaymentsController`, `LiquidationDocPaymentsController`):

1. Buscar todas las llamadas a `canAdvance/canRegress/canAdvanceFromStatus/canReject`.
2. Sustituir por la llamada equivalente a `denialReasonFor...`.
3. Usar `$reason->message()` para el Flash en lugar del mensaje genérico actual.

- [ ] **Step 7.6: Migrar templates**

Buscar templates que llaman los métodos legacy:

```powershell
Get-ChildItem -Recurse -Filter *.php -Path templates/ |
  Select-String -Pattern 'canAdvance|canRegress|canAdvanceFromStatus|canReject'
```

Las plantillas suelen usar estos métodos para mostrar/ocultar botones del pipeline. Sustituir:

```php
<?php if ($pipelineService->canAdvance(...)): ?>
    <button>Avanzar</button>
<?php endif ?>
```

Por:

```php
<?php $denial = $pipelineService->denialReasonForAdvance($invoice, $roleId); ?>
<?php if ($denial === null): ?>
    <button>Avanzar</button>
<?php else: ?>
    <span class="text-muted small"><?= h($denial->message()) ?></span>
<?php endif ?>
```

> Decisión por template: si la UX actual sólo oculta el botón sin mostrar motivo, mantener ese comportamiento mostrando sólo el botón (`if ($denial === null)`). Si la UX puede beneficiarse del motivo visible, mostrar el mensaje. Decidir caso por caso al revisar cada plantilla.

- [ ] **Step 7.7: Migrar ViewModels y elementos compartidos**

Buscar también:

```powershell
Get-ChildItem -Recurse -Filter *.php -Path templates/element/, src/View/ |
  Select-String -Pattern 'canAdvance|canRegress'
```

Aplicar el mismo patrón.

- [ ] **Step 7.8: Verificar style + arranque**

```powershell
composer cs-check
php bin/cake server
```

- [ ] **Step 7.9: Validación de las 4 ramas de `DenialReason`**

Con servidor corriendo, ejercitar cada caso del Bloque 3 del spec:

| Caso | Setup | Mensaje esperado |
|---|---|---|
| `TERMINAL_STATE` | Factura en `pagada`, intentar avanzar | "El registro ya está en su estado final." |
| `UNAUTHORIZED` | Factura en `aprobacion`, rol Tesorería sin pipeline_permission | "No tiene permisos para avanzar este registro." |
| `REJECTED` | Factura con `area_approval='Rechazada'` | "El registro fue rechazado y no puede avanzar." |
| `MISSING_FIELDS` | Factura en `tesoreria` sin pagos registrados | (Pendiente — no se añadió MISSING_FIELDS al enum aún. El flujo actual muestra los errores específicos de `validateTransitionRequirements` por separado en el Flash. Verificar que ese mensaje sigue mostrándose con la lista de campos.) |

> Nota: `MISSING_FIELDS` está declarado en el enum pero los métodos `denialReasonForAdvance` actuales no lo emiten. El flujo de validación de campos sigue corriendo paralelo vía `validateTransitionRequirements` desde `saveAndAdvance` y mostrando los errores en Flash. Esto se trata en un PR futuro (no es scope de PA-002/PA-005).

- [ ] **Step 7.10: Commit**

```powershell
git add src/ templates/
git commit -m "refactor(pipeline): migrar callers de canAdvance/canRegress a denialReason"
```

---

## Task 8: Eliminar `canAdvance/canRegress` deprecados (commit 8)

**Files:**
- Modify: `src/Service/InvoicePipelineService.php` (eliminar `canAdvance`, `canRegress`)
- Modify: `src/Service/PettyCashService.php` (eliminar `canAdvance`, `canRegress`)
- Modify: `src/Service/PaymentSchedulingService.php` (eliminar `canAdvance`, `canReject`, `canRegress`)
- Modify: `src/Service/RefundService.php` (eliminar `canRegress`)
- Modify: `src/Service/NoveltyService.php` (eliminar `canAdvanceFromStatus`)

- [ ] **Step 8.1: Buscar callers residuales**

```powershell
Get-ChildItem -Recurse -Include *.php,*.ctp -Path src/, templates/ |
  Select-String -Pattern '->canAdvance\(|->canRegress\(|->canAdvanceFromStatus\(|->canReject\('
```

Esperado: solo llamadas internas dentro de los services (entre sí) — no debería haber callers externos tras Task 7. Si aparece algún caller externo, migrarlo a `denialReasonFor...` antes de continuar.

- [ ] **Step 8.2: Eliminar los métodos legacy en `InvoicePipelineService`**

Eliminar:
- `canAdvance(int $roleId, string $currentStatus, ?string $documentType = null): bool` (líneas ~120–131)
- `canRegress(int $roleId, string $currentStatus): bool` (líneas ~175–186)

- [ ] **Step 8.3: Eliminar los métodos legacy en `PettyCashService`**

Eliminar:
- `canAdvance(int $roleId, string $currentStatus): bool` (línea 232)
- `canRegress(int $roleId, string $currentStatus): bool` (línea 710)

- [ ] **Step 8.4: Eliminar los métodos legacy en `PaymentSchedulingService`**

Eliminar:
- `canAdvance(int $roleId, string $currentStatus): bool` (línea 55)
- `canReject(int $roleId, string $currentStatus): bool` (línea 68)
- `canRegress(int $roleId, string $currentStatus): bool` (línea 81)

- [ ] **Step 8.5: Eliminar `canRegress` en `RefundService`**

Línea ~356.

- [ ] **Step 8.6: Eliminar `canAdvanceFromStatus` en `NoveltyService`**

Línea ~453.

- [ ] **Step 8.7: Verificar style + arranque**

```powershell
composer cs-check
php bin/cake server
```

Si algún caller quedó sin migrar, PHP lanzará `BadMethodCallException` al primer hit del endpoint correspondiente. Identificar y migrar.

- [ ] **Step 8.8: Smoke E2E final**

Re-ejecutar los 6 happy paths del Bloque 5 del spec. Cero Flash inesperados, cero 500s, cero 403 espurios.

- [ ] **Step 8.9: Cerrar hallazgos en el audit doc**

Editar `docs/audits/permissions-audit-2026-05-11.md`. En la tabla "Estado de remediación":

- PA-002: `⏳ Pendiente` → `✅ Resuelto` con commit `<sha_commit_8>` (2026-05-XX).
- PA-005: idem.
- PA-006: idem (cerrado como efecto colateral).
- PA-010: idem (cerrado como efecto colateral).

Añadir párrafo de cierre bajo cada hallazgo, en el estilo del cierre de PA-001 (ver `docs/audits/permissions-audit-2026-05-11.md:56-58`).

- [ ] **Step 8.10: Commit**

```powershell
git add src/Service/ docs/audits/permissions-audit-2026-05-11.md
git commit -m "chore(pipeline): eliminar canAdvance/canRegress deprecados; cerrar PA-002/005/006/010"
```

---

## Cierre de la PR

Tras los 8 commits:

```powershell
git log --oneline -10
```

Verificar el orden: `Task 1 → Task 8` en orden cronológico. Si la PR aún no existe, crearla con `gh pr create` apuntando a `main`. Etiquetar como `refactor` y `audit`.

**Métricas esperadas (aprox):** `+400 / -200 LoC`, 8 commits, 1 PR.

---

## Notas para el ejecutor

- **No combinar commits.** Cada uno es un punto de checkpoint manual. Si un commit falla validación, hay que volver a él, no a uno anterior.
- **No saltarse pasos de validación.** Sin tests automatizados, la validación manual es la única red.
- **Para Step 5.7 (Advances)**: la inspección del cuerpo de cada acción es **obligatoria** antes de anotar. No copiar steps adivinados — si una acción resulta ser dinámica, modelarla como dinámica.
- **Si una acción no encaja en ninguna de las tres categorías** (CRUD del módulo, pipeline-step, exenta) ⇒ probablemente la acción es código muerto. Confirmar con `git log -S` y eliminar antes de anotarla.
- **Rollback parcial es seguro hasta antes del commit 6.** Después de 6, rollback de commits individuales puede dejar la app sin atributos en algunos métodos ⇒ requiere hotfix con anotaciones puntuales.

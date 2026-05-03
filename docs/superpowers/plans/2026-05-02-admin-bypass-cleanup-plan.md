# Cleanup post pipeline-permissions: bypass de Administrador y constantes huérfanas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Limitar el bypass del rol Administrador a los módulos `users` y `roles`, eliminar checks redundantes de Admin en services y controllers, y borrar las constantes huérfanas que dejó la migración a `pipeline_permissions`.

**Architecture:** El bypass de Administrador se centraliza en una constante declarativa `AuthorizationService::ADMIN_BYPASS_MODULES = ['users', 'roles']`. Tras el cambio, `PipelineAuthorizationService` deja de tener bypass y todo callsite con `if (roleName !== ADMIN && !pipelineAuth->canOperate(...))` se simplifica a `if (!pipelineAuth->canOperate(...))`. Las listas de visibilidad por rol (`ROLE_VISIBLE_STATUSES`, `Pipeline/State::getRoleVisibility()`) quedan fuera de alcance.

**Tech Stack:** PHP 8.2+, CakePHP 5.3, MySQL/MariaDB. Sin tests automatizados (política del proyecto). Validación = `composer cs-check` + `php -l` por archivo + validación manual final por el usuario en el navegador.

**Spec:** `docs/superpowers/specs/2026-05-02-admin-bypass-cleanup-design.md`

---

## File Structure

**Archivos creados:** ninguno (cleanup puro).

**Archivos modificados:**

| Archivo | Razón |
|---------|-------|
| `src/Service/AuthorizationService.php` | Agregar `ADMIN_BYPASS_MODULES`; condicionar bypass en `isAllowed()` |
| `src/Service/PipelineAuthorizationService.php` | Eliminar bypass de Admin |
| `src/Controller/AppController.php` | Delegar bypass a `AuthorizationService` |
| `src/Service/InvoicePipelineService.php` | Eliminar bypass redundante (2 lugares) |
| `src/Service/InvoiceFieldAccessPolicy.php` | Eliminar bypass redundante (3 lugares) |
| `src/Service/InvoiceTransitionValidator.php` | Eliminar bypass redundante (1 lugar) |
| `src/Service/NoveltyPipelineService.php` | Eliminar bypass redundante (3 lugares) |
| `src/Service/PaymentSchedulingPipelineService.php` | Eliminar bypass redundante (3 lugares) |
| `src/Service/PettyCashService.php` | Eliminar bypass redundante (1 lugar) |
| `src/Service/RefundService.php` | Eliminar bypass redundante (1 lugar) |
| `src/Controller/InvoicePaymentsController.php` | Eliminar bypass redundante (5 lugares) |
| `src/Controller/LiquidationDocPaymentsController.php` | Eliminar bypass redundante (3 lugares) |
| `src/Controller/RefundsController.php` | Eliminar bypass redundante (2 lugares) |
| `src/Controller/PettyCashRecordsController.php` | Eliminar bypass redundante (2 lugares) |
| `src/Controller/NoveltyLiquidationDocsController.php` | Eliminar bypass redundante (2 lugares) |
| `src/Controller/InvoicesController.php` | Eliminar bypass de bloqueos (3 lugares — *behavior change*) |
| `src/Controller/EmailLogsController.php` | Eliminar bypass de Admin en `_canRetry()` |
| `src/Constants/PettyCashConstants.php` | Borrar `REGRESS_ROLE_BY_STATUS` |
| `src/Constants/RefundConstants.php` | Borrar `REGRESS_ROLE_BY_STATUS` |
| `src/Constants/NoveltyConstants.php` | Borrar 5 alias backward-compat |
| Otras constantes Tier 2 | Solo si el usuario aprueba en Task 3 |

**Archivos auxiliares (temporales, no se commitean al final):**
- `docs/superpowers/audit-constants-2026-05-02.md` — reporte de auditoría que el usuario revisa antes de la limpieza Tier 2.

---

## Task 1: Generar reporte de auditoría de constantes

**Files:**
- Create: `docs/superpowers/audit-constants-2026-05-02.md`

**Justificación:** El usuario pidió "verificar caller-por-caller y reportar antes de tocar" para constantes huérfanas (Pregunta 3 del brainstorming). Este task genera el reporte para que el usuario vete candidatas dudosas.

**Comando de auditoría completa (referencia):**

```bash
for f in src/Constants/*.php; do
  cls=$(basename "$f" .php)
  echo "=== $cls ==="
  grep -E "public const [A-Z_]+\s*=" "$f" | sed -E 's/.*public const ([A-Z_]+).*/\1/' | while read const; do
    count=$(grep -rln "${cls}::${const}\b" src/ templates/ config/ bin/ 2>/dev/null | grep -v "src/Constants/${cls}.php" | wc -l)
    if [ "$count" = "0" ]; then
      echo "  ORPHAN: ${const}"
    fi
  done
done
```

- [ ] **Step 1: Crear el reporte de auditoría con los hallazgos pre-cargados**

Escribir el siguiente contenido en `docs/superpowers/audit-constants-2026-05-02.md`:

````markdown
# Auditoría de constantes huérfanas — 2026-05-02

Constantes en `src/Constants/` sin referencias fuera de su propio archivo en `src/`, `templates/`, `config/` y `bin/`. Migraciones legacy ejecutadas no cuentan como uso vivo (commit `pipeline-permissions`).

## Tier 1 — Borrar (alias backward-compat o explícitamente @deprecated)

| Archivo | Constante | Valor | Motivo |
|---------|-----------|-------|--------|
| `PettyCashConstants` | `REGRESS_ROLE_BY_STATUS` | `array` | `@deprecated` — migrado a `pipeline_permissions` |
| `RefundConstants` | `REGRESS_ROLE_BY_STATUS` | `array` | `@deprecated` — migrado a `pipeline_permissions` |
| `NoveltyConstants` | `STATUS_FIRMAS_APROBACION` | `= self::STATUS_REVISION_FIRMAS` | Alias backward-compat por renombre |
| `NoveltyConstants` | `STATUS_PENDING` | `= self::STATUS_REGISTRO` | Alias backward-compat |
| `NoveltyConstants` | `STATUS_APPROVED` | `= self::STATUS_PAGADA` | Alias backward-compat |
| `NoveltyConstants` | `STATUS_REJECTED` | `= self::STATUS_RECHAZADA` | Alias backward-compat |
| `NoveltyConstants` | `STATUSES` | `= self::ALL_STATUSES` | Alias backward-compat |

## Tier 2 — Probables (zero callers externos, no aparecen en arrays internos)

| Archivo | Constante | Valor | Comentario |
|---------|-----------|-------|------------|
| `InvoiceConstants` | `APPROVER_STATUSES` | array | Definida pero sin uso (existe `APPROVER_STATUSES_ACTIVE` que es la usada) |
| `InvoiceConstants` | `PAYMENT_RECORD_STATUSES` | array | Definida pero sin uso |
| `InvoiceConstants` | `DIAN_PENDING` | `'Pendiente'` | Constante individual (existe `DIAN_STATUSES` que la incluye) |
| `NoveltyConstants` | `DOC_TYPE_SUPPORT` | `'support'` | Constante suelta sin uso ni array contenedor |
| `PipelineStepConstants` | `PIPELINES` | array | Lista declarativa sin caller |

## Tier 3 — Riesgo de borrado (zero callers externos PERO referenciadas internamente en arrays exportados)

> Borrar estas obliga a inlinear strings en los arrays públicos del mismo archivo, lo que rompe la abstracción "valor con nombre". **Recomendación: NO borrar.**

| Archivo | Constante | Referenciada en | Recomendación |
|---------|-----------|-----------------|---------------|
| `AdvanceConstants` | `MODULE` | string `'advances'` se usa en controllers | Conservar (anchor del slug) |
| `ContractTypeConstants` | `FIJO`, `INDEFINIDO` | `self::ALL`, `self::LABELS` | Conservar |
| `InvoiceConstants` | `HOLDER_TYPE_PROVIDER`, `HOLDER_TYPE_EMPLOYEE`, `HOLDER_TYPE_MANUAL` | `self::HOLDER_TYPES` | Conservar |
| `NoveltyConstants` | `STATUS_REGISTRO` | `self::ALL_STATUSES`, `self::STATUS_LABELS`, `self::STATUS_ICONS` | Conservar |
| `NoveltyConstants` | `PERIOD_SEGUNDA_QUINCENA`, `PERIOD_CIERRE_NOMINA` | `self::PERIODS`, `self::PERIOD_LABELS` | Conservar |
| `NoveltyConstants` | `PAYMENT_PENDIENTE`, `PAYMENT_NA` | `self::PAYMENT_STATUSES`, `self::PAYMENT_LABELS` | Conservar |
| `NoveltyConstants` | `APPROVAL_PENDING` | Valor `'Pendiente'` posiblemente hardcodeado en templates | Conservar (riesgo) |
| `PettyCashConstants` | `CODE_PREFIX` | `'CM'` posiblemente usado en seq generation | Conservar (riesgo) |
| `ProviderConstants` | `DOCUMENT_TYPE_NIT`, `DOCUMENT_TYPE_CC`, `DOCUMENT_TYPE_OTHER` | `self::DOCUMENT_TYPES` | Conservar |
| `RefundConstants` | `OBSERVATION_TYPE_GENERAL` | `self::OBSERVATION_TYPES` | Conservar |

## Acción solicitada al usuario

1. Confirmar borrado de **Tier 1 completo** (acción default). Si quieres conservar alguna, indícalo.
2. Confirmar borrado de **Tier 2** ítem por ítem (riesgo bajo pero requiere veto explícito).
3. **Tier 3** se conserva por default. Solo se borra si pides explícitamente alguna.
````

- [ ] **Step 2: Commit del reporte**

```bash
git add docs/superpowers/audit-constants-2026-05-02.md
git commit -m "$(cat <<'EOF'
chore(audit): inventario de constantes huérfanas en src/Constants

Reporte para revisión del usuario antes de Task 2 y 3 del plan
de cleanup post pipeline-permissions.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 3: PUNTO DE CONFIRMACIÓN — Esperar veto del usuario**

Mensaje al usuario:
> "Reporte de auditoría escrito en `docs/superpowers/audit-constants-2026-05-02.md`. Tier 1 (7 constantes) se borrará por default en Task 2; Tier 2 (5 constantes) requiere tu confirmación; Tier 3 se conserva. ¿Procedo con Tier 1 + alguna confirmación de Tier 2?"

Esperar respuesta antes de continuar a Task 2.

---

## Task 2: Borrar constantes Tier 1 (confirmadas seguras)

**Files:**
- Modify: `src/Constants/PettyCashConstants.php` (eliminar líneas 56-65)
- Modify: `src/Constants/RefundConstants.php` (eliminar líneas 54-63)
- Modify: `src/Constants/NoveltyConstants.php` (eliminar líneas 21, 178-182)

- [ ] **Step 1: Borrar `REGRESS_ROLE_BY_STATUS` de `PettyCashConstants`**

Edit en `src/Constants/PettyCashConstants.php`:

`old_string`:
```php
    /**
     * @deprecated Migrado a pipeline_permissions a partir del plan
     *   2026-05-02-pipeline-permissions. Conservado solo por referencia
     *   histórica; no se consulta desde el código.
     */
    public const REGRESS_ROLE_BY_STATUS = [
        self::STATUS_CONTABILIDAD => [RoleConstants::CONTABILIDAD],
        self::STATUS_TESORERIA => [RoleConstants::TESORERIA],
        self::STATUS_AUT_PAGO => [RoleConstants::CONTADOR],
    ];

    // Tipos de observación (petty_cash_observations.type)
```

`new_string`:
```php
    // Tipos de observación (petty_cash_observations.type)
```

- [ ] **Step 2: Verificar que `RoleConstants` ya no se referencia en `PettyCashConstants`**

Run: `grep -n "RoleConstants" src/Constants/PettyCashConstants.php`
Expected: 0 matches (la constante borrada era el único caller).

Nota: `PettyCashConstants` está en `namespace App\Constants` (igual que `RoleConstants`), por lo que NO usa `use App\Constants\RoleConstants;`. No hay import que remover; el grep es solo para confirmar que no quedan referencias huérfanas a esa clase.

- [ ] **Step 3: Borrar `REGRESS_ROLE_BY_STATUS` de `RefundConstants`**

Edit en `src/Constants/RefundConstants.php`:

`old_string`:
```php
    /**
     * @deprecated Migrado a pipeline_permissions a partir del plan
     *   2026-05-02-pipeline-permissions. Conservado solo por referencia
     *   histórica; no se consulta desde el código.
     */
    public const REGRESS_ROLE_BY_STATUS = [
        self::STATUS_CONTABILIDAD => [RoleConstants::CONTABILIDAD],
        self::STATUS_TESORERIA => [RoleConstants::TESORERIA],
        self::STATUS_AUT_PAGO => [RoleConstants::CONTADOR],
    ];

    public const OBSERVATION_TYPE_GENERAL = 'general';
```

`new_string`:
```php
    public const OBSERVATION_TYPE_GENERAL = 'general';
```

- [ ] **Step 4: Verificar que `RoleConstants` ya no se referencia en `RefundConstants`**

Run: `grep -n "RoleConstants" src/Constants/RefundConstants.php`
Expected: 0 matches.

Nota: igual que `PettyCashConstants`, `RefundConstants` está en `namespace App\Constants`. No hay import que remover.

- [ ] **Step 5: Borrar `STATUS_FIRMAS_APROBACION` de `NoveltyConstants`**

Edit en `src/Constants/NoveltyConstants.php`:

`old_string`:
```php
    // Backward compat for renamed status
    public const STATUS_FIRMAS_APROBACION = self::STATUS_REVISION_FIRMAS;

    public const PIPELINE_STATUSES = [
```

`new_string`:
```php
    public const PIPELINE_STATUSES = [
```

- [ ] **Step 6: Borrar bloque "Backward compat" final de `NoveltyConstants`**

Edit en `src/Constants/NoveltyConstants.php`:

`old_string`:
```php
    // Backward compat
    public const STATUS_PENDING = self::STATUS_REGISTRO;
    public const STATUS_APPROVED = self::STATUS_PAGADA;
    public const STATUS_REJECTED = self::STATUS_RECHAZADA;
    public const STATUSES = self::ALL_STATUSES;
}
```

`new_string`:
```php
}
```

- [ ] **Step 7: Verificar sintaxis de cada archivo tocado**

Run:
```bash
php -l src/Constants/PettyCashConstants.php
php -l src/Constants/RefundConstants.php
php -l src/Constants/NoveltyConstants.php
```

Expected: `No syntax errors detected in <file>` para cada uno.

- [ ] **Step 8: Verificar que no hay referencias rotas en el resto del código**

Run:
```bash
grep -rn "REGRESS_ROLE_BY_STATUS\|STATUS_FIRMAS_APROBACION\|NoveltyConstants::STATUS_PENDING\|NoveltyConstants::STATUS_APPROVED\|NoveltyConstants::STATUS_REJECTED\|NoveltyConstants::STATUSES\b" src/ templates/ config/ bin/ 2>/dev/null
```

Expected: 0 matches (excluyendo `STATUSES` que aparezca en otro contexto — verificar manualmente cualquier hit con `\b`).

- [ ] **Step 9: Ejecutar code style check**

Run: `composer cs-check`
Expected: PASS (si falla por estilo en archivos no relacionados, ignorar; si falla en los archivos tocados, ejecutar `composer cs-fix` y commitear el fix junto).

- [ ] **Step 10: Commit Tier 1**

```bash
git add src/Constants/PettyCashConstants.php src/Constants/RefundConstants.php src/Constants/NoveltyConstants.php
git commit -m "$(cat <<'EOF'
chore(cleanup): eliminar constantes huérfanas tras pipeline-permissions

Tier 1 (confirmadas seguras):
- PettyCashConstants::REGRESS_ROLE_BY_STATUS (@deprecated)
- RefundConstants::REGRESS_ROLE_BY_STATUS (@deprecated)
- NoveltyConstants::STATUS_FIRMAS_APROBACION (alias backward-compat)
- NoveltyConstants::STATUS_PENDING/APPROVED/REJECTED (aliases backward-compat)
- NoveltyConstants::STATUSES (alias backward-compat de ALL_STATUSES)

Cero callers externos verificado por grep en src/, templates/, config/, bin/.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Borrar constantes Tier 2 según veto del usuario

**Files:** depende de qué Tier 2 apruebe el usuario.

- [ ] **Step 1: Recoger veto del usuario para Tier 2**

A partir del mensaje del usuario tras Task 1 Step 3, identificar las constantes Tier 2 a borrar. Default sugerido: las 5 listadas.

- [ ] **Step 2: Borrar cada Tier 2 confirmada**

Para cada constante aprobada, abrir el archivo correspondiente y eliminar la línea `public const <CONSTANTE> = <valor>;` y su comentario adyacente si lo hay.

Ejemplo si se borra `InvoiceConstants::APPROVER_STATUSES`:

Edit en `src/Constants/InvoiceConstants.php`:

`old_string`:
```php
    public const APPROVER_STATUSES = [
        self::APPROVER_STATUS_PENDING,
        self::APPROVER_STATUS_APPROVED,
        self::APPROVER_STATUS_REJECTED,
        self::APPROVER_STATUS_SUPERSEDED,
    ];

    public const APPROVER_STATUSES_ACTIVE = [
```

`new_string`:
```php
    public const APPROVER_STATUSES_ACTIVE = [
```

(Hacer este patrón para cada Tier 2 confirmada.)

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l <archivo>` para cada archivo modificado.
Expected: `No syntax errors detected`.

- [ ] **Step 4: Verificar que no hay referencias rotas**

Run para cada constante borrada:
```bash
grep -rn "<ClassName>::<CONSTANT>\b" src/ templates/ config/ bin/ 2>/dev/null
```
Expected: 0 matches.

- [ ] **Step 5: Code style check**

Run: `composer cs-check`
Expected: PASS para los archivos tocados.

- [ ] **Step 6: Commit Tier 2 (solo si se borró algo)**

```bash
git add <archivos modificados>
git commit -m "$(cat <<'EOF'
chore(cleanup): eliminar constantes huérfanas Tier 2

Confirmadas por revisión manual (cero callers externos):
<lista de constantes borradas>

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

Si ningún Tier 2 fue aprobado, saltar este task.

---

## Task 4: AuthorizationService — bypass limitado a users/roles

**Files:**
- Modify: `src/Service/AuthorizationService.php:11-15` (agregar constante)
- Modify: `src/Service/AuthorizationService.php:48-53` (modificar isAllowed)

- [ ] **Step 1: Agregar la constante `ADMIN_BYPASS_MODULES`**

Edit en `src/Service/AuthorizationService.php`:

`old_string`:
```php
class AuthorizationService
{
    // Role name constants — reference centralized constants
    public const ROLE_ADMIN = RoleConstants::ADMIN;

    // Module constants (matching PermissionsTable::MODULES)
    public const MODULES = [
```

`new_string`:
```php
class AuthorizationService
{
    // Role name constants — reference centralized constants
    public const ROLE_ADMIN = RoleConstants::ADMIN;

    /**
     * Módulos donde Administrador conserva bypass automático. Para cualquier
     * otro módulo, el rol Administrador pasa por el lookup normal en la tabla
     * `permissions`. Cleanup post pipeline-permissions (2026-05-02).
     */
    public const ADMIN_BYPASS_MODULES = ['users', 'roles'];

    // Module constants (matching PermissionsTable::MODULES)
    public const MODULES = [
```

- [ ] **Step 2: Modificar `isAllowed()` para limitar el bypass**

Edit en `src/Service/AuthorizationService.php`:

`old_string`:
```php
    public function isAllowed(int $roleId, string $roleName, string $module, string $action): bool
    {
        // Admin bypasses all checks
        if ($roleName === self::ROLE_ADMIN) {
            return true;
        }

        $permissions = $this->getPermissionsForRole($roleId);
```

`new_string`:
```php
    public function isAllowed(int $roleId, string $roleName, string $module, string $action): bool
    {
        // Admin bypass solo para módulos en ADMIN_BYPASS_MODULES.
        if ($roleName === self::ROLE_ADMIN && in_array($module, self::ADMIN_BYPASS_MODULES, true)) {
            return true;
        }

        $permissions = $this->getPermissionsForRole($roleId);
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l src/Service/AuthorizationService.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Code style check**

Run: `composer cs-check src/Service/AuthorizationService.php` (o `composer cs-check` si el script no acepta argumento)
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/AuthorizationService.php
git commit -m "$(cat <<'EOF'
refactor(auth): bypass de Administrador limitado a users y roles

Centraliza la regla en AuthorizationService::ADMIN_BYPASS_MODULES.
Para cualquier otro módulo, Administrador pasa por la tabla permissions
como cualquier otro rol. Sin seed: el usuario configura permisos manuales.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: PipelineAuthorizationService — eliminar bypass

**Files:**
- Modify: `src/Service/PipelineAuthorizationService.php:31-40` (canOperate)
- Modify: `src/Service/PipelineAuthorizationService.php:48-61` (getOperableSteps)

- [ ] **Step 1: Eliminar bypass en `canOperate()`**

Edit en `src/Service/PipelineAuthorizationService.php`:

`old_string`:
```php
    public function canOperate(int $roleId, string $roleName, string $pipeline, string $step): bool
    {
        if ($roleName === RoleConstants::ADMIN) {
            return true;
        }

        $perms = $this->_loadForRole($roleId);

        return (bool)($perms[$pipeline][$step] ?? false);
    }
```

`new_string`:
```php
    public function canOperate(int $roleId, string $roleName, string $pipeline, string $step): bool
    {
        $perms = $this->_loadForRole($roleId);

        return (bool)($perms[$pipeline][$step] ?? false);
    }
```

- [ ] **Step 2: Eliminar bypass en `getOperableSteps()`**

Edit en `src/Service/PipelineAuthorizationService.php`:

`old_string`:
```php
    public function getOperableSteps(int $roleId, string $roleName, string $pipeline): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return PipelineStepConstants::STEPS_BY_PIPELINE[$pipeline] ?? [];
        }

        $perms = $this->_loadForRole($roleId);
        $stepsForPipeline = $perms[$pipeline] ?? [];

        return array_values(array_filter(
            PipelineStepConstants::STEPS_BY_PIPELINE[$pipeline] ?? [],
            static fn(string $step): bool => !empty($stepsForPipeline[$step]),
        ));
    }
```

`new_string`:
```php
    public function getOperableSteps(int $roleId, string $roleName, string $pipeline): array
    {
        $perms = $this->_loadForRole($roleId);
        $stepsForPipeline = $perms[$pipeline] ?? [];

        return array_values(array_filter(
            PipelineStepConstants::STEPS_BY_PIPELINE[$pipeline] ?? [],
            static fn(string $step): bool => !empty($stepsForPipeline[$step]),
        ));
    }
```

- [ ] **Step 3: Verificar que `RoleConstants` aún se importa por otro motivo**

Run: `grep -n "RoleConstants" src/Service/PipelineAuthorizationService.php`
Expected: 0 matches después del cambio.

Si 0 matches, eliminar el `use` correspondiente. Edit:

`old_string`:
```php
use App\Constants\PipelineStepConstants;
use App\Constants\RoleConstants;
use Cake\ORM\TableRegistry;
```

`new_string`:
```php
use App\Constants\PipelineStepConstants;
use Cake\ORM\TableRegistry;
```

- [ ] **Step 4: Notar que el parámetro `$roleName` queda sin uso en ambos métodos**

Decisión: **conservar el parámetro** para no romper la API pública (callers no requieren cambio). Documentarlo con docblock `@unused`. Edit:

`old_string`:
```php
    /**
     * @param int $roleId
     * @param string $roleName
     * @param string $pipeline
     * @param string $step
     * @return bool true si el rol puede operar el paso del pipeline.
     */
    public function canOperate(int $roleId, string $roleName, string $pipeline, string $step): bool
```

`new_string`:
```php
    /**
     * @param int $roleId
     * @param string $roleName Conservado para compat con callers; no se consulta tras cleanup 2026-05-02.
     * @param string $pipeline
     * @param string $step
     * @return bool true si el rol puede operar el paso del pipeline.
     */
    public function canOperate(int $roleId, string $roleName, string $pipeline, string $step): bool
```

`old_string`:
```php
    /**
     * @param int $roleId
     * @param string $roleName
     * @param string $pipeline
     * @return array<string> Pasos del pipeline donde el rol puede operar.
     */
    public function getOperableSteps(int $roleId, string $roleName, string $pipeline): array
```

`new_string`:
```php
    /**
     * @param int $roleId
     * @param string $roleName Conservado para compat con callers; no se consulta tras cleanup 2026-05-02.
     * @param string $pipeline
     * @return array<string> Pasos del pipeline donde el rol puede operar.
     */
    public function getOperableSteps(int $roleId, string $roleName, string $pipeline): array
```

- [ ] **Step 5: Verificar sintaxis**

Run: `php -l src/Service/PipelineAuthorizationService.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Code style check**

Run: `composer cs-check`
Expected: PASS para el archivo tocado.

- [ ] **Step 7: Commit**

```bash
git add src/Service/PipelineAuthorizationService.php
git commit -m "$(cat <<'EOF'
refactor(auth): eliminar bypass de Admin en PipelineAuthorizationService

Admin sin filas en pipeline_permissions no podrá operar pipelines.
Configuración manual por el usuario desde la UI de Roles.

Conservados los parámetros \$roleName en la API pública para no
romper callers; documentados como @unused.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: AppController — delegar bypass a AuthorizationService

**Files:**
- Modify: `src/Controller/AppController.php:130-146` (_setUserPermissions)
- Modify: `src/Controller/AppController.php:210-226` (_checkPermission)

- [ ] **Step 1: Refactorizar `_setUserPermissions()` con merge selectivo**

Edit en `src/Controller/AppController.php`:

`old_string`:
```php
    /**
     * Calculate and pass user permissions to all views for sidebar filtering.
     */
    protected function _setUserPermissions(object $user): void
    {
        $roleName = $this->_getUserRoleName($user);

        if ($roleName === AuthorizationService::ROLE_ADMIN) {
            // Admin sees everything
            $perms = [];
            foreach (array_keys(AuthorizationService::MODULES) as $module) {
                $perms[$module] = ['can_view' => true, 'can_create' => true, 'can_edit' => true, 'can_delete' => true];
            }
            $this->set('userPermissions', $perms);

            return;
        }

        $this->set('userPermissions', $this->authService->getPermissionsForRoleAsMatrix((int)$user->role_id));
    }
```

`new_string`:
```php
    /**
     * Calculate and pass user permissions to all views for sidebar filtering.
     *
     * Para Administrador, mergea ADMIN_BYPASS_MODULES con can_view/create/edit/delete=true
     * para que esos módulos sean siempre visibles en el sidebar aunque la BD
     * no tenga la fila correspondiente.
     */
    protected function _setUserPermissions(object $user): void
    {
        $roleName = $this->_getUserRoleName($user);
        $perms = $this->authService->getPermissionsForRoleAsMatrix((int)$user->role_id);

        if ($roleName === AuthorizationService::ROLE_ADMIN) {
            foreach (AuthorizationService::ADMIN_BYPASS_MODULES as $module) {
                $perms[$module] = [
                    'can_view' => true,
                    'can_create' => true,
                    'can_edit' => true,
                    'can_delete' => true,
                ];
            }
        }

        $this->set('userPermissions', $perms);
    }
```

- [ ] **Step 2: Eliminar bypass en `_checkPermission()`**

Edit en `src/Controller/AppController.php`:

`old_string`:
```php
    protected function _checkPermission(string $module, string $action): bool
    {
        $identity = $this->Authentication->getIdentity();
        if (!$identity) {
            return false;
        }

        $user = $identity->getOriginalData();
        $roleName = $this->_getUserRoleName($user);

        // Admin always allowed
        if ($roleName === AuthorizationService::ROLE_ADMIN) {
            return true;
        }

        return $this->authService->isAllowed((int)$user->role_id, $roleName, $module, $action);
    }
```

`new_string`:
```php
    protected function _checkPermission(string $module, string $action): bool
    {
        $identity = $this->Authentication->getIdentity();
        if (!$identity) {
            return false;
        }

        $user = $identity->getOriginalData();
        $roleName = $this->_getUserRoleName($user);

        return $this->authService->isAllowed((int)$user->role_id, $roleName, $module, $action);
    }
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l src/Controller/AppController.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Code style check**

Run: `composer cs-check`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/AppController.php
git commit -m "$(cat <<'EOF'
refactor(auth): AppController delega bypass a AuthorizationService

_setUserPermissions(): Admin recibe matriz real de permisos +
merge selectivo de ADMIN_BYPASS_MODULES (users, roles).

_checkPermission(): elimina bypass inline; isAllowed() centraliza
la regla.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Services — limpiar bypass redundantes en pipelines

**Files:**
- Modify: `src/Service/InvoicePipelineService.php` (líneas 182, 232)
- Modify: `src/Service/InvoiceFieldAccessPolicy.php` (líneas 76, 101, 144)
- Modify: `src/Service/InvoiceTransitionValidator.php` (línea 76)
- Modify: `src/Service/NoveltyPipelineService.php` (líneas 592, 615, 638)
- Modify: `src/Service/PaymentSchedulingPipelineService.php` (líneas 74, 92, 143)
- Modify: `src/Service/PettyCashService.php` (línea 451)
- Modify: `src/Service/RefundService.php` (línea 463)

- [ ] **Step 1: `InvoicePipelineService::canAdvance()` — eliminar bypass redundante**

Edit en `src/Service/InvoicePipelineService.php`:

`old_string`:
```php
    public function canAdvance(int $roleId, string $roleName, string $currentStatus, ?string $documentType = null): bool
    {
        if ($this->getNextStatus($currentStatus, $documentType) === null) {
            return false;
        }

        if ($roleName === RoleConstants::ADMIN) {
            return true;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_INVOICES,
            $currentStatus,
        );
    }
```

`new_string`:
```php
    public function canAdvance(int $roleId, string $roleName, string $currentStatus, ?string $documentType = null): bool
    {
        if ($this->getNextStatus($currentStatus, $documentType) === null) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_INVOICES,
            $currentStatus,
        );
    }
```

- [ ] **Step 2: `InvoicePipelineService::canRegress()` — eliminar bypass redundante**

Edit en `src/Service/InvoicePipelineService.php`:

`old_string`:
```php
    public function canRegress(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        if ($roleName === RoleConstants::ADMIN) {
            return true;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_INVOICES,
            $currentStatus,
        );
    }
```

`new_string`:
```php
    public function canRegress(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_INVOICES,
            $currentStatus,
        );
    }
```

- [ ] **Step 3: Confirmar que `RoleConstants` se sigue importando por otra razón**

Run: `grep -n "RoleConstants" src/Service/InvoicePipelineService.php`
Expected: 0 matches.

Si 0 matches, quitar el `use`. Edit:

`old_string`:
```php
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Constants\RoleConstants;
```

`new_string`:
```php
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
```

- [ ] **Step 4: `InvoiceFieldAccessPolicy::getEditableFields()` — eliminar bypass**

Edit en `src/Service/InvoiceFieldAccessPolicy.php`:

`old_string`:
```php
    public function getEditableFields(int $roleId, string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return self::ALL_FIELDS;
        }

        $allowedSteps = $this->pipelineAuth->getOperableSteps(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_INVOICES,
        );

        if (!in_array($status, $allowedSteps, true)) {
            return [];
        }

        return self::FIELDS_BY_STEP[$status] ?? [];
    }
```

`new_string`:
```php
    public function getEditableFields(int $roleId, string $roleName, string $status): array
    {
        $allowedSteps = $this->pipelineAuth->getOperableSteps(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_INVOICES,
        );

        if (!in_array($status, $allowedSteps, true)) {
            return [];
        }

        return self::FIELDS_BY_STEP[$status] ?? [];
    }
```

- [ ] **Step 5: `InvoiceFieldAccessPolicy::getVisibleSections()` — eliminar bypass + helper privado**

Edit en `src/Service/InvoiceFieldAccessPolicy.php`:

`old_string`:
```php
    public function getVisibleSections(int $roleId, string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return $this->_resolveAdminSections($status);
        }

        $sections = ['ledger'];

        $operableSteps = $this->pipelineAuth->getOperableSteps(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_INVOICES,
        );

        foreach ($operableSteps as $step) {
            if (isset(self::SECTION_BY_STEP[$step])) {
                $sections[] = self::SECTION_BY_STEP[$step];
            }
        }

        return array_values(array_unique($sections));
    }
```

`new_string`:
```php
    public function getVisibleSections(int $roleId, string $roleName, string $status): array
    {
        $sections = ['ledger'];

        $operableSteps = $this->pipelineAuth->getOperableSteps(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_INVOICES,
        );

        foreach ($operableSteps as $step) {
            if (isset(self::SECTION_BY_STEP[$step])) {
                $sections[] = self::SECTION_BY_STEP[$step];
            }
        }

        return array_values(array_unique($sections));
    }
```

- [ ] **Step 6: `InvoiceFieldAccessPolicy::filterEntityData()` — eliminar bypass**

Edit en `src/Service/InvoiceFieldAccessPolicy.php`:

`old_string`:
```php
    public function filterEntityData(array $data, int $roleId, string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return $data;
        }

        $allowed = $this->getEditableFields($roleId, $roleName, $status);

        return array_intersect_key($data, array_flip($allowed));
    }
```

`new_string`:
```php
    public function filterEntityData(array $data, int $roleId, string $roleName, string $status): array
    {
        $allowed = $this->getEditableFields($roleId, $roleName, $status);

        return array_intersect_key($data, array_flip($allowed));
    }
```

- [ ] **Step 7: `InvoiceFieldAccessPolicy` — borrar helpers privados ahora muertos**

Tras los cambios anteriores, `_resolveAdminSections()` y `_getStatusIndex()` ya no tienen callers. Edit:

`old_string`:
```php
    /**
     * @param string $status
     * @return array
     */
    private function _resolveAdminSections(string $status): array
    {
        $statusIndex = $this->_getStatusIndex($status);
        $sections = ['general', 'dates', 'classification', 'revision'];
        if ($statusIndex >= 1) {
            $sections[] = 'accounting';
        }
        if ($statusIndex >= 2) {
            $sections[] = 'treasury';
        }
        if ($statusIndex >= 3) {
            $sections[] = 'payment_authorization';
        }

        return $sections;
    }

    /**
     * @param string $status
     * @return int
     */
    private function _getStatusIndex(string $status): int
    {
        if ($status === InvoiceConstants::STATUS_LEGALIZADA) {
            return (int)array_search(InvoiceConstants::STATUS_CONTABILIDAD, InvoiceConstants::PIPELINE_STATUSES);
        }

        $index = array_search($status, InvoiceConstants::PIPELINE_STATUSES);

        return $index !== false ? (int)$index : 0;
    }
}
```

`new_string`:
```php
}
```

- [ ] **Step 8: Quitar import `RoleConstants` de `InvoiceFieldAccessPolicy`**

Run: `grep -n "RoleConstants" src/Service/InvoiceFieldAccessPolicy.php`
Expected: 0 matches.

Si 0 matches, quitar el `use`. Edit:

`old_string`:
```php
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Constants\RoleConstants;
```

`new_string`:
```php
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
```

- [ ] **Step 9: `InvoiceTransitionValidator::filterErrorsForRole()` — eliminar bypass**

Edit en `src/Service/InvoiceTransitionValidator.php`:

`old_string`:
```php
    public function filterErrorsForRole(array $errors, array $rules, int $roleId, string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return array_values($errors);
        }

        $editable = $this->fieldPolicy->getEditableFields($roleId, $roleName, $status);
        $statusVisible = in_array($roleName, $this->states->get($status)->getRoleVisibility(), true);
```

`new_string`:
```php
    public function filterErrorsForRole(array $errors, array $rules, int $roleId, string $roleName, string $status): array
    {
        $editable = $this->fieldPolicy->getEditableFields($roleId, $roleName, $status);
        $statusVisible = in_array($roleName, $this->states->get($status)->getRoleVisibility(), true);
```

- [ ] **Step 10: Quitar import `RoleConstants` de `InvoiceTransitionValidator`**

Run: `grep -n "RoleConstants" src/Service/InvoiceTransitionValidator.php`
Expected: 0 matches.

Si 0 matches, quitar el `use`. Edit:

`old_string`:
```php
use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\Pipeline\DocumentTypePolicyFactory;
```

`new_string`:
```php
use App\Constants\InvoiceConstants;
use App\Service\Pipeline\DocumentTypePolicyFactory;
```

- [ ] **Step 11: `NoveltyPipelineService::getEditableFields()` — eliminar bypass**

Edit en `src/Service/NoveltyPipelineService.php`:

`old_string`:
```php
    public function getEditableFields(int $roleId, string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return self::ALL_FIELDS;
        }

        if (
            !$this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_NOVELTIES,
                $status,
            )
        ) {
            return [];
        }

        return self::FIELDS_BY_STEP[$status] ?? [];
    }
```

`new_string`:
```php
    public function getEditableFields(int $roleId, string $roleName, string $status): array
    {
        if (
            !$this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_NOVELTIES,
                $status,
            )
        ) {
            return [];
        }

        return self::FIELDS_BY_STEP[$status] ?? [];
    }
```

- [ ] **Step 12: `NoveltyPipelineService::getVisibleSections()` — eliminar bypass**

Edit en `src/Service/NoveltyPipelineService.php`:

`old_string`:
```php
    public function getVisibleSections(int $roleId, string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return self::SECTIONS_BY_STATUS[$status] ?? self::ALL_SECTIONS;
        }

        $operableSteps = $this->pipelineAuth->getOperableSteps(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_NOVELTIES,
        );

        $sections = [];
        foreach ($operableSteps as $step) {
            $sections = array_merge($sections, self::SECTIONS_BY_STEP[$step] ?? []);
        }

        return array_values(array_unique($sections));
    }
```

`new_string`:
```php
    public function getVisibleSections(int $roleId, string $roleName, string $status): array
    {
        $operableSteps = $this->pipelineAuth->getOperableSteps(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_NOVELTIES,
        );

        $sections = [];
        foreach ($operableSteps as $step) {
            $sections = array_merge($sections, self::SECTIONS_BY_STEP[$step] ?? []);
        }

        return array_values(array_unique($sections));
    }
```

- [ ] **Step 13: `NoveltyPipelineService::canAdvanceFromStatus()` — eliminar bypass**

Edit en `src/Service/NoveltyPipelineService.php`:

`old_string`:
```php
    public function canAdvanceFromStatus(int $roleId, string $roleName, string $status): bool
    {
        if ($roleName === RoleConstants::ADMIN) {
            return true;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_NOVELTIES,
            $status,
        );
    }
```

`new_string`:
```php
    public function canAdvanceFromStatus(int $roleId, string $roleName, string $status): bool
    {
        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_NOVELTIES,
            $status,
        );
    }
```

> **Importante:** `RoleConstants` SIGUE siendo necesario en este archivo por las constantes `ROLE_VISIBLE_STATUSES` y `LIQUIDATION_VISIBLE_STATUSES`. NO quitar el import.

- [ ] **Step 14: `PaymentSchedulingPipelineService::canAdvance()` — eliminar bypass**

Edit en `src/Service/PaymentSchedulingPipelineService.php`:

`old_string`:
```php
    public function canAdvance(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ((self::TRANSITIONS[$currentStatus] ?? null) === null) {
            return false;
        }

        if ($roleName === RoleConstants::ADMIN) {
            return true;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            $currentStatus,
        );
    }
```

`new_string`:
```php
    public function canAdvance(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ((self::TRANSITIONS[$currentStatus] ?? null) === null) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            $currentStatus,
        );
    }
```

- [ ] **Step 15: `PaymentSchedulingPipelineService::canReject()` — eliminar bypass**

Edit en `src/Service/PaymentSchedulingPipelineService.php`:

`old_string`:
```php
    public function canReject(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ($currentStatus !== PaymentSchedulingConstants::STATUS_AUT_PAGO) {
            return false;
        }

        if ($roleName === RoleConstants::ADMIN) {
            return true;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            $currentStatus,
        );
    }
```

`new_string`:
```php
    public function canReject(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ($currentStatus !== PaymentSchedulingConstants::STATUS_AUT_PAGO) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            $currentStatus,
        );
    }
```

- [ ] **Step 16: `PaymentSchedulingPipelineService::canRegress()` — eliminar bypass**

Edit en `src/Service/PaymentSchedulingPipelineService.php`:

`old_string`:
```php
    public function canRegress(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        if ($roleName === RoleConstants::ADMIN) {
            return true;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            $currentStatus,
        );
    }
```

`new_string`:
```php
    public function canRegress(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            $currentStatus,
        );
    }
```

> **Importante:** `RoleConstants` SIGUE siendo necesario por `ROLE_VISIBLE_STATUSES`. NO quitar el import.

- [ ] **Step 17: `PettyCashService::canRegress()` — eliminar bypass**

Edit en `src/Service/PettyCashService.php`:

`old_string`:
```php
    public function canRegress(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        if ($roleName === RoleConstants::ADMIN) {
            return true;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PETTY_CASH,
            $currentStatus,
        );
    }
```

`new_string`:
```php
    public function canRegress(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PETTY_CASH,
            $currentStatus,
        );
    }
```

> **Importante:** `RoleConstants` SIGUE siendo necesario por `ROLE_VISIBLE_STATUSES`. NO quitar el import.

- [ ] **Step 18: `RefundService::canRegress()` — eliminar bypass**

Edit en `src/Service/RefundService.php`:

`old_string`:
```php
    public function canRegress(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        if ($roleName === RoleConstants::ADMIN) {
            return true;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_REFUNDS,
            $currentStatus,
        );
    }
```

`new_string`:
```php
    public function canRegress(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_REFUNDS,
            $currentStatus,
        );
    }
```

> **Importante:** `RoleConstants` SIGUE siendo necesario por `ROLE_VISIBLE_STATUSES`. NO quitar el import.

- [ ] **Step 19: Verificar sintaxis de cada archivo tocado**

Run:
```bash
php -l src/Service/InvoicePipelineService.php
php -l src/Service/InvoiceFieldAccessPolicy.php
php -l src/Service/InvoiceTransitionValidator.php
php -l src/Service/NoveltyPipelineService.php
php -l src/Service/PaymentSchedulingPipelineService.php
php -l src/Service/PettyCashService.php
php -l src/Service/RefundService.php
```

Expected: `No syntax errors detected` para cada uno.

- [ ] **Step 20: Code style check**

Run: `composer cs-check`
Expected: PASS para los archivos tocados (ignorar warnings preexistentes en otros archivos).

- [ ] **Step 21: Commit**

```bash
git add src/Service/InvoicePipelineService.php src/Service/InvoiceFieldAccessPolicy.php src/Service/InvoiceTransitionValidator.php src/Service/NoveltyPipelineService.php src/Service/PaymentSchedulingPipelineService.php src/Service/PettyCashService.php src/Service/RefundService.php
git commit -m "$(cat <<'EOF'
refactor(auth): eliminar bypass redundante de Admin en services de pipeline

Tras eliminar el bypass de Admin en PipelineAuthorizationService::canOperate(),
los wrappers (\$roleName === ADMIN) en los services callers son redundantes y
se simplifican. Comportamiento equivalente.

Behavior change para Admin: si no tiene pipeline_permissions configurados,
no podrá editar campos de facturas/novedades ni avanzar/regresar pipelines.
Configuración manual por el usuario.

Cambios complementarios:
- InvoiceFieldAccessPolicy: borrar helpers _resolveAdminSections() y
  _getStatusIndex() que quedan muertos.
- Imports de RoleConstants removidos donde ya no se usan
  (InvoicePipelineService, InvoiceFieldAccessPolicy, InvoiceTransitionValidator).
- Imports preservados donde aún se usan por ROLE_VISIBLE_STATUSES
  (NoveltyPipelineService, PaymentSchedulingPipelineService,
  PettyCashService, RefundService).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Controllers — limpiar bypass redundantes en pipelines

**Files:**
- Modify: `src/Controller/InvoicePaymentsController.php` (líneas 57, 106, 153, 193, 238)
- Modify: `src/Controller/LiquidationDocPaymentsController.php` (líneas 64, 105, 142)
- Modify: `src/Controller/RefundsController.php` (líneas 322, 329)
- Modify: `src/Controller/PettyCashRecordsController.php` (líneas 290, 297)
- Modify: `src/Controller/NoveltyLiquidationDocsController.php` (líneas 212, 219)
- Modify: `src/Controller/EmailLogsController.php` (línea 138)

> **Patrón:** En cada caso, eliminar `$roleName !== RoleConstants::ADMIN &&` del condicional, conservando el resto. El parámetro `$roleName` se sigue pasando a `canOperate()` (no se usa internamente, pero se preserva la API).

- [ ] **Step 1: `InvoicePaymentsController::addPayment()` — limpieza**

Edit en `src/Controller/InvoicePaymentsController.php`:

`old_string`:
```php
        if (
            $roleName !== RoleConstants::ADMIN
            && !(
                $this->pipelineAuth->canOperate(
                    $roleId,
                    $roleName,
                    PipelineStepConstants::PIPELINE_INVOICES,
                    InvoiceConstants::STATUS_TESORERIA,
                )
                && $currentStatus === InvoiceConstants::STATUS_TESORERIA
            )
        ) {
            $this->Flash->error('No tiene permisos para registrar pagos en este estado.');

            return $this->_redirectForInvoice($invoice, 'edit', $invoiceId);
        }
```

`new_string`:
```php
        if (
            !$this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_TESORERIA,
            )
            || $currentStatus !== InvoiceConstants::STATUS_TESORERIA
        ) {
            $this->Flash->error('No tiene permisos para registrar pagos en este estado.');

            return $this->_redirectForInvoice($invoice, 'edit', $invoiceId);
        }
```

- [ ] **Step 2: `InvoicePaymentsController::editPayment()` — limpieza**

Edit en `src/Controller/InvoicePaymentsController.php`:

`old_string`:
```php
        if (
            $roleName !== RoleConstants::ADMIN
            && !$this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_TESORERIA,
            )
        ) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->_redirectForInvoice((int)$invoiceId, 'edit', $invoiceId);
        }

        $data = array_intersect_key(
```

`new_string`:
```php
        if (
            !$this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_TESORERIA,
            )
        ) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->_redirectForInvoice((int)$invoiceId, 'edit', $invoiceId);
        }

        $data = array_intersect_key(
```

- [ ] **Step 3: `InvoicePaymentsController::authorizePayment()` — limpieza**

Edit en `src/Controller/InvoicePaymentsController.php`:

`old_string`:
```php
        if (
            $roleName !== RoleConstants::ADMIN
            && !$this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_AUTORIZACION_PAGO,
            )
        ) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->_redirectForInvoice((int)$invoiceId, 'edit', $invoiceId);
        }

        $result = $this->paymentService->authorizePayment((int)$paymentId, (int)$this->_getCurrentUser()->id);
```

`new_string`:
```php
        if (
            !$this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_AUTORIZACION_PAGO,
            )
        ) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->_redirectForInvoice((int)$invoiceId, 'edit', $invoiceId);
        }

        $result = $this->paymentService->authorizePayment((int)$paymentId, (int)$this->_getCurrentUser()->id);
```

- [ ] **Step 4: `InvoicePaymentsController::rejectPayment()` — limpieza**

Edit en `src/Controller/InvoicePaymentsController.php`:

`old_string`:
```php
        if (
            $roleName !== RoleConstants::ADMIN
            && !$this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_AUTORIZACION_PAGO,
            )
        ) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->_redirectForInvoice((int)$invoiceId, 'edit', $invoiceId);
        }

        $reason = (string)$this->request->getData('reason');
```

`new_string`:
```php
        if (
            !$this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_AUTORIZACION_PAGO,
            )
        ) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->_redirectForInvoice((int)$invoiceId, 'edit', $invoiceId);
        }

        $reason = (string)$this->request->getData('reason');
```

- [ ] **Step 5: `InvoicePaymentsController::deletePayment()` — limpieza**

Edit en `src/Controller/InvoicePaymentsController.php`:

`old_string`:
```php
        if (
            $roleName !== RoleConstants::ADMIN
            && !(
                $this->pipelineAuth->canOperate(
                    $roleId,
                    $roleName,
                    PipelineStepConstants::PIPELINE_INVOICES,
                    InvoiceConstants::STATUS_TESORERIA,
                )
                && $currentStatus === InvoiceConstants::STATUS_TESORERIA
            )
        ) {
            $this->Flash->error('No tiene permisos para eliminar pagos en este estado.');

            return $this->_redirectForInvoice($invoice, 'edit', $invoiceId);
        }
```

`new_string`:
```php
        if (
            !$this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_TESORERIA,
            )
            || $currentStatus !== InvoiceConstants::STATUS_TESORERIA
        ) {
            $this->Flash->error('No tiene permisos para eliminar pagos en este estado.');

            return $this->_redirectForInvoice($invoice, 'edit', $invoiceId);
        }
```

- [ ] **Step 6: Quitar import `RoleConstants` de `InvoicePaymentsController` si ya no se usa**

Run: `grep -n "RoleConstants" src/Controller/InvoicePaymentsController.php`
Expected: 0 matches.

Si 0 matches, quitar el `use` desde el header del archivo (revisar con `Read` los imports primero).

- [ ] **Step 7: `LiquidationDocPaymentsController::addPayment()` — limpieza**

Edit en `src/Controller/LiquidationDocPaymentsController.php`:

`old_string`:
```php
        if (
            $roleName !== RoleConstants::ADMIN
            && !$this->pipelineAuth->canOperate(
                $this->_getRoleId(),
                $roleName,
                PipelineStepConstants::PIPELINE_NOVELTIES,
                NoveltyConstants::STATUS_TESORERIA,
            )
        ) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
        }

        $result = $this->paymentService->registerPayment(
```

`new_string`:
```php
        if (
            !$this->pipelineAuth->canOperate(
                $this->_getRoleId(),
                $roleName,
                PipelineStepConstants::PIPELINE_NOVELTIES,
                NoveltyConstants::STATUS_TESORERIA,
            )
        ) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
        }

        $result = $this->paymentService->registerPayment(
```

- [ ] **Step 8: `LiquidationDocPaymentsController::authorizePayment()` — limpieza**

Edit en `src/Controller/LiquidationDocPaymentsController.php`:

`old_string`:
```php
        if (
            $roleName !== RoleConstants::ADMIN
            && !$this->pipelineAuth->canOperate(
                $this->_getRoleId(),
                $roleName,
                PipelineStepConstants::PIPELINE_NOVELTIES,
                NoveltyConstants::STATUS_AUT_PAGO,
            )
        ) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
        }

        $result = $this->paymentService->authorizePayment((int)$paymentId, (int)$this->_getCurrentUser()->id);
```

`new_string`:
```php
        if (
            !$this->pipelineAuth->canOperate(
                $this->_getRoleId(),
                $roleName,
                PipelineStepConstants::PIPELINE_NOVELTIES,
                NoveltyConstants::STATUS_AUT_PAGO,
            )
        ) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
        }

        $result = $this->paymentService->authorizePayment((int)$paymentId, (int)$this->_getCurrentUser()->id);
```

- [ ] **Step 9: `LiquidationDocPaymentsController::rejectPayment()` — limpieza**

Edit en `src/Controller/LiquidationDocPaymentsController.php`:

`old_string`:
```php
        if (
            $roleName !== RoleConstants::ADMIN
            && !$this->pipelineAuth->canOperate(
                $this->_getRoleId(),
                $roleName,
                PipelineStepConstants::PIPELINE_NOVELTIES,
                NoveltyConstants::STATUS_AUT_PAGO,
            )
        ) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
        }

        $result = $this->paymentService->rejectPayment((int)$paymentId, (int)$this->_getCurrentUser()->id);
```

`new_string`:
```php
        if (
            !$this->pipelineAuth->canOperate(
                $this->_getRoleId(),
                $roleName,
                PipelineStepConstants::PIPELINE_NOVELTIES,
                NoveltyConstants::STATUS_AUT_PAGO,
            )
        ) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
        }

        $result = $this->paymentService->rejectPayment((int)$paymentId, (int)$this->_getCurrentUser()->id);
```

- [ ] **Step 10: Quitar import `RoleConstants` de `LiquidationDocPaymentsController` si ya no se usa**

Run: `grep -n "RoleConstants" src/Controller/LiquidationDocPaymentsController.php`
Expected: 0 matches.

Si 0 matches, quitar el `use App\Constants\RoleConstants;` del header.

- [ ] **Step 11: `RefundsController` — `canRegisterPayment` y `canAuthorizePayment`**

Edit en `src/Controller/RefundsController.php`:

`old_string`:
```php
        $canRegisterPayment = $roleName === RoleConstants::ADMIN
            || $this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_REFUNDS,
                RefundConstants::STATUS_TESORERIA,
            );
        $canAuthorizePayment = $roleName === RoleConstants::ADMIN
            || $this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_REFUNDS,
                RefundConstants::STATUS_AUT_PAGO,
            );
```

`new_string`:
```php
        $canRegisterPayment = $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_REFUNDS,
            RefundConstants::STATUS_TESORERIA,
        );
        $canAuthorizePayment = $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_REFUNDS,
            RefundConstants::STATUS_AUT_PAGO,
        );
```

- [ ] **Step 12: Verificar si `RoleConstants` se usa aún en `RefundsController`**

Run: `grep -n "RoleConstants" src/Controller/RefundsController.php`
Expected: 0 matches (verificar; si hay otros usos, NO quitar el import).

Si 0 matches, quitar el `use App\Constants\RoleConstants;` del header.

- [ ] **Step 13: `PettyCashRecordsController` — `canRegisterPayment` y `canAuthorizePayment`**

Edit en `src/Controller/PettyCashRecordsController.php`:

`old_string`:
```php
        $canRegisterPayment = $roleName === RoleConstants::ADMIN
            || $this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_PETTY_CASH,
                PettyCashConstants::STATUS_TESORERIA,
            );
        $canAuthorizePayment = $roleName === RoleConstants::ADMIN
            || $this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_PETTY_CASH,
                PettyCashConstants::STATUS_AUT_PAGO,
            );
```

`new_string`:
```php
        $canRegisterPayment = $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PETTY_CASH,
            PettyCashConstants::STATUS_TESORERIA,
        );
        $canAuthorizePayment = $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PETTY_CASH,
            PettyCashConstants::STATUS_AUT_PAGO,
        );
```

- [ ] **Step 14: Verificar `RoleConstants` en `PettyCashRecordsController`**

Run: `grep -n "RoleConstants" src/Controller/PettyCashRecordsController.php`

Si 0 matches, quitar el `use App\Constants\RoleConstants;`.

- [ ] **Step 15: `NoveltyLiquidationDocsController` — `canOpTesoreria` y `canOpAutPago`**

Edit en `src/Controller/NoveltyLiquidationDocsController.php`:

`old_string`:
```php
        $canOpTesoreria = $roleName === RoleConstants::ADMIN
            || $this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_NOVELTIES,
                NoveltyConstants::STATUS_TESORERIA,
            );
        $canOpAutPago = $roleName === RoleConstants::ADMIN
            || $this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_NOVELTIES,
                NoveltyConstants::STATUS_AUT_PAGO,
            );
```

`new_string`:
```php
        $canOpTesoreria = $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_NOVELTIES,
            NoveltyConstants::STATUS_TESORERIA,
        );
        $canOpAutPago = $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_NOVELTIES,
            NoveltyConstants::STATUS_AUT_PAGO,
        );
```

- [ ] **Step 16: Verificar `RoleConstants` en `NoveltyLiquidationDocsController`**

Run: `grep -n "RoleConstants" src/Controller/NoveltyLiquidationDocsController.php`

Si 0 matches, quitar el `use App\Constants\RoleConstants;`.

- [ ] **Step 17: `EmailLogsController::_canRetry()` — eliminar bypass**

Edit en `src/Controller/EmailLogsController.php`:

`old_string`:
```php
    private function _canRetry(EmailLog $logRow): bool
    {
        $user = $this->Authentication->getIdentity()?->getOriginalData();
        if (!$user) {
            return false;
        }

        // Admin pasa siempre.
        $roleName = $this->_getUserRoleName($user);
        if ($roleName === AuthorizationService::ROLE_ADMIN) {
            return true;
        }

        // Resto: depende de la entidad.
        $roleId = (int)($user->role_id ?? 0);

        if ($logRow->entity_type === EmailLogConstants::ENTITY_INVOICE) {
            return $this->authService->isAllowed($roleId, $roleName, 'invoices', 'edit');
        }

        if ($logRow->entity_type === EmailLogConstants::ENTITY_NOVELTY) {
            return $this->authService->isAllowed($roleId, $roleName, 'employee_novelties', 'edit');
        }

        // Sin entity_type → solo admin (que ya retornó arriba).
        return false;
    }
```

`new_string`:
```php
    private function _canRetry(EmailLog $logRow): bool
    {
        $user = $this->Authentication->getIdentity()?->getOriginalData();
        if (!$user) {
            return false;
        }

        $roleName = $this->_getUserRoleName($user);
        $roleId = (int)($user->role_id ?? 0);

        if ($logRow->entity_type === EmailLogConstants::ENTITY_INVOICE) {
            return $this->authService->isAllowed($roleId, $roleName, 'invoices', 'edit');
        }

        if ($logRow->entity_type === EmailLogConstants::ENTITY_NOVELTY) {
            return $this->authService->isAllowed($roleId, $roleName, 'employee_novelties', 'edit');
        }

        // Sin entity_type → ningún rol autorizado (incluido Admin).
        return false;
    }
```

- [ ] **Step 18: Verificar si `AuthorizationService` aún se usa en `EmailLogsController`**

Run: `grep -n "AuthorizationService" src/Controller/EmailLogsController.php`
Expected: probablemente 1 match (la propiedad `$this->authService` viene de `AppController`). Confirmar con `Read` que el archivo aún usa `AuthorizationService::ROLE_ADMIN` en alguna otra línea (línea 138 ya quitada).

Si solo aparece en `use App\Service\AuthorizationService;` y ya no se referencia en el cuerpo del archivo, quitar el `use`.

- [ ] **Step 19: Verificar sintaxis de cada archivo tocado**

Run:
```bash
php -l src/Controller/InvoicePaymentsController.php
php -l src/Controller/LiquidationDocPaymentsController.php
php -l src/Controller/RefundsController.php
php -l src/Controller/PettyCashRecordsController.php
php -l src/Controller/NoveltyLiquidationDocsController.php
php -l src/Controller/EmailLogsController.php
```

Expected: `No syntax errors detected` para cada uno.

- [ ] **Step 20: Code style check**

Run: `composer cs-check`
Expected: PASS para los archivos tocados.

- [ ] **Step 21: Commit**

```bash
git add src/Controller/InvoicePaymentsController.php src/Controller/LiquidationDocPaymentsController.php src/Controller/RefundsController.php src/Controller/PettyCashRecordsController.php src/Controller/NoveltyLiquidationDocsController.php src/Controller/EmailLogsController.php
git commit -m "$(cat <<'EOF'
refactor(auth): eliminar bypass redundante de Admin en controllers

Tras eliminar el bypass de Admin en PipelineAuthorizationService::canOperate(),
los wrappers (\$roleName === ADMIN) en controllers son redundantes. Cambios
puramente sintácticos:
- InvoicePaymentsController: 5 acciones (addPayment, editPayment,
  authorizePayment, rejectPayment, deletePayment)
- LiquidationDocPaymentsController: 3 acciones
- RefundsController: 2 cómputos (canRegisterPayment, canAuthorizePayment)
- PettyCashRecordsController: 2 cómputos
- NoveltyLiquidationDocsController: 2 cómputos (canOpTesoreria, canOpAutPago)

EmailLogsController._canRetry(): elimina bypass de Admin; ahora delega
en AuthorizationService::isAllowed() que aplica la regla central
(Admin no tiene bypass para invoices ni employee_novelties).

Imports de RoleConstants/AuthorizationService removidos donde quedan sin uso.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: InvoicesController — bypass de bloqueos (behavior change)

**Files:**
- Modify: `src/Controller/InvoicesController.php` (líneas 244-249, 252-259, 393-400)

> **Importante:** Estos NO son bypass redundantes. Hoy son atajos explícitos para que Admin (1) edite facturas en estado terminal `pagada`/`legalizada`, y (2) ignore los locks por petty_cash o paid_scheduling. Eliminarlos cambia comportamiento: Admin pasa por las mismas reglas que cualquier otro rol. Per la directiva del usuario "el bypass del admin... despues en ninguno", se eliminan.

- [ ] **Step 1: `InvoicesController::edit()` — eliminar redirect-to-view de Admin en estados terminales**

Edit en `src/Controller/InvoicesController.php`:

`old_string`:
```php
        // Paid/legalized invoices are read-only for non-admin roles: redirect to view.
        $terminalStatuses = [InvoiceConstants::STATUS_PAGADA, InvoiceConstants::STATUS_LEGALIZADA];
        if (
            in_array($invoice->pipeline_status, $terminalStatuses, true)
            && $this->_getRoleName() !== RoleConstants::ADMIN
        ) {
            return $this->_redirectForInvoice($invoice, 'view', $id);
        }
```

`new_string`:
```php
        // Paid/legalized invoices are read-only para todos los roles: redirect a view.
        $terminalStatuses = [InvoiceConstants::STATUS_PAGADA, InvoiceConstants::STATUS_LEGALIZADA];
        if (in_array($invoice->pipeline_status, $terminalStatuses, true)) {
            return $this->_redirectForInvoice($invoice, 'view', $id);
        }
```

- [ ] **Step 2: `InvoicesController::edit()` — eliminar bypass de edit lock para Admin**

Edit en `src/Controller/InvoicesController.php`:

`old_string`:
```php
        // Unified lock: petty cash or paid scheduling (non-admin only).
        if ($this->_getRoleName() !== RoleConstants::ADMIN) {
            $lockMessage = $this->pipeline->getEditLockMessage($invoice);
            if ($lockMessage !== null) {
                $this->Flash->warning($lockMessage);

                return $this->_redirectForInvoice($invoice, 'view', $id);
            }
        }
```

`new_string`:
```php
        // Unified lock: petty cash or paid scheduling.
        $lockMessage = $this->pipeline->getEditLockMessage($invoice);
        if ($lockMessage !== null) {
            $this->Flash->warning($lockMessage);

            return $this->_redirectForInvoice($invoice, 'view', $id);
        }
```

- [ ] **Step 3: `InvoicesController::advanceStatus()` — eliminar bypass de edit lock**

Edit en `src/Controller/InvoicesController.php`:

`old_string`:
```php
        if ($this->_getRoleName() !== RoleConstants::ADMIN) {
            $lockMessage = $this->pipeline->getEditLockMessage($invoice);
            if ($lockMessage !== null) {
                $this->Flash->error($lockMessage);

                return $this->_redirectForInvoice($invoice, 'view', $id);
            }
        }
```

`new_string`:
```php
        $lockMessage = $this->pipeline->getEditLockMessage($invoice);
        if ($lockMessage !== null) {
            $this->Flash->error($lockMessage);

            return $this->_redirectForInvoice($invoice, 'view', $id);
        }
```

- [ ] **Step 4: Verificar uso restante de `RoleConstants` en `InvoicesController`**

Run: `grep -n "RoleConstants" src/Controller/InvoicesController.php`
Expected: 0 matches.

Si 0 matches, quitar el `use App\Constants\RoleConstants;` del header.

- [ ] **Step 5: Verificar sintaxis**

Run: `php -l src/Controller/InvoicesController.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Code style check**

Run: `composer cs-check`
Expected: PASS.

- [ ] **Step 7: Commit (con behavior change explícito en el mensaje)**

```bash
git add src/Controller/InvoicesController.php
git commit -m "$(cat <<'EOF'
refactor(auth): eliminar bypass de Admin en bloqueos de InvoicesController

BEHAVIOR CHANGE: Admin ya no puede editar facturas en estado terminal
(pagada/legalizada) — todos los roles redirigen a view. Admin tampoco
puede saltarse el edit lock por petty_cash o paid_scheduling — todos
los roles ven el lock.

Consistente con la directiva post pipeline-permissions: el rol
Administrador no tiene atajos especiales fuera de users/roles.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: Verificación final del cleanup

- [ ] **Step 1: Grep final de `RoleConstants::ADMIN` con whitelist explícita**

Run:
```bash
grep -rn "RoleConstants::ADMIN\|ROLE_ADMIN\b" src/Controller/ src/Service/ 2>/dev/null
```

**Expected (whitelist):**
- `src/Service/AuthorizationService.php`: definición de `ROLE_ADMIN` y referencia en `isAllowed()` (combinada con `ADMIN_BYPASS_MODULES`).
- `src/Service/Strategy/InvoiceApprovalStrategy.php`: callsite que pasa `RoleConstants::ADMIN` como `$roleId` a `saveAndAdvance()` (identidad del actor en flow externo, no bypass).
- `src/Service/NoveltyPipelineService.php`: keys `RoleConstants::ADMIN` en `ROLE_VISIBLE_STATUSES` y `LIQUIDATION_VISIBLE_STATUSES` (visibilidad de listados — fuera de alcance).
- `src/Service/PaymentSchedulingPipelineService.php`: key `RoleConstants::ADMIN` en `ROLE_VISIBLE_STATUSES` (visibilidad).
- `src/Service/PettyCashService.php`: key `RoleConstants::ADMIN` en `ROLE_VISIBLE_STATUSES` (visibilidad).
- `src/Service/RefundService.php`: key `RoleConstants::ADMIN` en `ROLE_VISIBLE_STATUSES` (visibilidad).
- `src/Service/Pipeline/State/AprobacionState.php`, `AutorizacionPagoState.php`, `ContabilidadState.php`, `LegalizadaState.php`, `PagadaState.php`, `TesoreriaState.php`: `RoleConstants::ADMIN` en `getRoleVisibility()` y `getAdvanceRoleVisibility()` (visibilidad — fuera de alcance).
- `src/Controller/AppController.php`: 1 referencia a `AuthorizationService::ROLE_ADMIN` en `_setUserPermissions()` (merge selectivo de bypass).

**Cualquier hit fuera de la whitelist es un error** que debe investigarse y corregirse antes de continuar.

- [ ] **Step 2: Grep final de imports `use App\Constants\RoleConstants;`**

Run:
```bash
grep -rn "use App\\\\Constants\\\\RoleConstants" src/Controller/ src/Service/ 2>/dev/null
```

**Expected:** Solo en archivos que aún usan `RoleConstants` por motivos legítimos (ver whitelist anterior). No debe haber imports huérfanos.

- [ ] **Step 3: Code style global**

Run: `composer cs-check`
Expected: PASS sin errores en los archivos del cleanup. Si hay errores en archivos no tocados por este plan, son preexistentes y se ignoran.

- [ ] **Step 4: Sintaxis global de los archivos tocados**

Run:
```bash
for f in src/Service/AuthorizationService.php src/Service/PipelineAuthorizationService.php src/Controller/AppController.php src/Service/InvoicePipelineService.php src/Service/InvoiceFieldAccessPolicy.php src/Service/InvoiceTransitionValidator.php src/Service/NoveltyPipelineService.php src/Service/PaymentSchedulingPipelineService.php src/Service/PettyCashService.php src/Service/RefundService.php src/Controller/InvoicePaymentsController.php src/Controller/LiquidationDocPaymentsController.php src/Controller/RefundsController.php src/Controller/PettyCashRecordsController.php src/Controller/NoveltyLiquidationDocsController.php src/Controller/EmailLogsController.php src/Controller/InvoicesController.php src/Constants/PettyCashConstants.php src/Constants/RefundConstants.php src/Constants/NoveltyConstants.php; do
  php -l "$f" || echo "FAIL: $f"
done
```

Expected: `No syntax errors detected` en todos.

- [ ] **Step 5: Borrar el archivo de auditoría temporal (cumplió su propósito)**

```bash
git rm docs/superpowers/audit-constants-2026-05-02.md
git commit -m "$(cat <<'EOF'
chore: remover reporte de auditoría temporal

El reporte cumplió su propósito en Tasks 2 y 3 del plan de cleanup
post pipeline-permissions. La información permanece en el historial
de commits.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 6: Entregar al usuario para validación manual**

Mensaje al usuario:

> "Cleanup completo. Para validar manualmente, ejecuta `php bin/cake server` y verifica:
>
> | # | Como | En | Acción esperada |
> |---|------|-----|-----------------|
> | 1 | Admin sin filas adicionales en `permissions` ni `pipeline_permissions` | `/users` y `/roles` | Acceso permitido |
> | 2 | Admin sin filas adicionales | Cualquier otro módulo (ej. `/invoices`, `/petty-cash-records`, `/refunds`) | 403 / sidebar oculto |
> | 3 | Admin con todos los permisos puestos manualmente desde `/roles/edit/<admin_id>` | Index de cada módulo | Carga normal |
> | 4 | Admin con `pipeline_permissions` configurados desde la UI | Edit de factura, advance, regress, payment authorization | Funciona como rol normal |
> | 5 | Admin sobre factura `pagada` o `legalizada` | `/invoices/edit/<id>` | Redirige a `/invoices/view/<id>` (behavior change Task 9) |
> | 6 | Admin sobre factura con `petty_cash` o `paid_scheduling` lock | `/invoices/edit/<id>` | Muestra warning de lock y redirige a view (behavior change Task 9) |
> | 7 | Rol no-admin (Contabilidad, Tesorería, Contador, etc.) — sin cambios esperados | Sus operaciones habituales | Comportamiento idéntico al previo al refactor |
>
> Cualquier regresión: revertir el commit aislado correspondiente (`git revert <sha>`)."

---

## Validación final del plan (self-review del autor)

**Cobertura del spec:**

- ✅ Sección 1 del spec (modelo de bypass) → Tasks 4-6.
- ✅ Sección 2 del spec (limpieza en cascada) → Tasks 7-9.
- ✅ Sección 3 del spec (constantes huérfanas) → Tasks 1-3.
- ✅ Out-of-scope respetado: visibility lists no se tocan.
- ✅ Sin seeds.
- ✅ Validación manual reemplaza tests.

**Riesgos cubiertos:**

- ✅ Admin sin permisos manuales → documentado en commit messages y validación manual.
- ✅ Constantes con uso indirecto por valor → Tier 3 conservado por default; Tier 2 con veto del usuario.
- ✅ Behavior change en InvoicesController → task aparte (Task 9) con commit message explícito.
- ✅ Whitelist final de `RoleConstants::ADMIN` → Step 1 de Task 10.

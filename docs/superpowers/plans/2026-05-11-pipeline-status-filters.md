# Migración de filtros de visibilidad por estado a `pipeline_permissions` — Plan de Implementación

> **Para workers agénticos:** SUB-SKILL REQUERIDA: usar `superpowers:subagent-driven-development` (recomendado) o `superpowers:executing-plans` para implementar este plan task por task. Los pasos usan sintaxis de checkbox (`- [ ]`).

**Goal:** Hacer que `pipeline_permissions` sea la única fuente de verdad para "qué estados ve cada rol" en los listados de los 6 módulos con pipeline, eliminando matrices hardcodeadas y dependencia de `RoleConstants` en los servicios de filtrado.

**Architecture:** Cada `*Service::getVisibleStatuses` se convierte en un adaptador delgado sobre `PipelineAuthorizationService::getOperableSteps($roleId, '', $pipeline)`. Se agrega un pipeline nuevo (`liquidation_docs`) al catálogo `PipelineStepConstants`. Una migration idempotente siembra `pipeline_permissions` desde las matrices actuales antes de eliminarlas. `InvoicesController` excluye `document_type='Anticipo'` del listado `index` para que la fusión de matrices (factura + anticipo) no introduzca cambios de comportamiento.

**Tech Stack:** CakePHP 5.3, PHP 8.4+, MySQL/MariaDB. Migrations vía `Migrations\BaseMigration`. Sin tests automatizados (ver CLAUDE.md § Testing Policy — validación manual).

---

## Spec de referencia

`docs/superpowers/specs/2026-05-11-pipeline-status-filters-design.md`

## Decisiones operativas heredadas del spec

- **Admin pasa por la tabla** (sin bypass hardcodeado). La migration siembra Admin con `can_operate=true` en todos los pares (pipeline, step) de `STEPS_BY_PIPELINE`.
- **Pipeline `invoices` para facturas y anticipos**: `InvoicesController::index` excluye `document_type='Anticipo'`. `AdvancesController` ya filtra por `document_type='Anticipo'`. La unión de matrices se siembra en pipeline `invoices`.
- **Pipeline `liquidation_docs` nuevo**: para documentos de liquidación de novedades.
- **Eliminar `getRoleVisibility`/`getAdvanceRoleVisibility`** de la interfaz `InvoicePipelineState` y de los 7 States en el mismo PR.
- **Eliminar `ROLE_VISIBLE_STATUSES`** en los 4 services pertinentes en el mismo PR.
- **Patrón de filtro vacío**: en cada controller, cambiar `!empty($visibleStatuses) ? ['IN' => $visibleStatuses] : []` a `['IN' => $visibleStatuses ?: ['__none__']]` SOLO en los endpoints que hoy filtran por visibleStatuses (los listados "Mis Registros"). Los endpoints `all/rejected/overdue` de Invoices no filtran por visibleStatuses hoy → no se tocan.

## Cambios de comportamiento aceptados (documentados)

1. **Estados terminales excluidos del listado "Mis Registros"** (efecto del catálogo):
   - Hoy `PagadaState::getRoleVisibility() = [ADMIN]` → Admin ve facturas `pagada` en `/invoices`.
   - Después: `pagada` y `legalizada` están fuera de `STEPS_BY_PIPELINE['invoices']` → Admin las verá en `/invoices/all` pero NO en `/invoices`.
   - Lo mismo aplica a Tesorería viendo schedulings `pagada` en `/payment-schedulings`.
   - Si el usuario quiere preservar, deberá agregarlas explícitamente a `STEPS_BY_PIPELINE` en una iteración futura.

2. **Patrón "lista vacía → ver todo" eliminado**: si un rol no tiene permisos sembrados, los listados quedan vacíos en lugar de mostrar todo.

## Mapa de archivos

**Crear:**
- `config/Migrations/YYYYMMDDhhmmss_SeedPipelinePermissionsFromRoleMatrices.php`

**Modificar:**
- `src/Constants/PipelineStepConstants.php` — añadir `PIPELINE_LIQUIDATION_DOCS`
- `src/Service/InvoicePipelineService.php` — `getVisibleStatuses`, `getVisibleAdvanceStatuses`
- `src/Service/NoveltyService.php` — `getVisibleStatuses`, `getVisibleLiquidationStatuses`, borrar matrices
- `src/Service/PaymentSchedulingService.php` — `getVisibleStatuses`, borrar matriz
- `src/Service/PettyCashService.php` — `getVisibleStatuses`, borrar matriz
- `src/Service/RefundService.php` — `getVisibleStatuses`, borrar matriz
- `src/Service/Pipeline/Invoice/InvoicePipelineState.php` — interfaz, borrar 2 métodos
- `src/Service/Pipeline/Invoice/State/AprobacionState.php` — borrar 2 métodos
- `src/Service/Pipeline/Invoice/State/ContabilidadState.php` — borrar 2 métodos
- `src/Service/Pipeline/Invoice/State/TesoreriaState.php` — borrar 2 métodos
- `src/Service/Pipeline/Invoice/State/AutorizacionPagoState.php` — borrar 2 métodos
- `src/Service/Pipeline/Invoice/State/VerificacionPagoState.php` — borrar 2 métodos
- `src/Service/Pipeline/Invoice/State/PagadaState.php` — borrar 2 métodos
- `src/Service/Pipeline/Invoice/State/LegalizadaState.php` — borrar 2 métodos
- `src/Controller/InvoicesController.php` — `index` (+exclusión anticipos), passar `roleId`
- `src/Controller/AdvancesController.php` — `index`, pasar `roleId`
- `src/Controller/RefundsController.php` — `index`, pasar `roleId`
- `src/Controller/PettyCashRecordsController.php` — `index`, pasar `roleId`
- `src/Controller/PaymentSchedulingsController.php` — `index`, pasar `roleId`
- `src/Controller/NoveltyLiquidationDocsController.php` — `index`, pasar `roleId`
- `src/Controller/EmployeeNoveltiesController.php` — `index`, pasar `roleId`
- `src/Service/SidebarCounterService.php` — 5 callsites
- `src/Service/PendingNotificationsService.php` — 1 callsite

**No tocar** (verificados como dinámicos):
- `templates/Roles/edit.php` y `templates/Roles/add.php` — iteran `$pipelineLabels` dinámicamente.
- `src/Controller/RolesController.php` — pasa `PIPELINE_LABELS`/`STEP_LABELS` directamente.

---

## Task 1: Agregar `PIPELINE_LIQUIDATION_DOCS` al catálogo

**Files:**
- Modify: `src/Constants/PipelineStepConstants.php`

- [ ] **Step 1: Agregar la constante, label, steps y step labels**

Editar `src/Constants/PipelineStepConstants.php`:

Después de `public const PIPELINE_LEGALIZATIONS = 'legalizations';` (línea ~20), agregar:
```php
    public const PIPELINE_LIQUIDATION_DOCS = 'liquidation_docs';
```

En `PIPELINE_LABELS` después de `self::PIPELINE_LEGALIZATIONS => 'Legalizaciones',`, agregar:
```php
        self::PIPELINE_LIQUIDATION_DOCS => 'Documentos de liquidación',
```

En `STEPS_BY_PIPELINE` después del bloque de `PIPELINE_LEGALIZATIONS`, agregar:
```php
        self::PIPELINE_LIQUIDATION_DOCS => [
            NoveltyConstants::STATUS_CONTABILIDAD,
            NoveltyConstants::STATUS_REVISION_FIRMAS,
            NoveltyConstants::STATUS_GDP,
            NoveltyConstants::STATUS_TESORERIA,
            NoveltyConstants::STATUS_AUTORIZACION_PAGO,
            NoveltyConstants::STATUS_VERIFICACION_PAGO,
        ],
```

En `STEP_LABELS` después del bloque de `PIPELINE_LEGALIZATIONS`, agregar:
```php
        self::PIPELINE_LIQUIDATION_DOCS => [
            NoveltyConstants::STATUS_CONTABILIDAD => 'Contabilidad',
            NoveltyConstants::STATUS_REVISION_FIRMAS => 'Revisión y Firmas',
            NoveltyConstants::STATUS_GDP => 'GDP',
            NoveltyConstants::STATUS_TESORERIA => 'Tesorería',
            NoveltyConstants::STATUS_AUTORIZACION_PAGO => 'Autorización de pago',
            NoveltyConstants::STATUS_VERIFICACION_PAGO => 'Verificación de pago',
        ],
```

- [ ] **Step 2: Verificar code style**

Run: `composer cs-check`
Expected: sin violaciones.

- [ ] **Step 3: Verificar que la UI de Roles muestra la nueva sección**

Levantar `php bin/cake server`, login como Admin, navegar a `/roles/edit/{id}`, verificar que aparece una sección "Documentos de liquidación" con los 6 checkboxes (todos desmarcados — todavía no sembrado).

- [ ] **Step 4: Commit**

```bash
git add src/Constants/PipelineStepConstants.php
git commit -m "feat(pipeline): agregar pipeline liquidation_docs al catalogo de permisos"
```

---

## Task 2: Migration de seed de `pipeline_permissions`

**Files:**
- Create: `config/Migrations/YYYYMMDDhhmmss_SeedPipelinePermissionsFromRoleMatrices.php`

- [ ] **Step 1: Generar el archivo de migration**

```bash
php bin/cake migrations create SeedPipelinePermissionsFromRoleMatrices
```

CakePHP crea el archivo en `config/Migrations/<timestamp>_SeedPipelinePermissionsFromRoleMatrices.php` con un esqueleto basado en `BaseMigration`.

- [ ] **Step 2: Reemplazar el contenido del archivo**

Reemplazar todo el contenido del archivo creado por:

```php
<?php
declare(strict_types=1);

use Cake\ORM\TableRegistry;
use Migrations\BaseMigration;

/**
 * Siembra pipeline_permissions con la matriz histórica de visibilidad de
 * cada rol en cada pipeline. Reemplaza las matrices hardcodeadas
 * ROLE_VISIBLE_STATUSES (en *Service) y getRoleVisibility/
 * getAdvanceRoleVisibility (en los Invoice States) por filas de tabla.
 *
 * Idempotente: si una fila (role_id, pipeline, step) ya existe, NO la
 * sobreescribe. Esto preserva configuración manual hecha por admin
 * después del primer deploy.
 *
 * Down: no-op. Revertir borraría configuración del admin.
 */
class SeedPipelinePermissionsFromRoleMatrices extends BaseMigration
{
    /**
     * Mapa rol → pipeline → steps con can_operate=true.
     * Las claves de rol son los nombres canónicos en la tabla `roles`.
     */
    private const SEED = [
        // ========== PIPELINE invoices ==========
        // Unión de getRoleVisibility() y getAdvanceRoleVisibility() de cada InvoicePipelineState.
        // Salen `pagada` y `legalizada` (no están en STEPS_BY_PIPELINE['invoices']).
        'Administrador' => [
            'invoices' => ['aprobacion', 'contabilidad', 'tesoreria', 'autorizacion_pago', 'verificacion_pago'],
            'novelties' => [
                'aprobacion', 'rrhh', 'contabilidad', 'revision_firmas', 'gdp',
                'tesoreria', 'autorizacion_pago', 'verificacion_pago',
            ],
            'payment_schedulings' => ['borrador', 'tesoreria', 'autorizacion_pago', 'verificacion_pago'],
            'refunds' => ['agrupacion', 'contabilidad', 'tesoreria', 'autorizacion_pago', 'verificacion_pago'],
            'petty_cash' => ['agrupacion', 'contabilidad', 'tesoreria', 'autorizacion_pago', 'verificacion_pago'],
            'legalizations' => [
                'validacion', 'revision_firmas', 'contabilidad', 'tesoreria',
                'autorizacion_pago', 'verificacion_pago',
            ],
            'liquidation_docs' => [
                'contabilidad', 'revision_firmas', 'gdp', 'tesoreria',
                'autorizacion_pago', 'verificacion_pago',
            ],
        ],
        'Registro/Revisión' => [
            'invoices' => ['aprobacion'],
            'refunds' => ['agrupacion'],
            'petty_cash' => ['agrupacion'],
            'liquidation_docs' => ['revision_firmas', 'gdp'],
        ],
        'Contabilidad' => [
            'invoices' => ['contabilidad'],
            'novelties' => ['contabilidad'],
            'refunds' => ['contabilidad'],
            'petty_cash' => ['contabilidad'],
            'liquidation_docs' => ['contabilidad'],
        ],
        'Tesorería' => [
            'invoices' => ['tesoreria', 'autorizacion_pago', 'verificacion_pago'],
            'novelties' => ['tesoreria', 'autorizacion_pago', 'verificacion_pago'],
            'payment_schedulings' => ['borrador', 'tesoreria', 'autorizacion_pago', 'verificacion_pago'],
            // 'pagada' del scheduling se omite (no está en STEPS_BY_PIPELINE).
            'refunds' => ['tesoreria', 'autorizacion_pago', 'verificacion_pago'],
            'petty_cash' => ['tesoreria', 'autorizacion_pago', 'verificacion_pago'],
            'liquidation_docs' => ['tesoreria', 'autorizacion_pago', 'verificacion_pago'],
        ],
        'Contador' => [
            'invoices' => ['autorizacion_pago', 'verificacion_pago'],
            'novelties' => ['revision_firmas', 'autorizacion_pago', 'verificacion_pago'],
            'payment_schedulings' => ['autorizacion_pago', 'verificacion_pago'],
            'refunds' => ['autorizacion_pago', 'verificacion_pago'],
            'petty_cash' => ['autorizacion_pago', 'verificacion_pago'],
            'liquidation_docs' => ['autorizacion_pago', 'verificacion_pago'],
        ],
        // Auxiliar/Asistente/Coordinador: anticipos (todos los steps activos de invoices),
        // novedades, reintegros activos, caja menor activa, liquidaciones activas.
        'Auxiliar de Personal' => [
            'invoices' => ['aprobacion', 'contabilidad', 'tesoreria', 'autorizacion_pago', 'verificacion_pago'],
            'novelties' => ['aprobacion', 'rrhh', 'revision_firmas', 'gdp'],
            'refunds' => ['agrupacion', 'contabilidad', 'tesoreria', 'autorizacion_pago', 'verificacion_pago'],
            'petty_cash' => ['agrupacion', 'contabilidad', 'tesoreria', 'autorizacion_pago', 'verificacion_pago'],
            'liquidation_docs' => [
                'contabilidad', 'revision_firmas', 'gdp', 'tesoreria',
                'autorizacion_pago', 'verificacion_pago',
            ],
        ],
        'Asistente de Personal' => [
            'invoices' => ['aprobacion', 'contabilidad', 'tesoreria', 'autorizacion_pago', 'verificacion_pago'],
            'novelties' => ['aprobacion', 'rrhh', 'revision_firmas', 'gdp'],
            'refunds' => ['agrupacion', 'contabilidad', 'tesoreria', 'autorizacion_pago', 'verificacion_pago'],
            'petty_cash' => ['agrupacion', 'contabilidad', 'tesoreria', 'autorizacion_pago', 'verificacion_pago'],
            'liquidation_docs' => [
                'contabilidad', 'revision_firmas', 'gdp', 'tesoreria',
                'autorizacion_pago', 'verificacion_pago',
            ],
        ],
        'Coordinador Administrativo y Financiero' => [
            'invoices' => ['aprobacion', 'contabilidad', 'tesoreria', 'autorizacion_pago', 'verificacion_pago'],
            'novelties' => ['revision_firmas'],
            'refunds' => ['agrupacion', 'contabilidad', 'tesoreria', 'autorizacion_pago', 'verificacion_pago'],
            'petty_cash' => ['agrupacion', 'contabilidad', 'tesoreria', 'autorizacion_pago', 'verificacion_pago'],
            'liquidation_docs' => [
                'contabilidad', 'revision_firmas', 'gdp', 'tesoreria',
                'autorizacion_pago', 'verificacion_pago',
            ],
        ],
    ];

    public function up(): void
    {
        $roles = TableRegistry::getTableLocator()->get('Roles');
        $perms = TableRegistry::getTableLocator()->get('PipelinePermissions');

        foreach (self::SEED as $roleName => $pipelineMap) {
            $role = $roles->find()->where(['name' => $roleName])->first();
            if ($role === null) {
                // Rol no existe (renombrado/borrado). No fatal: log y continúa.
                error_log(sprintf(
                    '[SeedPipelinePermissionsFromRoleMatrices] Rol "%s" no encontrado en tabla roles. Skipping.',
                    $roleName,
                ));
                continue;
            }

            foreach ($pipelineMap as $pipeline => $steps) {
                foreach ($steps as $step) {
                    $existing = $perms->find()
                        ->where([
                            'role_id' => (int)$role->id,
                            'pipeline' => $pipeline,
                            'step' => $step,
                        ])
                        ->first();

                    if ($existing !== null) {
                        // Idempotencia: respetar la configuración existente.
                        continue;
                    }

                    $entity = $perms->newEntity([
                        'role_id' => (int)$role->id,
                        'pipeline' => $pipeline,
                        'step' => $step,
                        'can_operate' => true,
                    ]);
                    $perms->save($entity);
                }
            }
        }
    }

    public function down(): void
    {
        // No-op. Revertir borraría configuración del admin.
        // Para limpiar manualmente: DELETE FROM pipeline_permissions WHERE ...
    }
}
```

- [ ] **Step 3: Ejecutar la migration**

Run: `php bin/cake migrations migrate`
Expected: `== <timestamp> SeedPipelinePermissionsFromRoleMatrices: migrating` seguido de `migrated`.

- [ ] **Step 4: Verificar el seed en la BD**

```sql
SELECT r.name, pp.pipeline, COUNT(*) AS steps
FROM pipeline_permissions pp
JOIN roles r ON r.id = pp.role_id
WHERE pp.can_operate = 1
GROUP BY r.name, pp.pipeline
ORDER BY r.name, pp.pipeline;
```

Resultado esperado: Administrador con 7 pipelines, otros roles con los pipelines/steps según la matriz.

- [ ] **Step 5: Verificar idempotencia**

Run: `php bin/cake migrations migrate` (segunda vez — no debería pasar nada porque ya está marcada).
Run manualmente:
```bash
php -r "
require 'vendor/autoload.php';
require 'config/bootstrap.php';
\$app = new App\Application(dirname(__DIR__).'/config');
\$app->bootstrap();
\$count = \Cake\ORM\TableRegistry::getTableLocator()->get('PipelinePermissions')->find()->count();
echo 'Total rows: ' . \$count . PHP_EOL;
"
```
O simplemente: `SELECT COUNT(*) FROM pipeline_permissions;` antes/después de un nuevo intento de seed manual (re-marcar migration como pending y volver a ejecutar). El conteo no debe cambiar.

- [ ] **Step 6: Commit**

```bash
git add config/Migrations/*SeedPipelinePermissionsFromRoleMatrices.php
git commit -m "feat(migrations): sembrar pipeline_permissions desde matrices hardcodeadas"
```

---

## Task 3: Refactorizar `InvoicePipelineService`

**Files:**
- Modify: `src/Service/InvoicePipelineService.php`

- [ ] **Step 1: Reemplazar `getVisibleStatuses` y `getVisibleAdvanceStatuses`**

Localizar el método actual:
```php
public function getVisibleStatuses(string $roleName): array
{
    $result = [];
    foreach ($this->states->all() as $name => $state) {
        if (in_array($roleName, $state->getRoleVisibility(), true)) {
            $result[] = $name;
        }
    }

    return $result;
}

public function getVisibleAdvanceStatuses(string $roleName): array
{
    $result = [];
    foreach ($this->states->all() as $name => $state) {
        if ($name === InvoiceConstants::STATUS_PAGADA || $name === InvoiceConstants::STATUS_LEGALIZADA) {
            continue;
        }
        if (in_array($roleName, $state->getAdvanceRoleVisibility(), true)) {
            $result[] = $name;
        }
    }

    return $result;
}
```

Reemplazar por:
```php
public function getVisibleStatuses(int $roleId): array
{
    return $this->pipelineAuth->getOperableSteps(
        $roleId,
        '',
        PipelineStepConstants::PIPELINE_INVOICES,
    );
}

public function getVisibleAdvanceStatuses(int $roleId): array
{
    return $this->pipelineAuth->getOperableSteps(
        $roleId,
        '',
        PipelineStepConstants::PIPELINE_INVOICES,
    );
}
```

(Los terminales `pagada`/`legalizada` están fuera de `STEPS_BY_PIPELINE['invoices']`, así que ya no se necesita la guarda manual.)

- [ ] **Step 2: Verificar code style**

Run: `composer cs-check`
Expected: sin violaciones.

- [ ] **Step 3: Commit**

```bash
git add src/Service/InvoicePipelineService.php
git commit -m "refactor(invoice): delegar getVisibleStatuses a PipelineAuthorizationService"
```

---

## Task 4: Refactorizar `NoveltyService`

**Files:**
- Modify: `src/Service/NoveltyService.php`

- [ ] **Step 1: Reemplazar el cuerpo de `getVisibleStatuses` y `getVisibleLiquidationStatuses`**

Localizar:
```php
public function getVisibleStatuses(string $roleName): array
{
    return self::ROLE_VISIBLE_STATUSES[$roleName] ?? [];
}
```

Reemplazar por:
```php
public function getVisibleStatuses(int $roleId): array
{
    return $this->pipelineAuth->getOperableSteps(
        $roleId,
        '',
        PipelineStepConstants::PIPELINE_NOVELTIES,
    );
}
```

Localizar:
```php
public function getVisibleLiquidationStatuses(string $roleName): array
{
    return self::LIQUIDATION_VISIBLE_STATUSES[$roleName] ?? [];
}
```

Reemplazar por:
```php
public function getVisibleLiquidationStatuses(int $roleId): array
{
    return $this->pipelineAuth->getOperableSteps(
        $roleId,
        '',
        PipelineStepConstants::PIPELINE_LIQUIDATION_DOCS,
    );
}
```

- [ ] **Step 2: Eliminar las constantes `ROLE_VISIBLE_STATUSES`, `LIQUIDATION_ACTIVE_STATUSES` y `LIQUIDATION_VISIBLE_STATUSES`**

Borrar los bloques completos `private const ROLE_VISIBLE_STATUSES = [...];`, `private const LIQUIDATION_ACTIVE_STATUSES = [...];` y `private const LIQUIDATION_VISIBLE_STATUSES = [...];`.

- [ ] **Step 3: Limpiar imports huérfanos**

Buscar `use App\Constants\RoleConstants;` en el archivo. Si después de borrar las matrices no queda otro uso de `RoleConstants::` en el archivo, eliminar el import.

Run: `grep -c "RoleConstants" src/Service/NoveltyService.php`
- Si retorna `0`: borrar el `use App\Constants\RoleConstants;`.
- Si retorna `>0` pero todas las referencias están comentadas: revisar y limpiar.

- [ ] **Step 4: Verificar que `PipelineStepConstants` está importado**

Buscar `use App\Constants\PipelineStepConstants;`. Si no está, agregarlo al bloque de imports (NoveltyService ya lo usa para `canOperate`, así que probablemente ya esté).

- [ ] **Step 5: Verificar code style**

Run: `composer cs-check`
Expected: sin violaciones.

- [ ] **Step 6: Commit**

```bash
git add src/Service/NoveltyService.php
git commit -m "refactor(novelty): delegar getVisibleStatuses/getVisibleLiquidationStatuses a pipeline_permissions"
```

---

## Task 5: Refactorizar `PaymentSchedulingService`

**Files:**
- Modify: `src/Service/PaymentSchedulingService.php`

- [ ] **Step 1: Reemplazar `getVisibleStatuses`**

Localizar:
```php
public function getVisibleStatuses(string $roleName): array
{
    return self::ROLE_VISIBLE_STATUSES[$roleName] ?? [];
}
```

Reemplazar por:
```php
public function getVisibleStatuses(int $roleId): array
{
    return $this->pipelineAuth->getOperableSteps(
        $roleId,
        '',
        PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
    );
}
```

- [ ] **Step 2: Eliminar la constante `ROLE_VISIBLE_STATUSES`**

Borrar el bloque completo `private const ROLE_VISIBLE_STATUSES = [...];`.

- [ ] **Step 3: Limpiar import de `RoleConstants` si queda huérfano**

Run: `grep -c "RoleConstants" src/Service/PaymentSchedulingService.php`
- Si retorna `0`: borrar el `use App\Constants\RoleConstants;`.

- [ ] **Step 4: Verificar code style**

Run: `composer cs-check`
Expected: sin violaciones.

- [ ] **Step 5: Commit**

```bash
git add src/Service/PaymentSchedulingService.php
git commit -m "refactor(scheduling): delegar getVisibleStatuses a pipeline_permissions"
```

---

## Task 6: Refactorizar `PettyCashService`

**Files:**
- Modify: `src/Service/PettyCashService.php`

- [ ] **Step 1: Reemplazar `getVisibleStatuses`**

Localizar:
```php
public function getVisibleStatuses(string $roleName): array
{
    return self::ROLE_VISIBLE_STATUSES[$roleName] ?? [];
}
```

Reemplazar por:
```php
public function getVisibleStatuses(int $roleId): array
{
    return $this->pipelineAuth->getOperableSteps(
        $roleId,
        '',
        PipelineStepConstants::PIPELINE_PETTY_CASH,
    );
}
```

- [ ] **Step 2: Eliminar las constantes `ROLE_VISIBLE_STATUSES` y `ACTIVE_STATUSES`**

Borrar ambos bloques de constantes.

- [ ] **Step 3: Limpiar import de `RoleConstants` si queda huérfano**

Run: `grep -c "RoleConstants" src/Service/PettyCashService.php`
- Si retorna `0`: borrar el `use App\Constants\RoleConstants;`.

- [ ] **Step 4: Verificar code style**

Run: `composer cs-check`
Expected: sin violaciones.

- [ ] **Step 5: Commit**

```bash
git add src/Service/PettyCashService.php
git commit -m "refactor(petty-cash): delegar getVisibleStatuses a pipeline_permissions"
```

---

## Task 7: Refactorizar `RefundService`

**Files:**
- Modify: `src/Service/RefundService.php`

- [ ] **Step 1: Reemplazar `getVisibleStatuses`**

Localizar:
```php
public function getVisibleStatuses(string $roleName): array
{
    return self::ROLE_VISIBLE_STATUSES[$roleName] ?? [];
}
```

Reemplazar por:
```php
public function getVisibleStatuses(int $roleId): array
{
    return $this->pipelineAuth->getOperableSteps(
        $roleId,
        '',
        PipelineStepConstants::PIPELINE_REFUNDS,
    );
}
```

- [ ] **Step 2: Eliminar las constantes `ROLE_VISIBLE_STATUSES` y `ACTIVE_STATUSES`**

Borrar ambos bloques de constantes.

- [ ] **Step 3: Limpiar import de `RoleConstants` si queda huérfano**

Run: `grep -c "RoleConstants" src/Service/RefundService.php`
- Si retorna `0`: borrar el `use App\Constants\RoleConstants;`.

- [ ] **Step 4: Verificar code style**

Run: `composer cs-check`
Expected: sin violaciones.

- [ ] **Step 5: Commit**

```bash
git add src/Service/RefundService.php
git commit -m "refactor(refund): delegar getVisibleStatuses a pipeline_permissions"
```

---

## Task 8: Actualizar `InvoicesController` (con exclusión de anticipos)

**Files:**
- Modify: `src/Controller/InvoicesController.php`

- [ ] **Step 1: Cambiar `index()` para pasar `roleId` y excluir anticipos**

Localizar la función `public function index()` (línea ~70). En el cuerpo actual:
```php
$roleName = $this->_getRoleName();
$visibleStatuses = $this->pipeline->getVisibleStatuses($roleName);
$userId = (int)$this->_getCurrentUser()->id;

$conditions = !empty($visibleStatuses)
    ? ['Invoices.pipeline_status IN' => $visibleStatuses]
    : [];

// Excluir facturas de Caja Menor que ya están en contabilidad o posterior
$conditions[] = [
    'OR' => [
        'Invoices.document_type !=' => InvoiceConstants::DOCTYPE_CAJA_MENOR,
        'Invoices.pipeline_status' => InvoiceConstants::STATUS_APROBACION,
    ],
];
```

Reemplazar por:
```php
$roleName = $this->_getRoleName();
$user = $this->_getCurrentUser();
$roleId = (int)$user->role_id;
$userId = (int)$user->id;
$visibleStatuses = $this->pipeline->getVisibleStatuses($roleId);

$conditions = [
    'Invoices.pipeline_status IN' => $visibleStatuses ?: ['__none__'],
    'Invoices.document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO,
];

// Excluir facturas de Caja Menor que ya están en contabilidad o posterior
$conditions[] = [
    'OR' => [
        'Invoices.document_type !=' => InvoiceConstants::DOCTYPE_CAJA_MENOR,
        'Invoices.pipeline_status' => InvoiceConstants::STATUS_APROBACION,
    ],
];
```

- [ ] **Step 2: Verificar que `all()`, `rejected()`, `overdue()` NO se tocan**

Run: `grep -n "getVisibleStatuses" src/Controller/InvoicesController.php`
Expected: solo aparece en `index()`. Los otros endpoints no deben filtrar por visibleStatuses (mantienen comportamiento actual de "ver todo aplicando solo su condición específica").

- [ ] **Step 3: Verificar code style**

Run: `composer cs-check`
Expected: sin violaciones.

- [ ] **Step 4: Validación manual**

Levantar `php bin/cake server`, login como Contabilidad → `/invoices` debe mostrar SOLO facturas en estado `contabilidad`, NINGÚN anticipo.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/InvoicesController.php
git commit -m "refactor(invoices): filtrar /invoices por roleId via pipeline_permissions y excluir anticipos"
```

---

## Task 9: Actualizar `AdvancesController`

**Files:**
- Modify: `src/Controller/AdvancesController.php`

- [ ] **Step 1: Cambiar `index()` para pasar `roleId`**

Localizar:
```php
$roleName = $this->_getUserRoleName($this->_getCurrentUser());
$visibleStatuses = $this->pipelineService->getVisibleAdvanceStatuses($roleName);

$query = $invoicesTable->find()
    ->where(['Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
    ->contain([...])
    ->orderBy(['Invoices.created' => 'DESC']);

if (!empty($visibleStatuses)) {
    $query->where(['Invoices.pipeline_status IN' => $visibleStatuses]);
}
```

Reemplazar por:
```php
$roleId = (int)$this->_getCurrentUser()->role_id;
$visibleStatuses = $this->pipelineService->getVisibleAdvanceStatuses($roleId);

$query = $invoicesTable->find()
    ->where([
        'Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
        'Invoices.pipeline_status IN' => $visibleStatuses ?: ['__none__'],
    ])
    ->contain([...])
    ->orderBy(['Invoices.created' => 'DESC']);
```

(Mantener el array `contain` con los valores actuales: `'Providers', 'Employees', 'OperationCenters', 'AdvanceLegalization'`.)

- [ ] **Step 2: Verificar code style**

Run: `composer cs-check`
Expected: sin violaciones.

- [ ] **Step 3: Validación manual**

Login como Auxiliar de Personal → `/advances` debe mostrar anticipos en estados activos (no `pagada` ni `legalizada`).

- [ ] **Step 4: Commit**

```bash
git add src/Controller/AdvancesController.php
git commit -m "refactor(advances): filtrar por roleId via pipeline_permissions"
```

---

## Task 10: Actualizar `RefundsController`

**Files:**
- Modify: `src/Controller/RefundsController.php`

- [ ] **Step 1: Cambiar `index()` para pasar `roleId`**

Localizar (alrededor línea 118):
```php
$visibleStatuses = $this->refundService->getVisibleStatuses($roleName);
```

Antes de esa línea, asegurar que `$roleId` está disponible:
```php
$roleId = (int)$this->_getCurrentUser()->role_id;
$visibleStatuses = $this->refundService->getVisibleStatuses($roleId);
```

Localizar (alrededor línea 124):
```php
if (!empty($visibleStatuses)) {
    $query->where(['Refunds.status IN' => $visibleStatuses]);
}
```

Reemplazar por:
```php
$query->where(['Refunds.status IN' => $visibleStatuses ?: ['__none__']]);
```

- [ ] **Step 2: Verificar code style**

Run: `composer cs-check`
Expected: sin violaciones.

- [ ] **Step 3: Validación manual**

Login como Contabilidad → `/refunds` debe mostrar solo reintegros en `contabilidad`.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/RefundsController.php
git commit -m "refactor(refunds): filtrar por roleId via pipeline_permissions"
```

---

## Task 11: Actualizar `PettyCashRecordsController`

**Files:**
- Modify: `src/Controller/PettyCashRecordsController.php`

- [ ] **Step 1: Cambiar `index()` para pasar `roleId`**

Localizar (alrededor línea 80):
```php
$visibleStatuses = $this->pettyCashService->getVisibleStatuses($roleName);
```

Asegurar que existe `$roleId`:
```php
$roleId = (int)$this->_getCurrentUser()->role_id;
$visibleStatuses = $this->pettyCashService->getVisibleStatuses($roleId);
```

Localizar (alrededor línea 86):
```php
if (!empty($visibleStatuses)) {
    $query->where(['PettyCashRecords.status IN' => $visibleStatuses]);
}
```

Reemplazar por:
```php
$query->where(['PettyCashRecords.status IN' => $visibleStatuses ?: ['__none__']]);
```

- [ ] **Step 2: Verificar code style**

Run: `composer cs-check`

- [ ] **Step 3: Validación manual**

Login como Tesorería → `/petty-cash-records` muestra registros en `tesoreria`/`autorizacion_pago`/`verificacion_pago`.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/PettyCashRecordsController.php
git commit -m "refactor(petty-cash): filtrar por roleId via pipeline_permissions"
```

---

## Task 12: Actualizar `PaymentSchedulingsController`

**Files:**
- Modify: `src/Controller/PaymentSchedulingsController.php`

- [ ] **Step 1: Cambiar `index()` para pasar `roleId`**

Localizar (alrededor línea 57):
```php
$visibleStatuses = $this->schedulingService->getVisibleStatuses($roleName);
```

Asegurar `$roleId`:
```php
$roleId = (int)$this->_getCurrentUser()->role_id;
$visibleStatuses = $this->schedulingService->getVisibleStatuses($roleId);
```

Localizar (alrededor línea 63):
```php
if (!empty($visibleStatuses)) {
    $query->where(['PaymentSchedulings.pipeline_status IN' => $visibleStatuses]);
}
```

Reemplazar por:
```php
$query->where(['PaymentSchedulings.pipeline_status IN' => $visibleStatuses ?: ['__none__']]);
```

- [ ] **Step 2: Verificar code style**

Run: `composer cs-check`

- [ ] **Step 3: Validación manual**

Login como Tesorería → `/payment-schedulings` muestra schedulings en los 4 steps (sin terminales).

- [ ] **Step 4: Commit**

```bash
git add src/Controller/PaymentSchedulingsController.php
git commit -m "refactor(scheduling): filtrar por roleId via pipeline_permissions"
```

---

## Task 13: Actualizar `NoveltyLiquidationDocsController`

**Files:**
- Modify: `src/Controller/NoveltyLiquidationDocsController.php`

- [ ] **Step 1: Cambiar `index()` para pasar `roleId`**

Localizar (alrededor línea 64):
```php
$visibleStatuses = $this->pipelineService->getVisibleLiquidationStatuses($roleName);
```

Asegurar `$roleId`:
```php
$roleId = (int)$this->_getCurrentUser()->role_id;
$visibleStatuses = $this->pipelineService->getVisibleLiquidationStatuses($roleId);
```

Localizar (alrededor línea 70):
```php
if (!empty($visibleStatuses)) {
    $query->where(['NoveltyLiquidationDocs.pipeline_status IN' => $visibleStatuses]);
}
```

Reemplazar por:
```php
$query->where(['NoveltyLiquidationDocs.pipeline_status IN' => $visibleStatuses ?: ['__none__']]);
```

- [ ] **Step 2: Verificar code style**

Run: `composer cs-check`

- [ ] **Step 3: Validación manual**

Login como Tesorería → `/novelty-liquidation-docs` muestra docs en `tesoreria`/`autorizacion_pago`/`verificacion_pago`.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/NoveltyLiquidationDocsController.php
git commit -m "refactor(liquidation-docs): filtrar por roleId via pipeline_permissions"
```

---

## Task 14: Actualizar `EmployeeNoveltiesController`

**Files:**
- Modify: `src/Controller/EmployeeNoveltiesController.php`

- [ ] **Step 1: Cambiar `index()` para pasar `roleId`**

Localizar (alrededor línea 72):
```php
$visibleStatuses = $this->pipelineService->getVisibleStatuses($roleName);
```

Asegurar `$roleId`:
```php
$roleId = (int)$this->_getCurrentUser()->role_id;
$visibleStatuses = $this->pipelineService->getVisibleStatuses($roleId);
```

Localizar (alrededor línea 75):
```php
if (!empty($visibleStatuses)) {
    $conditions['EmployeeNovelties.pipeline_status IN'] = $visibleStatuses;
}
```

Reemplazar por:
```php
$conditions['EmployeeNovelties.pipeline_status IN'] = $visibleStatuses ?: ['__none__'];
```

- [ ] **Step 2: Verificar code style**

Run: `composer cs-check`

- [ ] **Step 3: Validación manual**

Login como rol con permisos en `novelties.contabilidad` (ej. Contabilidad) → `/employee-novelties` muestra solo novedades en ese estado.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/EmployeeNoveltiesController.php
git commit -m "refactor(novelties): filtrar por roleId via pipeline_permissions"
```

---

## Task 15: Actualizar `SidebarCounterService`

**Files:**
- Modify: `src/Service/SidebarCounterService.php`

- [ ] **Step 1: Identificar la firma actual y los métodos a actualizar**

Run: `grep -n "getVisibleStatuses\|getVisibleAdvanceStatuses\|getVisibleLiquidationStatuses\|roleName" src/Service/SidebarCounterService.php`

Para cada método que internamente llama `*->getVisibleStatuses($roleName)` (5 callsites: invoices, novelties, advances, petty_cash, refunds, liquidation_docs), cambiar la firma del método público de `SidebarCounterService` para que reciba `int $roleId` además del (o en lugar del) `string $roleName`.

- [ ] **Step 2: Cambiar cada callsite**

Para cada uno de los 5 lugares dentro del archivo, ejemplo (línea ~130):
```php
$visibleStatuses = $this->invoicePipeline->getVisibleStatuses($roleName);
```
Reemplazar por:
```php
$visibleStatuses = $this->invoicePipeline->getVisibleStatuses($roleId);
```

Hacer el cambio análogo para:
- línea ~166: `$this->noveltyPipeline->getVisibleStatuses($roleName)` → pasar `$roleId`
- línea ~214: `$this->invoicePipeline->getVisibleAdvanceStatuses($roleName)` → `$roleId`
- línea ~232: `$this->pettyCashService->getVisibleStatuses($roleName)` → `$roleId`
- línea ~247: `$this->refundService->getVisibleStatuses($roleName)` → `$roleId`
- línea ~259: `$this->noveltyPipeline->getVisibleLiquidationStatuses($roleName)` → `$roleId`

- [ ] **Step 3: Asegurar que `$roleId` está disponible donde se necesita**

Examinar cada método que ahora usa `$roleId`. Si el método solo recibe `$roleName`, ajustar la firma a también recibir `$roleId` (o solo `$roleId`). Propagar el cambio al/los callers del propio `SidebarCounterService` (probablemente `AppController` o un middleware que pinta el sidebar).

Run: `grep -rn "SidebarCounterService" src/ --include="*.php" | grep -v "src/Service/SidebarCounterService.php"`

Para cada caller, asegurar que pasa `$roleId`. El caller obvio será un controller/middleware que ya tiene acceso a `$user->role_id`.

- [ ] **Step 4: Aplicar el patrón de "lista vacía → 0 resultados"**

En cada bloque donde antes había:
```php
if (empty($visibleStatuses)) {
    return 0;
}
```
Mantenerlo (es coherente: si no hay permisos, contar 0 sin ir a BD). No requiere el truco `__none__` porque ya retorna `0` antes de query.

- [ ] **Step 5: Verificar code style**

Run: `composer cs-check`

- [ ] **Step 6: Validación manual**

Login con varios roles, verificar que los badges del sidebar muestran los mismos números que antes del PR.

- [ ] **Step 7: Commit**

```bash
git add src/Service/SidebarCounterService.php $(git diff --name-only -- src/Controller src/Middleware)
git commit -m "refactor(sidebar): consumir getVisibleStatuses con roleId"
```

(Incluir cualquier controller/middleware que se haya tocado para propagar `$roleId`.)

---

## Task 16: Actualizar `PendingNotificationsService`

**Files:**
- Modify: `src/Service/PendingNotificationsService.php`

- [ ] **Step 1: Cambiar el callsite**

Localizar (alrededor línea 148):
```php
$visibleStatuses = $this->paymentSchedulingService->getVisibleStatuses($roleName);
if (empty($visibleStatuses)) {
    return 0;
}
```

Cambiar a:
```php
$visibleStatuses = $this->paymentSchedulingService->getVisibleStatuses($roleId);
if (empty($visibleStatuses)) {
    return 0;
}
```

Asegurar que `$roleId` está disponible en el método (ajustar firma si solo recibe `$roleName`).

- [ ] **Step 2: Propagar el cambio a los callers**

Run: `grep -rn "PendingNotificationsService" src/ --include="*.php" | grep -v "src/Service/PendingNotificationsService.php"`

Para cada caller, pasar `$roleId` además del (o en lugar del) `$roleName`.

- [ ] **Step 3: Verificar code style**

Run: `composer cs-check`

- [ ] **Step 4: Validación manual**

Login con varios roles; abrir el panel de notificaciones pendientes. Los conteos deben ser idénticos a los de antes del PR.

- [ ] **Step 5: Commit**

```bash
git add src/Service/PendingNotificationsService.php $(git diff --name-only -- src/Controller)
git commit -m "refactor(notifications): consumir getVisibleStatuses con roleId"
```

---

## Task 17: Eliminar `getRoleVisibility`/`getAdvanceRoleVisibility` de la interfaz y los States

**Files:**
- Modify: `src/Service/Pipeline/Invoice/InvoicePipelineState.php`
- Modify: `src/Service/Pipeline/Invoice/State/AprobacionState.php`
- Modify: `src/Service/Pipeline/Invoice/State/ContabilidadState.php`
- Modify: `src/Service/Pipeline/Invoice/State/TesoreriaState.php`
- Modify: `src/Service/Pipeline/Invoice/State/AutorizacionPagoState.php`
- Modify: `src/Service/Pipeline/Invoice/State/VerificacionPagoState.php`
- Modify: `src/Service/Pipeline/Invoice/State/PagadaState.php`
- Modify: `src/Service/Pipeline/Invoice/State/LegalizadaState.php`

- [ ] **Step 1: Verificar que NADIE más usa estos métodos**

Run:
```bash
grep -rn "getRoleVisibility\|getAdvanceRoleVisibility" src/ templates/
```
Expected: SOLO las declaraciones en la interfaz y las 7 implementaciones (después del refactor de Task 3 nada más debería llamar a estos métodos). Si aparece algún caller no esperado, detenerse e investigar.

- [ ] **Step 2: Borrar de la interfaz**

En `src/Service/Pipeline/Invoice/InvoicePipelineState.php`, localizar y borrar:
```php
    /**
     * Roles que ven facturas en este estado en /invoices "Mis Facturas".
     * @return array<int, string>
     */
    public function getRoleVisibility(): array;

    /**
     * Roles que ven anticipos en este estado en /advances "Mis Anticipos".
     * @return array<int, string>
     */
    public function getAdvanceRoleVisibility(): array;
```

(Los textos exactos de docblocks pueden variar; borrar ambos métodos completos junto con su docblock.)

- [ ] **Step 3: Borrar de cada implementación**

Para cada uno de los 7 archivos en `src/Service/Pipeline/Invoice/State/`, borrar los métodos `getRoleVisibility()` y `getAdvanceRoleVisibility()` completos.

Después de borrar, en cada archivo verificar:
```bash
grep -c "RoleConstants" src/Service/Pipeline/Invoice/State/<Archivo>.php
```
- Si retorna `0`: borrar `use App\Constants\RoleConstants;`.

- [ ] **Step 4: Verificar code style**

Run: `composer cs-check`
Expected: sin violaciones.

- [ ] **Step 5: Validación manual de regresión**

Login con cada rol y abrir su listado principal (`/invoices`, `/refunds`, etc.). Todos deben seguir funcionando como en el Task anterior.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Pipeline/Invoice/
git commit -m "refactor(pipeline-state): eliminar getRoleVisibility/getAdvanceRoleVisibility de States"
```

---

## Task 18: Validación manual end-to-end

**Files:** (ninguno — verificación)

- [ ] **Step 1: cs-check final**

Run: `composer cs-check`
Expected: sin violaciones.

- [ ] **Step 2: Seed sembrado correctamente**

Ejecutar en MySQL:
```sql
SELECT r.name, pp.pipeline, pp.step
FROM pipeline_permissions pp
JOIN roles r ON r.id = pp.role_id
WHERE pp.can_operate = 1
ORDER BY r.name, pp.pipeline, pp.step;
```

Confirmar:
- Administrador tiene filas en los 7 pipelines.
- Contabilidad tiene `invoices.contabilidad`, `refunds.contabilidad`, etc.
- Tesorería tiene los 3 steps tesoreros en cada pipeline relevante.

- [ ] **Step 3: Listados por rol** (paridad con comportamiento previo)

Levantar `php bin/cake server`. Para cada combinación, comparar el listado contra lo esperado:

| Rol | URL | Esperado |
|---|---|---|
| Contabilidad | `/invoices` | Solo facturas en `contabilidad`, ningún anticipo |
| Tesorería | `/invoices` | `tesoreria`, `autorizacion_pago`, `verificacion_pago`; ningún anticipo |
| Contador | `/invoices` | `autorizacion_pago`, `verificacion_pago`; ningún anticipo |
| Registro/Revisión | `/invoices` | `aprobacion`; ningún anticipo |
| Administrador | `/invoices` | Todas las activas (sin pagada/legalizada), incluye o no anticipos según comportamiento esperado — NOTA: con la nueva exclusión, Admin tampoco ve anticipos en `/invoices`. Si esto es problema, ver "Cambios de comportamiento aceptados" |
| Contabilidad | `/refunds` | Solo `contabilidad` |
| Tesorería | `/refunds` | `tesoreria`, `autorizacion_pago`, `verificacion_pago` |
| Tesorería | `/petty-cash-records` | Steps tesoreros |
| Tesorería | `/payment-schedulings` | Steps tesoreros (sin `pagada`) |
| Auxiliar de Personal | `/advances` | Activos solamente |
| Tesorería | `/novelty-liquidation-docs` | Steps tesoreros |
| Contabilidad | `/employee-novelties` | Solo `contabilidad` |

- [ ] **Step 4: Sidebar counters**

Para cada rol del paso anterior, abrir el sidebar y verificar que los badges muestran los conteos correctos (deben coincidir con los listados).

- [ ] **Step 5: Edición de permisos cambia visibilidad en vivo**

- Login como Administrador → `/roles/edit/{id_de_Contabilidad}`.
- Sección "Permisos de Pipeline" → bajo "Facturas", desmarcar el step `Contabilidad`.
- Guardar.
- Login como un usuario con rol Contabilidad → `/invoices` debe quedar vacío.
- Volver como Administrador, re-marcar el checkbox, guardar.
- Re-login como Contabilidad → `/invoices` vuelve a mostrar facturas en `contabilidad`.

- [ ] **Step 6: Rol nuevo sin permisos**

- Login como Administrador → `/roles/add`.
- Crear rol "Prueba SinPermisos" sin tocar la sección "Permisos de Pipeline" (todos los checkboxes desmarcados).
- Crear un usuario con ese rol, login.
- Verificar que `/invoices`, `/refunds`, `/petty-cash-records`, `/payment-schedulings`, `/novelty-liquidation-docs`, `/employee-novelties`, `/advances` están **todos vacíos** (lista vacía, no "ver todo").

- [ ] **Step 7: Verificación visual de la UI de Roles/edit**

Login como Admin, abrir `/roles/edit/{cualquier_id}`. Verificar que la sección "Permisos de Pipeline" muestra los 7 pipelines (Facturas, Novedades, Programación de pagos, Reintegros, Caja menor, Legalizaciones, Documentos de liquidación) y los checkboxes funcionan al guardar.

- [ ] **Step 8: Commit final si quedaron cambios sueltos**

```bash
git status
```

Si hay archivos modificados no commiteados (raro a estas alturas):
```bash
git add -A
git commit -m "chore: cleanup remanente de migracion de filtros de pipeline"
```

- [ ] **Step 9: Verificar el árbol de commits**

```bash
git log --oneline origin/main..HEAD
```

Expected: ~17 commits coherentes (uno por task), todos en español.

---

## Notas operativas

- **Orden estricto**: Tasks 1 y 2 deben ir antes que cualquier otro Task. Task 17 (eliminar getRoleVisibility) debe ir DESPUÉS del Task 3 (que es el único que usaba esos métodos). Los demás Tasks (4-16) pueden alterarse en orden si conviene, siempre que los services se refactoricen antes que sus controllers correspondientes.
- **Rollback**: si algo sale mal entre commits, `git revert <hash>` del último commit problemático y re-intentar. La migration de seed es idempotente, así que re-ejecutar no daña.
- **Si un rol no aparece en la matriz del seed**: la migration loguea un warning vía `error_log` y continúa. Verificar después del seed (paso 2 del Task 18) si quedan roles sin filas: `SELECT name FROM roles WHERE id NOT IN (SELECT DISTINCT role_id FROM pipeline_permissions);`.

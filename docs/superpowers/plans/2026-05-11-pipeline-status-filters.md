# Migración de filtros de visibilidad por estado a `pipeline_permissions` — Plan de Implementación

> **Para workers agénticos:** SUB-SKILL REQUERIDA: usar `superpowers:subagent-driven-development` (recomendado) o `superpowers:executing-plans` para implementar este plan task por task. Los pasos usan sintaxis de checkbox (`- [ ]`).

**Goal:** Hacer que `pipeline_permissions` sea la única fuente de verdad para "qué estados ve cada rol" en los listados de los 6 módulos con pipeline, eliminando matrices hardcodeadas y dependencia de `RoleConstants` en los servicios de filtrado.

**Architecture:** Cada `*Service::getVisibleStatuses` se convierte en un adaptador delgado sobre `PipelineAuthorizationService::getOperableSteps($roleId, '', $pipeline)`. Se agrega un pipeline nuevo (`liquidation_docs`) al catálogo `PipelineStepConstants`. El patrón "lista vacía → 0 resultados" se centraliza en un helper de `AppController`. `InvoicesController` excluye `document_type='Anticipo'` en sus 4 endpoints (`index`/`all`/`rejected`/`overdue`) para que `/invoices` y `/advances` sean disjuntos sin importar el endpoint.

**Tech Stack:** CakePHP 5.3, PHP 8.4+, MySQL/MariaDB. Migrations vía `Migrations\BaseMigration`. Sin tests automatizados (ver CLAUDE.md § Testing Policy — validación manual).

---

## Spec de referencia

`docs/superpowers/specs/2026-05-11-pipeline-status-filters-design.md`

## Decisiones operativas heredadas del spec (y overrides)

- **Admin pasa por la tabla** (sin bypass hardcodeado).
- **Pipeline `invoices` para facturas y anticipos**: `InvoicesController` excluye `document_type='Anticipo'` en los 4 endpoints (`index`, `all`, `rejected`, `overdue`). `AdvancesController` ya filtra por `document_type='Anticipo'`.
- **Pipeline `liquidation_docs` nuevo**: para documentos de liquidación de novedades.
- **Eliminar `getRoleVisibility`/`getAdvanceRoleVisibility`** de la interfaz `InvoicePipelineState` y de los 7 States en el mismo PR.
- **Eliminar `ROLE_VISIBLE_STATUSES`** en los 4 services pertinentes en el mismo PR.
- **Override del spec (configuración manual)**: NO se incluye migration de seed. Los permisos en `pipeline_permissions` los configurará el administrador manualmente desde `/roles/edit/{id}` tras el deploy. Consecuencia: todos los listados quedarán vacíos para cada rol HASTA que el admin haga la configuración inicial. Documentar al usuario que esa configuración es prerrequisito del deploy.
- **Eliminar `getVisibleAdvanceStatuses`** (review M2): los cuerpos eran idénticos a `getVisibleStatuses`. La distinción factura/anticipo vive en `document_type` del controller. `AdvancesController` pasa a llamar a `getVisibleStatuses`.
- **Centralizar el filtro de visibilidad** (review M1) en `AppController::_visibleStatusConditions()`, evitando duplicar el truco "lista vacía → resultado vacío" en 7 controllers.

## Cambios de comportamiento aceptados (documentados)

1. **Estados terminales excluidos del listado "Mis Registros"** (efecto del catálogo):
   - Hoy `PagadaState::getRoleVisibility() = [ADMIN]` → Admin ve facturas `pagada` en `/invoices`.
   - Después: `pagada` y `legalizada` están fuera de `STEPS_BY_PIPELINE['invoices']` → Admin las verá en `/invoices/all` pero NO en `/invoices`.
   - Lo mismo aplica a Tesorería viendo schedulings `pagada` en `/payment-schedulings`.

2. **Configuración manual post-deploy obligatoria**: sin seed, los 6 listados estarán vacíos para todos los roles hasta que el admin marque los checkboxes en `/roles/edit/{id}` por cada rol y pipeline.

3. **Anticipos NO aparecen en `/invoices/all`, `/invoices/rejected`, `/invoices/overdue`** tras el cambio. Si el negocio depende de ver anticipos en esos endpoints, revertir la exclusión en esos 3 controllers (manteniéndola en `index`).

## Mapa de archivos

**Modificar:**
- `src/Constants/PipelineStepConstants.php` — añadir `PIPELINE_LIQUIDATION_DOCS`
- `src/Controller/AppController.php` — añadir helper `_visibleStatusConditions`
- `src/Service/InvoicePipelineService.php` — `getVisibleStatuses`, eliminar `getVisibleAdvanceStatuses`
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
- `src/Controller/InvoicesController.php` — 4 endpoints (`index`, `all`, `rejected`, `overdue`) + exclusión anticipos en todos
- `src/Controller/AdvancesController.php` — `index`, usa `getVisibleStatuses` y `roleId`
- `src/Controller/RefundsController.php` — `index`, helper + `roleId`
- `src/Controller/PettyCashRecordsController.php` — `index`, helper + `roleId`
- `src/Controller/PaymentSchedulingsController.php` — `index`, helper + `roleId`
- `src/Controller/NoveltyLiquidationDocsController.php` — `index`, helper + `roleId`
- `src/Controller/EmployeeNoveltiesController.php` — `index`, helper + `roleId`
- `src/Service/SidebarCounterService.php` — 5 callsites, firma a `int $roleId` exclusivo
- `src/Service/PendingNotificationsService.php` — 1 callsite, firma a `int $roleId` exclusivo

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

Levantar `php bin/cake server`, login como Admin, navegar a `/roles/edit/{id}`, verificar que aparece una sección "Documentos de liquidación" con los 6 checkboxes (todos desmarcados — todavía no configurado).

- [ ] **Step 4: Commit**

```bash
git add src/Constants/PipelineStepConstants.php
git commit -m "feat(pipeline): agregar pipeline liquidation_docs al catalogo de permisos"
```

---

## Task 2: Helper `_visibleStatusConditions` en `AppController`

**Files:**
- Modify: `src/Controller/AppController.php`

- [ ] **Step 1: Agregar el método protegido al AppController**

Localizar la sección de métodos protegidos de `AppController` (cerca de `_getCurrentUser`, `_getUserRoleName`). Agregar:

```php
    /**
     * Construye condiciones de filtro por `pipeline_status` (o columna análoga)
     * para los listados "Mis Registros" de los módulos con pipeline.
     *
     * Si la lista de estados visibles está vacía (rol sin permisos sembrados),
     * retorna una condición imposible (`1 = 0`) para garantizar 0 resultados.
     * Centraliza el patrón usado por Invoices/Refunds/PettyCash/etc. y evita
     * el anti-pattern de valores centinela mágicos.
     *
     * @param string $field Columna calificada, ej. `Invoices.pipeline_status`.
     * @param array<int, string> $statuses Lista de estados visibles para el rol.
     * @return array<string|int, mixed> Condiciones para aplicar en `$query->where(...)`.
     */
    protected function _visibleStatusConditions(string $field, array $statuses): array
    {
        if ($statuses === []) {
            return ['1 = 0'];
        }

        return [$field . ' IN' => $statuses];
    }
```

- [ ] **Step 2: Verificar code style**

Run: `composer cs-check`
Expected: sin violaciones.

- [ ] **Step 3: Commit**

```bash
git add src/Controller/AppController.php
git commit -m "feat(app): helper _visibleStatusConditions para filtros de pipeline"
```

---

## Task 3: Refactorizar `InvoicePipelineService`

**Files:**
- Modify: `src/Service/InvoicePipelineService.php`

- [ ] **Step 1: Reemplazar `getVisibleStatuses` y eliminar `getVisibleAdvanceStatuses`**

Localizar el bloque:
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

Reemplazar por (solo un método):
```php
public function getVisibleStatuses(int $roleId): array
{
    return $this->pipelineAuth->getOperableSteps(
        $roleId,
        '',
        PipelineStepConstants::PIPELINE_INVOICES,
    );
}
```

`getVisibleAdvanceStatuses` se elimina por completo. `AdvancesController` (Task 9) pasará a llamar a `getVisibleStatuses` y la distinción factura/anticipo se mantiene vía `document_type` del query.

- [ ] **Step 2: Verificar code style**

Run: `composer cs-check`
Expected: sin violaciones.

- [ ] **Step 3: Commit**

```bash
git add src/Service/InvoicePipelineService.php
git commit -m "refactor(invoice): delegar getVisibleStatuses a pipeline_permissions y eliminar getVisibleAdvanceStatuses"
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

Borrar los 3 bloques completos.

- [ ] **Step 3: Limpiar import huérfano**

Run: `grep -c "RoleConstants" src/Service/NoveltyService.php`
- Si retorna `0`: borrar `use App\Constants\RoleConstants;`.

- [ ] **Step 4: Verificar que `PipelineStepConstants` está importado**

Run: `grep -c "use App.Constants.PipelineStepConstants" src/Service/NoveltyService.php`
- Si retorna `0`: agregar `use App\Constants\PipelineStepConstants;` al bloque de imports.

- [ ] **Step 5: Verificar code style**

Run: `composer cs-check`

- [ ] **Step 6: Commit**

```bash
git add src/Service/NoveltyService.php
git commit -m "refactor(novelty): delegar getVisibleStatuses y getVisibleLiquidationStatuses a pipeline_permissions"
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

Borrar el bloque completo.

- [ ] **Step 3: Limpiar import huérfano**

Run: `grep -c "RoleConstants" src/Service/PaymentSchedulingService.php`
- Si retorna `0`: borrar `use App\Constants\RoleConstants;`.

- [ ] **Step 4: Verificar code style**

Run: `composer cs-check`

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

Borrar ambos bloques.

- [ ] **Step 3: Limpiar import huérfano**

Run: `grep -c "RoleConstants" src/Service/PettyCashService.php`
- Si retorna `0`: borrar `use App\Constants\RoleConstants;`.

- [ ] **Step 4: Verificar code style**

Run: `composer cs-check`

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

Borrar ambos bloques.

- [ ] **Step 3: Limpiar import huérfano**

Run: `grep -c "RoleConstants" src/Service/RefundService.php`
- Si retorna `0`: borrar `use App\Constants\RoleConstants;`.

- [ ] **Step 4: Verificar code style**

Run: `composer cs-check`

- [ ] **Step 5: Commit**

```bash
git add src/Service/RefundService.php
git commit -m "refactor(refund): delegar getVisibleStatuses a pipeline_permissions"
```

---

## Task 8: Actualizar `InvoicesController` (4 endpoints + exclusión de anticipos)

**Files:**
- Modify: `src/Controller/InvoicesController.php`

- [ ] **Step 1: Reemplazar `index()` con el helper y exclusión de anticipos**

Localizar `public function index()` (línea ~70). Reemplazar el bloque:
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

Por:
```php
$roleName = $this->_getRoleName();
$user = $this->_getCurrentUser();
$roleId = (int)$user->role_id;
$userId = (int)$user->id;
$visibleStatuses = $this->pipeline->getVisibleStatuses($roleId);

$conditions = $this->_visibleStatusConditions('Invoices.pipeline_status', $visibleStatuses);
$conditions['Invoices.document_type !='] = InvoiceConstants::DOCTYPE_ANTICIPO;

// Excluir facturas de Caja Menor que ya están en contabilidad o posterior
$conditions[] = [
    'OR' => [
        'Invoices.document_type !=' => InvoiceConstants::DOCTYPE_CAJA_MENOR,
        'Invoices.pipeline_status' => InvoiceConstants::STATUS_APROBACION,
    ],
];
```

- [ ] **Step 2: Aplicar exclusión de anticipos a `all()`, `rejected()`, `overdue()`**

Para los 3 endpoints, agregar la condición de exclusión al array de condiciones que ya construyen. Patrón:

En `all()` (donde hoy hay `_buildInvoiceQuery([], $userId)`), cambiar el primer argumento de `[]` a:
```php
['Invoices.document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO]
```

En `rejected()` (hoy):
```php
$this->_buildInvoiceQuery([
    'Invoices.area_approval' => InvoiceConstants::APPROVAL_REJECTED,
], $userId)
```
Agregar la exclusión:
```php
$this->_buildInvoiceQuery([
    'Invoices.area_approval' => InvoiceConstants::APPROVAL_REJECTED,
    'Invoices.document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO,
], $userId)
```

En `overdue()` (hoy):
```php
$this->_buildInvoiceQuery([
    'Invoices.due_date <' => date('Y-m-d'),
    'Invoices.pipeline_status NOT IN' => [...],
], $userId)
```
Agregar:
```php
$this->_buildInvoiceQuery([
    'Invoices.due_date <' => date('Y-m-d'),
    'Invoices.pipeline_status NOT IN' => [...],
    'Invoices.document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO,
], $userId)
```

- [ ] **Step 3: Verificar que `getVisibleStatuses` solo se llama en `index()`**

Run: `grep -n "getVisibleStatuses" src/Controller/InvoicesController.php`
Expected: una sola ocurrencia, en `index()`.

- [ ] **Step 4: Verificar code style**

Run: `composer cs-check`

- [ ] **Step 5: Validación manual**

Levantar `php bin/cake server`. Login como Admin → configurar manualmente permisos para rol Contabilidad en `/roles/edit/{id}` (marcar `Facturas.Contabilidad`). Login como Contabilidad → `/invoices` debe mostrar SOLO facturas en estado `contabilidad`, ningún anticipo. `/invoices/all` tampoco muestra anticipos.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/InvoicesController.php
git commit -m "refactor(invoices): filtrar por pipeline_permissions y excluir anticipos en los 4 endpoints"
```

---

## Task 9: Actualizar `AdvancesController`

**Files:**
- Modify: `src/Controller/AdvancesController.php`

- [ ] **Step 1: Cambiar `index()` para usar `getVisibleStatuses` y el helper**

Localizar:
```php
$roleName = $this->_getUserRoleName($this->_getCurrentUser());
$visibleStatuses = $this->pipelineService->getVisibleAdvanceStatuses($roleName);

$query = $invoicesTable->find()
    ->where(['Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
    ->contain([
        'Providers',
        'Employees',
        'OperationCenters',
        'AdvanceLegalization',
    ])
    ->orderBy(['Invoices.created' => 'DESC']);

if (!empty($visibleStatuses)) {
    $query->where(['Invoices.pipeline_status IN' => $visibleStatuses]);
}
```

Reemplazar por:
```php
$roleId = (int)$this->_getCurrentUser()->role_id;
$visibleStatuses = $this->pipelineService->getVisibleStatuses($roleId);

$query = $invoicesTable->find()
    ->where(['Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
    ->contain([
        'Providers',
        'Employees',
        'OperationCenters',
        'AdvanceLegalization',
    ])
    ->orderBy(['Invoices.created' => 'DESC']);

$query->where($this->_visibleStatusConditions('Invoices.pipeline_status', $visibleStatuses));
```

(Cambio clave: `getVisibleAdvanceStatuses` → `getVisibleStatuses`, porque tras Task 3 solo existe el segundo.)

- [ ] **Step 2: Verificar code style**

Run: `composer cs-check`

- [ ] **Step 3: Validación manual**

Configurar permisos para rol Auxiliar de Personal en `/roles/edit/{id}` (marcar todos los steps de `Facturas`). Login como Auxiliar de Personal → `/advances` muestra anticipos en los 5 steps activos (`aprobacion`, `contabilidad`, `tesoreria`, `autorizacion_pago`, `verificacion_pago`).

- [ ] **Step 4: Commit**

```bash
git add src/Controller/AdvancesController.php
git commit -m "refactor(advances): usar getVisibleStatuses con roleId y helper de filtro"
```

---

## Task 10: Actualizar `RefundsController`

**Files:**
- Modify: `src/Controller/RefundsController.php`

- [ ] **Step 1: Cambiar `index()` para usar `roleId` y el helper**

Localizar (alrededor línea 118):
```php
$visibleStatuses = $this->refundService->getVisibleStatuses($roleName);
```

Localizar (alrededor línea 124):
```php
if (!empty($visibleStatuses)) {
    $query->where(['Refunds.status IN' => $visibleStatuses]);
}
```

Reemplazar los dos bloques por:
```php
$roleId = (int)$this->_getCurrentUser()->role_id;
$visibleStatuses = $this->refundService->getVisibleStatuses($roleId);

// ... (el resto de la construcción del query queda igual, hasta donde estaba el if)

$query->where($this->_visibleStatusConditions('Refunds.status', $visibleStatuses));
```

Asegurar que el `$roleId` se asigna antes del bloque que llama al service, y que la línea `$query->where(...)` con el helper sustituye al `if (!empty(...))`.

- [ ] **Step 2: Verificar code style**

Run: `composer cs-check`

- [ ] **Step 3: Validación manual**

Configurar permiso `Reintegros.contabilidad` para rol Contabilidad. Login como Contabilidad → `/refunds` muestra solo reintegros en `contabilidad`.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/RefundsController.php
git commit -m "refactor(refunds): usar pipeline_permissions con helper de filtro"
```

---

## Task 11: Actualizar `PettyCashRecordsController`

**Files:**
- Modify: `src/Controller/PettyCashRecordsController.php`

- [ ] **Step 1: Cambiar `index()` para usar `roleId` y el helper**

Localizar (alrededor línea 80):
```php
$visibleStatuses = $this->pettyCashService->getVisibleStatuses($roleName);
```

Localizar (alrededor línea 86):
```php
if (!empty($visibleStatuses)) {
    $query->where(['PettyCashRecords.status IN' => $visibleStatuses]);
}
```

Reemplazar por:
```php
$roleId = (int)$this->_getCurrentUser()->role_id;
$visibleStatuses = $this->pettyCashService->getVisibleStatuses($roleId);

// ... (resto del query)

$query->where($this->_visibleStatusConditions('PettyCashRecords.status', $visibleStatuses));
```

- [ ] **Step 2: Verificar code style**

Run: `composer cs-check`

- [ ] **Step 3: Validación manual**

Configurar permisos `Caja menor.tesoreria/autorizacion_pago/verificacion_pago` para rol Tesorería. Login como Tesorería → `/petty-cash-records` muestra registros en esos steps.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/PettyCashRecordsController.php
git commit -m "refactor(petty-cash): usar pipeline_permissions con helper de filtro"
```

---

## Task 12: Actualizar `PaymentSchedulingsController`

**Files:**
- Modify: `src/Controller/PaymentSchedulingsController.php`

- [ ] **Step 1: Cambiar `index()` para usar `roleId` y el helper**

Localizar (alrededor línea 57):
```php
$visibleStatuses = $this->schedulingService->getVisibleStatuses($roleName);
```

Localizar (alrededor línea 63):
```php
if (!empty($visibleStatuses)) {
    $query->where(['PaymentSchedulings.pipeline_status IN' => $visibleStatuses]);
}
```

Reemplazar por:
```php
$roleId = (int)$this->_getCurrentUser()->role_id;
$visibleStatuses = $this->schedulingService->getVisibleStatuses($roleId);

// ... (resto del query)

$query->where($this->_visibleStatusConditions('PaymentSchedulings.pipeline_status', $visibleStatuses));
```

- [ ] **Step 2: Verificar code style**

Run: `composer cs-check`

- [ ] **Step 3: Validación manual**

Configurar permisos `Programación de pagos.borrador/tesoreria/autorizacion_pago/verificacion_pago` para rol Tesorería. Login como Tesorería → `/payment-schedulings` muestra los 4 steps activos.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/PaymentSchedulingsController.php
git commit -m "refactor(scheduling): usar pipeline_permissions con helper de filtro"
```

---

## Task 13: Actualizar `NoveltyLiquidationDocsController`

**Files:**
- Modify: `src/Controller/NoveltyLiquidationDocsController.php`

- [ ] **Step 1: Cambiar `index()` para usar `roleId` y el helper**

Localizar (alrededor línea 64):
```php
$visibleStatuses = $this->pipelineService->getVisibleLiquidationStatuses($roleName);
```

Localizar (alrededor línea 70):
```php
if (!empty($visibleStatuses)) {
    $query->where(['NoveltyLiquidationDocs.pipeline_status IN' => $visibleStatuses]);
}
```

Reemplazar por:
```php
$roleId = (int)$this->_getCurrentUser()->role_id;
$visibleStatuses = $this->pipelineService->getVisibleLiquidationStatuses($roleId);

// ... (resto del query)

$query->where($this->_visibleStatusConditions('NoveltyLiquidationDocs.pipeline_status', $visibleStatuses));
```

- [ ] **Step 2: Verificar code style**

Run: `composer cs-check`

- [ ] **Step 3: Validación manual**

Configurar permisos `Documentos de liquidación.tesoreria/autorizacion_pago/verificacion_pago` para rol Tesorería. Login como Tesorería → `/novelty-liquidation-docs` muestra docs en esos steps.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/NoveltyLiquidationDocsController.php
git commit -m "refactor(liquidation-docs): usar pipeline_permissions con helper de filtro"
```

---

## Task 14: Actualizar `EmployeeNoveltiesController`

**Files:**
- Modify: `src/Controller/EmployeeNoveltiesController.php`

- [ ] **Step 1: Cambiar `index()` para usar `roleId` y el helper**

Localizar (alrededor línea 72):
```php
$visibleStatuses = $this->pipelineService->getVisibleStatuses($roleName);
```

Localizar (alrededor línea 75):
```php
if (!empty($visibleStatuses)) {
    $conditions['EmployeeNovelties.pipeline_status IN'] = $visibleStatuses;
}
```

Reemplazar por:
```php
$roleId = (int)$this->_getCurrentUser()->role_id;
$visibleStatuses = $this->pipelineService->getVisibleStatuses($roleId);

// ... (resto de la construcción de $conditions)

$conditions = array_merge(
    $conditions,
    $this->_visibleStatusConditions('EmployeeNovelties.pipeline_status', $visibleStatuses),
);
```

(Aquí el helper retorna un array de conditions; se mezcla con las que ya existen.)

- [ ] **Step 2: Verificar code style**

Run: `composer cs-check`

- [ ] **Step 3: Validación manual**

Configurar permiso `Novedades.contabilidad` para rol Contabilidad. Login como Contabilidad → `/employee-novelties` muestra solo novedades en `contabilidad`.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/EmployeeNoveltiesController.php
git commit -m "refactor(novelties): usar pipeline_permissions con helper de filtro"
```

---

## Task 15: Actualizar `SidebarCounterService` (firma SOLO `roleId`)

**Files:**
- Modify: `src/Service/SidebarCounterService.php`
- Possibly modify: el caller del sidebar (AppController o middleware del sidebar)

- [ ] **Step 1: Identificar callsites y firma actual**

Run:
```bash
grep -n "getVisibleStatuses\|getVisibleAdvanceStatuses\|getVisibleLiquidationStatuses\|function .*roleName" src/Service/SidebarCounterService.php
```

Identificar los 5+ callsites de `getVisibleStatuses` y los métodos que los albergan.

- [ ] **Step 2: Cambiar la firma de los métodos públicos del service a SOLO `int $roleId`**

Para cada método público que hoy recibe `string $roleName`, **eliminar** el parámetro `$roleName` y agregar `int $roleId`. Si el método necesita el nombre para algo (poco probable, verificar caso a caso), resolverlo internamente vía `RolesTable->get($roleId)->name` en el punto exacto donde se use.

Ejemplo de cambio de firma:
```php
// Antes
public function countInvoicesPending(string $roleName): int { ... }

// Después
public function countInvoicesPending(int $roleId): int { ... }
```

- [ ] **Step 3: Cambiar los callsites internos**

Para cada llamada interna del archivo:
- línea ~130: `$this->invoicePipeline->getVisibleStatuses($roleName)` → `$this->invoicePipeline->getVisibleStatuses($roleId)`
- línea ~166: `$this->noveltyPipeline->getVisibleStatuses($roleName)` → `$this->noveltyPipeline->getVisibleStatuses($roleId)`
- línea ~214: `$this->invoicePipeline->getVisibleAdvanceStatuses($roleName)` → `$this->invoicePipeline->getVisibleStatuses($roleId)` (tras Task 3 ya no existe `getVisibleAdvanceStatuses`)
- línea ~232: `$this->pettyCashService->getVisibleStatuses($roleName)` → `$this->pettyCashService->getVisibleStatuses($roleId)`
- línea ~247: `$this->refundService->getVisibleStatuses($roleName)` → `$this->refundService->getVisibleStatuses($roleId)`
- línea ~259: `$this->noveltyPipeline->getVisibleLiquidationStatuses($roleName)` → `$this->noveltyPipeline->getVisibleLiquidationStatuses($roleId)`

- [ ] **Step 4: Mantener el patrón `if (empty($visibleStatuses)) return 0;`**

No requiere cambios — sigue siendo coherente (rol sin permisos → conteo 0 sin tocar BD).

- [ ] **Step 5: Propagar el cambio de firma a los callers externos**

Run:
```bash
grep -rn "SidebarCounterService" src/ --include="*.php" | grep -v "src/Service/SidebarCounterService.php"
```

Para cada caller, **eliminar** el argumento `$roleName` y pasar solo `$roleId` (disponible vía `$user->role_id`). Si el caller no tenía `$roleId` a mano, obtenerlo desde el user autenticado.

- [ ] **Step 6: Verificar code style**

Run: `composer cs-check`

- [ ] **Step 7: Validación manual**

Login con varios roles, verificar que los badges del sidebar muestran los conteos correctos (deben coincidir con los listados de los controllers).

- [ ] **Step 8: Commit**

```bash
git add src/Service/SidebarCounterService.php $(git diff --name-only -- src/Controller src/Middleware)
git commit -m "refactor(sidebar): firma SOLO roleId en SidebarCounterService"
```

---

## Task 16: Actualizar `PendingNotificationsService` (firma SOLO `roleId`)

**Files:**
- Modify: `src/Service/PendingNotificationsService.php`
- Possibly modify: callers

- [ ] **Step 1: Cambiar la firma del método público a SOLO `int $roleId`**

Localizar el método público que recibe `$roleName`. Cambiar la firma a aceptar **solo** `int $roleId` (eliminar `$roleName` por completo).

- [ ] **Step 2: Cambiar el callsite interno**

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

- [ ] **Step 3: Propagar a callers externos**

Run:
```bash
grep -rn "PendingNotificationsService" src/ --include="*.php" | grep -v "src/Service/PendingNotificationsService.php"
```

Para cada caller, eliminar el `$roleName` y pasar `$roleId`.

- [ ] **Step 4: Verificar code style**

Run: `composer cs-check`

- [ ] **Step 5: Validación manual**

Login con varios roles; abrir el panel de notificaciones pendientes. Conteos deben ser coherentes con los permisos configurados.

- [ ] **Step 6: Commit**

```bash
git add src/Service/PendingNotificationsService.php $(git diff --name-only -- src/Controller)
git commit -m "refactor(notifications): firma SOLO roleId en PendingNotificationsService"
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

Expected: SOLO las declaraciones en la interfaz y las 7 implementaciones (tras el refactor de Task 3 nadie llama estos métodos). Si aparece algún caller inesperado, detenerse e investigar antes de borrar.

- [ ] **Step 2: Borrar de la interfaz**

En `src/Service/Pipeline/Invoice/InvoicePipelineState.php`, borrar las dos declaraciones (`public function getRoleVisibility(): array;` y `public function getAdvanceRoleVisibility(): array;`) junto con sus docblocks.

- [ ] **Step 3: Borrar de cada implementación**

Para cada uno de los 7 archivos en `src/Service/Pipeline/Invoice/State/`, borrar los métodos `getRoleVisibility()` y `getAdvanceRoleVisibility()` completos.

Tras borrar, en cada archivo:
```bash
grep -c "RoleConstants" src/Service/Pipeline/Invoice/State/<Archivo>.php
```
- Si retorna `0`: borrar `use App\Constants\RoleConstants;`.

- [ ] **Step 4: Verificar code style**

Run: `composer cs-check`

- [ ] **Step 5: Validación manual de regresión**

Login con cada rol con permisos configurados; abrir sus listados (`/invoices`, `/refunds`, etc.). Todo debe funcionar igual que en el task anterior.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Pipeline/Invoice/
git commit -m "refactor(pipeline-state): eliminar getRoleVisibility/getAdvanceRoleVisibility de States e interfaz"
```

---

## Task 18: Validación manual end-to-end

**Files:** (ninguno — verificación)

- [ ] **Step 1: cs-check final**

Run: `composer cs-check`
Expected: sin violaciones.

- [ ] **Step 2: Configurar permisos manualmente para cada rol**

Levantar `php bin/cake server`. Login como Administrador. Para cada rol activo del sistema, abrir `/roles/edit/{id}` y marcar los checkboxes según la matriz operativa esperada. Como referencia inicial (basada en el comportamiento previo del sistema):

| Rol | Pipeline `invoices` | `novelties` | `payment_schedulings` | `refunds` | `petty_cash` | `liquidation_docs` |
|---|---|---|---|---|---|---|
| Administrador | todos | todos | todos | todos | todos | todos |
| Registro/Revisión | aprobacion | — | — | agrupacion | agrupacion | revision_firmas, gdp |
| Contabilidad | contabilidad | contabilidad | — | contabilidad | contabilidad | contabilidad |
| Tesorería | tesoreria, autorizacion_pago, verificacion_pago | tesoreria, autorizacion_pago, verificacion_pago | borrador, tesoreria, autorizacion_pago, verificacion_pago | tesoreria, autorizacion_pago, verificacion_pago | tesoreria, autorizacion_pago, verificacion_pago | tesoreria, autorizacion_pago, verificacion_pago |
| Contador | autorizacion_pago, verificacion_pago | revision_firmas, autorizacion_pago, verificacion_pago | autorizacion_pago, verificacion_pago | autorizacion_pago, verificacion_pago | autorizacion_pago, verificacion_pago | autorizacion_pago, verificacion_pago |
| Auxiliar de Personal | todos | aprobacion, rrhh, revision_firmas, gdp | — | todos | todos | todos |
| Asistente de Personal | todos | aprobacion, rrhh, revision_firmas, gdp | — | todos | todos | todos |
| Coordinador Admin y Financiero | todos | revision_firmas | — | todos | todos | todos |

(Esta matriz refleja las matrices hardcodeadas previas. Es punto de partida; el negocio puede ajustar tras revisar.)

- [ ] **Step 3: Listados por rol** (paridad con comportamiento previo)

Para cada combinación, comparar el listado contra lo esperado:

| Rol | URL | Esperado |
|---|---|---|
| Contabilidad | `/invoices` | Solo facturas en `contabilidad`, ningún anticipo |
| Tesorería | `/invoices` | `tesoreria`, `autorizacion_pago`, `verificacion_pago`; ningún anticipo |
| Contador | `/invoices` | `autorizacion_pago`, `verificacion_pago`; ningún anticipo |
| Registro/Revisión | `/invoices` | `aprobacion`; ningún anticipo |
| Administrador | `/invoices` | Activas sin `pagada`/`legalizada`/anticipos |
| Cualquiera | `/invoices/all` | Todas las facturas excluyendo anticipos |
| Contabilidad | `/refunds` | Solo `contabilidad` |
| Tesorería | `/refunds` | `tesoreria`, `autorizacion_pago`, `verificacion_pago` |
| Tesorería | `/petty-cash-records` | Steps tesoreros |
| Tesorería | `/payment-schedulings` | Steps tesoreros |
| Auxiliar de Personal | `/advances` | Anticipos en steps activos |
| Tesorería | `/novelty-liquidation-docs` | Steps tesoreros |
| Contabilidad | `/employee-novelties` | Solo `contabilidad` |

- [ ] **Step 4: Sidebar counters**

Para cada rol, abrir el sidebar y verificar que los badges muestran conteos coherentes con los listados.

- [ ] **Step 5: Edición de permisos cambia visibilidad en vivo**

- Login como Administrador → `/roles/edit/{id_de_Contabilidad}`.
- Sección "Permisos de Pipeline" → bajo "Facturas", desmarcar el step `Contabilidad`. Guardar.
- Login como un usuario con rol Contabilidad → `/invoices` queda vacío.
- Volver como Administrador, re-marcar, guardar.
- Re-login como Contabilidad → `/invoices` vuelve a mostrar facturas.

- [ ] **Step 6: Rol nuevo sin permisos**

- Login como Administrador → `/roles/add`.
- Crear rol "Prueba SinPermisos" con todos los checkboxes desmarcados.
- Crear usuario con ese rol, login.
- Los 7 listados deben estar **todos vacíos** (lista vacía, no "ver todo").

- [ ] **Step 7: Verificación visual de la UI de Roles/edit**

Login como Admin, abrir `/roles/edit/{cualquier_id}`. La sección "Permisos de Pipeline" muestra los 7 pipelines (Facturas, Novedades, Programación de pagos, Reintegros, Caja menor, Legalizaciones, Documentos de liquidación) y los checkboxes funcionan al guardar.

- [ ] **Step 8: Commit final si quedaron cambios sueltos**

```bash
git status
```
Si hay archivos modificados no commiteados:
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

- **Pre-requisito post-deploy**: el admin DEBE configurar manualmente los permisos en `/roles/edit/{id}` para cada rol antes de que los usuarios puedan ver listas. Sin configuración, todos los listados quedan vacíos. Coordinar el momento de la configuración con el deploy.
- **Orden de tasks**: Tasks 1 y 2 (catálogo + helper) deben ir antes que los services y controllers. Task 17 (eliminar getRoleVisibility) debe ir DESPUÉS de Task 3 (único caller que se removió). El resto puede alterarse si conviene, siempre que cada service se refactorice antes que su(s) controller(s) asociado(s).
- **Rollback**: si algo falla, `git revert <hash>` del commit problemático. Sin migration de seed no hay efecto en BD que revertir; solo código. Los datos que el admin haya configurado en `pipeline_permissions` se preservan.
- **Cambio aceptado de scope**: tras el deploy, `/invoices/all`, `/invoices/rejected` y `/invoices/overdue` ya no muestran anticipos. Si esto resulta inaceptable para reportes existentes, revertir la exclusión en esos 3 endpoints específicos (mantenerla solo en `index`).

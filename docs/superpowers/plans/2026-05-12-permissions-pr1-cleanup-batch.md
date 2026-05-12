# PR1 — Cleanup batch (PA-009 + PA-012 + PA-013 + PA-014) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cerrar 4 hallazgos menores de la auditoría de permisos sin cambio de comportamiento observable: añadir `getEmptyMatrix()` para evitar el accidente de `getPermissionsMatrix(0)`, documentar la caché per-request, deduplicar `STEP_LABELS` contra `STATUS_LABELS` donde haya match exacto, y reflejar el cierre de PA-012 (ya resuelto colateralmente por PA-004).

**Architecture:** Cuatro cambios independientes en archivos distintos. Cada uno es un commit separado para permitir bisect si algo se rompe. Sin migraciones, sin nuevas dependencias, sin cambios en interfaces públicas.

**Tech Stack:** PHP 8.4, CakePHP 5.3. No se añaden tests automatizados (política `CLAUDE.md` → Testing Policy). Validación manual en cada paso.

**Spec origen:** `docs/superpowers/specs/2026-05-12-permissions-audit-closeout-design.md`
**Audit origen:** `docs/audits/permissions-audit-2026-05-11.md`

---

## Resumen de cambios por archivo

- **Crear:** ninguno
- **Modificar:**
  - `src/Service/AuthorizationService.php` — añadir `getEmptyMatrix()`, ajustar docblock de clase.
  - `src/Service/PipelineAuthorizationService.php` — añadir `getEmptyMatrix()`, ajustar docblock de clase.
  - `src/Controller/RolesController.php` — reemplazar `getPermissionsMatrix(0)` por `getEmptyMatrix()` en `add()`.
  - `src/Constants/PipelineStepConstants.php` — sustituir literales de `STEP_LABELS` por referencias a `{Domain}Constants::STATUS_LABELS[step]` donde haya match exacto.
  - `docs/audits/permissions-audit-2026-05-11.md` — marcar PA-009/PA-012/PA-013/PA-014 como ✅ Resuelto y añadir bloques `> **Cierre:**`.

---

## Tarea 1 — PA-013: Docblocks de caché per-request

**Files:**
- Modify: `src/Service/AuthorizationService.php:9-17` (docblock de clase)
- Modify: `src/Service/PipelineAuthorizationService.php:9-17` (docblock de clase)

- [ ] **Paso 1.1 — Actualizar docblock de `AuthorizationService`**

En `src/Service/AuthorizationService.php`, reemplazar el docblock actual (líneas 9-17):

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

por:

```php
/**
 * Servicio CRUD de permisos. Consulta directa a `permissions` con cache
 * per-request.
 *
 * Caché: `$cache` se invalida explícitamente vía `invalidate(int $roleId)` y
 * tras `savePermissionsForRole()`. No persiste entre requests por diseño:
 * depende del scope per-request del container de CakePHP DI (la instancia se
 * recolecta al cerrar la respuesta). No promover a caché global sin invalidación
 * cross-request explícita.
 *
 * @internal Depender de `App\Authorization\AuthorizationFacade` en su lugar.
 * Esta clase concreta solo debe inyectarse en `RolesController` y
 * `AppController::_setUserPermissions` (matrices y save quedan fuera del
 * contrato del Facade — ver audit PA-004).
 */
```

- [ ] **Paso 1.2 — Actualizar docblock de `PipelineAuthorizationService`**

En `src/Service/PipelineAuthorizationService.php`, reemplazar el docblock actual (líneas 9-17):

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

por:

```php
/**
 * Resuelve si un rol puede operar (avanzar, regresar, editar campos, ver
 * sección) en un paso específico de un pipeline. Cache per-request.
 *
 * Caché: `$cache` se invalida explícitamente vía `invalidate(int $roleId)` y
 * tras `savePermissions()`. No persiste entre requests por diseño: depende del
 * scope per-request del container de CakePHP DI (la instancia se recolecta al
 * cerrar la respuesta). No promover a caché global sin invalidación
 * cross-request explícita.
 *
 * @internal Depender de `App\Authorization\AuthorizationFacade` en su lugar.
 * Esta clase concreta solo debe inyectarse en `RolesController` y
 * `AppController::_setUserPermissions` (matrices y save quedan fuera del
 * contrato del Facade — ver audit PA-004).
 */
```

- [ ] **Paso 1.3 — Validar code style**

Ejecutar:
```
composer cs-check
```
Esperado: `No code style errors found in ...` o equivalente sin errores.

- [ ] **Paso 1.4 — Commit**

```
git add src/Service/AuthorizationService.php src/Service/PipelineAuthorizationService.php
git commit -m "docs(auth): documentar caché per-request en AuthorizationService y PipelineAuthorizationService (PA-013)"
```

---

## Tarea 2 — PA-014: Deduplicar `STEP_LABELS` contra `STATUS_LABELS`

**Files:**
- Modify: `src/Constants/PipelineStepConstants.php:98-152`

**Contexto:** se verificó manualmente que existen 2 divergencias intencionales entre `STEP_LABELS` y `*Constants::STATUS_LABELS`:

- `NOVELTIES > STATUS_REVISION_FIRMAS`: STEP_LABELS dice `'Revisión y Firmas'`, NoveltyConstants dice `'Revisión y Firmas de documentos'`.
- `LIQUIDATION_DOCS > STATUS_REVISION_FIRMAS`: misma divergencia (es el mismo step de NoveltyConstants).

Estas 2 entradas se conservan como string literal con comentario inline; el resto se delega a `STATUS_LABELS` (PHP 8.4 soporta array element access en class constants).

- [ ] **Paso 2.1 — Reemplazar `STEP_LABELS`**

En `src/Constants/PipelineStepConstants.php`, reemplazar **el bloque completo** de `STEP_LABELS` (líneas 95-152) por:

```php
    /**
     * Etiquetas en español para mostrar en la UI de configuración.
     *
     * Las entradas referencian `STATUS_LABELS` de cada `*Constants` cuando el
     * label coincide; las 2 excepciones documentadas (Revisión y Firmas en
     * pipelines de novedades) se conservan como literal por divergencia
     * intencional con `NoveltyConstants::STATUS_LABELS`.
     */
    public const STEP_LABELS = [
        self::PIPELINE_INVOICES => [
            InvoiceConstants::STATUS_APROBACION => InvoiceConstants::STATUS_LABELS[InvoiceConstants::STATUS_APROBACION],
            InvoiceConstants::STATUS_CONTABILIDAD => InvoiceConstants::STATUS_LABELS[InvoiceConstants::STATUS_CONTABILIDAD],
            InvoiceConstants::STATUS_TESORERIA => InvoiceConstants::STATUS_LABELS[InvoiceConstants::STATUS_TESORERIA],
            InvoiceConstants::STATUS_AUTORIZACION_PAGO => InvoiceConstants::STATUS_LABELS[InvoiceConstants::STATUS_AUTORIZACION_PAGO],
            InvoiceConstants::STATUS_VERIFICACION_PAGO => InvoiceConstants::STATUS_LABELS[InvoiceConstants::STATUS_VERIFICACION_PAGO],
        ],
        self::PIPELINE_NOVELTIES => [
            NoveltyConstants::STATUS_APROBACION => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_APROBACION],
            NoveltyConstants::STATUS_RRHH => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_RRHH],
            NoveltyConstants::STATUS_CONTABILIDAD => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_CONTABILIDAD],
            // Divergencia intencional: NoveltyConstants dice 'Revisión y Firmas de documentos',
            // aquí se usa el label corto por espacio en la UI de matriz de permisos.
            NoveltyConstants::STATUS_REVISION_FIRMAS => 'Revisión y Firmas',
            NoveltyConstants::STATUS_GDP => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_GDP],
            NoveltyConstants::STATUS_TESORERIA => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_TESORERIA],
            NoveltyConstants::STATUS_AUTORIZACION_PAGO => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_AUTORIZACION_PAGO],
            NoveltyConstants::STATUS_VERIFICACION_PAGO => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_VERIFICACION_PAGO],
        ],
        self::PIPELINE_PAYMENT_SCHEDULINGS => [
            PaymentSchedulingConstants::STATUS_BORRADOR => PaymentSchedulingConstants::STATUS_LABELS[PaymentSchedulingConstants::STATUS_BORRADOR],
            PaymentSchedulingConstants::STATUS_TESORERIA => PaymentSchedulingConstants::STATUS_LABELS[PaymentSchedulingConstants::STATUS_TESORERIA],
            PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO => PaymentSchedulingConstants::STATUS_LABELS[PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO],
            PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO => PaymentSchedulingConstants::STATUS_LABELS[PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO],
        ],
        self::PIPELINE_REFUNDS => [
            RefundConstants::STATUS_AGRUPACION => RefundConstants::STATUS_LABELS[RefundConstants::STATUS_AGRUPACION],
            RefundConstants::STATUS_CONTABILIDAD => RefundConstants::STATUS_LABELS[RefundConstants::STATUS_CONTABILIDAD],
            RefundConstants::STATUS_TESORERIA => RefundConstants::STATUS_LABELS[RefundConstants::STATUS_TESORERIA],
            RefundConstants::STATUS_AUTORIZACION_PAGO => RefundConstants::STATUS_LABELS[RefundConstants::STATUS_AUTORIZACION_PAGO],
            RefundConstants::STATUS_VERIFICACION_PAGO => RefundConstants::STATUS_LABELS[RefundConstants::STATUS_VERIFICACION_PAGO],
        ],
        self::PIPELINE_PETTY_CASH => [
            PettyCashConstants::STATUS_AGRUPACION => PettyCashConstants::STATUS_LABELS[PettyCashConstants::STATUS_AGRUPACION],
            PettyCashConstants::STATUS_CONTABILIDAD => PettyCashConstants::STATUS_LABELS[PettyCashConstants::STATUS_CONTABILIDAD],
            PettyCashConstants::STATUS_TESORERIA => PettyCashConstants::STATUS_LABELS[PettyCashConstants::STATUS_TESORERIA],
            PettyCashConstants::STATUS_AUTORIZACION_PAGO => PettyCashConstants::STATUS_LABELS[PettyCashConstants::STATUS_AUTORIZACION_PAGO],
            PettyCashConstants::STATUS_VERIFICACION_PAGO => PettyCashConstants::STATUS_LABELS[PettyCashConstants::STATUS_VERIFICACION_PAGO],
        ],
        self::PIPELINE_LEGALIZATIONS => [
            AdvanceConstants::STATUS_VALIDACION => AdvanceConstants::STATUS_LABELS[AdvanceConstants::STATUS_VALIDACION],
            AdvanceConstants::STATUS_REVISION_FIRMAS => AdvanceConstants::STATUS_LABELS[AdvanceConstants::STATUS_REVISION_FIRMAS],
            AdvanceConstants::STATUS_CONTABILIDAD => AdvanceConstants::STATUS_LABELS[AdvanceConstants::STATUS_CONTABILIDAD],
            AdvanceConstants::STATUS_TESORERIA => AdvanceConstants::STATUS_LABELS[AdvanceConstants::STATUS_TESORERIA],
            AdvanceConstants::STATUS_AUTORIZACION_PAGO => AdvanceConstants::STATUS_LABELS[AdvanceConstants::STATUS_AUTORIZACION_PAGO],
            AdvanceConstants::STATUS_VERIFICACION_PAGO => AdvanceConstants::STATUS_LABELS[AdvanceConstants::STATUS_VERIFICACION_PAGO],
        ],
        self::PIPELINE_LIQUIDATION_DOCS => [
            NoveltyConstants::STATUS_CONTABILIDAD => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_CONTABILIDAD],
            // Divergencia intencional (idem PIPELINE_NOVELTIES).
            NoveltyConstants::STATUS_REVISION_FIRMAS => 'Revisión y Firmas',
            NoveltyConstants::STATUS_GDP => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_GDP],
            NoveltyConstants::STATUS_TESORERIA => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_TESORERIA],
            NoveltyConstants::STATUS_AUTORIZACION_PAGO => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_AUTORIZACION_PAGO],
            NoveltyConstants::STATUS_VERIFICACION_PAGO => NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_VERIFICACION_PAGO],
        ],
    ];
```

Importante:
- `PettyCashConstants` y `RefundConstants` heredan `STATUS_LABELS` del `GroupingPipelineConstantsTrait`. La referencia `RefundConstants::STATUS_LABELS[...]` resuelve a través del trait sin necesidad de cambios adicionales.
- No tocar `STEPS_BY_PIPELINE`, `PIPELINE_LABELS`, ni `isValid()`.

- [ ] **Paso 2.2 — Validar sintaxis PHP**

Ejecutar:
```
php -l src/Constants/PipelineStepConstants.php
```
Esperado: `No syntax errors detected in src/Constants/PipelineStepConstants.php`.

- [ ] **Paso 2.3 — Validar code style**

Ejecutar:
```
composer cs-check
```
Esperado: sin errores.

- [ ] **Paso 2.4 — Validar resolución de constantes en runtime**

Levantar el servidor:
```
php bin/cake server
```
En el navegador con sesión de admin:

1. Visitar `/roles/edit/1` (rol admin). Confirmar que la tabla de pipeline permissions muestra las mismas etiquetas que antes para cada pipeline (Facturas, Novedades, Programación, Reintegros, Caja menor, Legalizaciones, Documentos de liquidación).
2. Visitar `/roles/add`. Confirmar que la matriz se renderiza con todos los pipelines y sus steps con label correcto.
3. Visitar `/roles/view/1`. Confirmar las mismas etiquetas.
4. **Específicamente**: confirmar que el step `STATUS_REVISION_FIRMAS` en NOVELTIES y LIQUIDATION_DOCS muestra `'Revisión y Firmas'` (no `'Revisión y Firmas de documentos'`).

Si alguna etiqueta sale distinta, revisar que `STATUS_LABELS` de cada `*Constants` esté completo para los steps listados en `STEPS_BY_PIPELINE`. Si falta alguno, agregar la entrada al `*Constants` correspondiente o conservar el literal aquí con comentario.

- [ ] **Paso 2.5 — Commit**

```
git add src/Constants/PipelineStepConstants.php
git commit -m "refactor(auth): STEP_LABELS delega a STATUS_LABELS de cada *Constants (PA-014)"
```

---

## Tarea 3 — PA-009: `getEmptyMatrix()` en lugar de `getPermissionsMatrix(0)`

**Files:**
- Modify: `src/Service/PipelineAuthorizationService.php` (añadir método)
- Modify: `src/Service/AuthorizationService.php` (añadir método simétrico)
- Modify: `src/Controller/RolesController.php:80-81` (reemplazar caller)

- [ ] **Paso 3.1 — Añadir `getEmptyMatrix()` en `PipelineAuthorizationService`**

En `src/Service/PipelineAuthorizationService.php`, insertar el siguiente método **inmediatamente después** del método `getPermissionsMatrix()` (después de la línea 73, antes de `savePermissions()`):

```php
    /**
     * Devuelve una matriz vacía (todos los steps en `false`) sin consultar BD.
     * Útil en `RolesController::add` donde aún no existe `role_id`.
     *
     * @return array<string, array<string, bool>> matrix[pipeline][step] = false
     */
    public function getEmptyMatrix(): array
    {
        $matrix = [];

        foreach (PipelineStepConstants::STEPS_BY_PIPELINE as $pipeline => $steps) {
            $matrix[$pipeline] = [];
            foreach ($steps as $step) {
                $matrix[$pipeline][$step] = false;
            }
        }

        return $matrix;
    }
```

- [ ] **Paso 3.2 — Añadir `getEmptyPermissionsMatrix()` en `AuthorizationService`**

En `src/Service/AuthorizationService.php`, insertar el siguiente método **inmediatamente después** de `getPermissionsForRoleAsMatrix()` (después de la línea 128, antes de `savePermissionsForRole()`):

```php
    /**
     * Devuelve una matriz vacía (todos los módulos con CRUD en `false`) sin
     * consultar BD. Útil en `RolesController::add` donde aún no existe
     * `role_id`. Espejo simétrico a
     * `PipelineAuthorizationService::getEmptyMatrix()`.
     *
     * @return array<string, array<string, bool>> matrix[module][action] = false
     */
    public function getEmptyPermissionsMatrix(): array
    {
        $matrix = [];

        foreach (array_keys(self::MODULES) as $module) {
            $matrix[$module] = [
                'can_view' => false,
                'can_create' => false,
                'can_edit' => false,
                'can_delete' => false,
            ];
        }

        return $matrix;
    }
```

- [ ] **Paso 3.3 — Migrar `RolesController::add()` a usar los nuevos métodos**

En `src/Controller/RolesController.php`, dentro de `add()` (líneas 78-87), reemplazar:

```php
        $modules = AuthorizationService::MODULES;
        $permissionsMatrix = [];
        $pipelineMatrix = $this->pipelineAuth->getPermissionsMatrix(0);
        $pipelineLabels = PipelineStepConstants::PIPELINE_LABELS;
        $stepLabels = PipelineStepConstants::STEP_LABELS;

        $this->set(compact('role', 'modules', 'permissionsMatrix', 'pipelineMatrix', 'pipelineLabels', 'stepLabels'));
```

por:

```php
        $modules = AuthorizationService::MODULES;
        $permissionsMatrix = $this->authService->getEmptyPermissionsMatrix();
        $pipelineMatrix = $this->pipelineAuth->getEmptyMatrix();
        $pipelineLabels = PipelineStepConstants::PIPELINE_LABELS;
        $stepLabels = PipelineStepConstants::STEP_LABELS;

        $this->set(compact('role', 'modules', 'permissionsMatrix', 'pipelineMatrix', 'pipelineLabels', 'stepLabels'));
```

Notas:
- `$permissionsMatrix = []` actual deja la plantilla sin checkboxes CRUD pre-renderizados. Cambiarlo a `getEmptyPermissionsMatrix()` puede afectar el render. **Antes de aplicar este cambio**, revisar `templates/Roles/add.php` para confirmar cómo itera `$permissionsMatrix`. Si itera `$modules` y consulta `$permissionsMatrix[$module] ?? [...]`, ambos shapes funcionan. Si itera `$permissionsMatrix` directamente, el cambio mejora la corrección (antes mostraba 0 filas, ahora todas con checkboxes desmarcados).
- `$this->authService` está disponible en `AppController` (verificar; si no, mantener `$permissionsMatrix = []` y solo cambiar `$pipelineMatrix`).

- [ ] **Paso 3.4 — Verificar template `Roles/add.php` antes de commit**

Leer `templates/Roles/add.php` y confirmar cómo se itera `$permissionsMatrix`. Decidir entre:

**A.** El template itera `$modules` y usa `$permissionsMatrix[$module] ?? [...]` → mantener `getEmptyPermissionsMatrix()`, ningún cambio en template.

**B.** El template itera `$permissionsMatrix` directamente y antes funcionaba con `[]` (matriz vacía → 0 filas, sintomático del bug) → mantener `getEmptyPermissionsMatrix()`, el cambio CORRIGE el render que estaba mostrando 0 filas CRUD.

**C.** El template asume `$permissionsMatrix = []` para mostrar algo específico → revertir solo esa línea a `$permissionsMatrix = []` y mantener `getEmptyMatrix()` para el pipeline. Documentar en comentario.

- [ ] **Paso 3.5 — Validar sintaxis y code style**

Ejecutar:
```
php -l src/Service/PipelineAuthorizationService.php
php -l src/Service/AuthorizationService.php
php -l src/Controller/RolesController.php
composer cs-check
```
Esperado: sin errores en ningún archivo.

- [ ] **Paso 3.6 — Validación manual**

Levantar:
```
php bin/cake server
```

Como admin:

1. `/roles/add` → verificar render del formulario. Matriz pipeline: todos los checkboxes desmarcados (igual o mejor que antes). Matriz CRUD: comportamiento esperado según decisión del paso 3.4.
2. Llenar el formulario, crear un rol nuevo, confirmar redirect a `/roles` y entrada presente.
3. Editar el rol recién creado en `/roles/edit/{id}` → confirmar que todos los checkboxes están desmarcados como cabía esperar.
4. `/roles/edit/1` (admin) → matriz idéntica a antes del cambio.
5. `/roles/view/1` → matriz idéntica a antes del cambio.

- [ ] **Paso 3.7 — Commit**

```
git add src/Service/PipelineAuthorizationService.php src/Service/AuthorizationService.php src/Controller/RolesController.php
git commit -m "feat(auth): getEmptyMatrix/getEmptyPermissionsMatrix para RolesController::add (PA-009)"
```

---

## Tarea 4 — PA-012: Verificar ausencia de fallbacks y cerrar en doc

**Files:**
- (read-only) `src/**` — verificación
- Modify: `docs/audits/permissions-audit-2026-05-11.md` (cierre de PA-012)

**Contexto:** durante la fase de planning se verificó que no quedan ocurrencias de `new PipelineAuthorizationService()` ni `new AuthorizationService()` como fallback en el código. PA-012 fue resuelto colateralmente por la migración a `AuthorizationFacade` (PA-004). Esta tarea solo verifica nuevamente y refleja el cierre en el documento de auditoría.

- [ ] **Paso 4.1 — Re-verificar ausencia de fallbacks**

Ejecutar (vía la herramienta Grep):

Patrón 1: `\?\? new (PipelineAuthorizationService|AuthorizationService|AuthorizationFacade)` en `src/`.
Esperado: sin coincidencias.

Patrón 2: `PipelineAuthorizationService \$pipelineAuth = null` y `PipelineAuthorizationService\? \$pipelineAuth` en `src/`.
Esperado: sin coincidencias.

Patrón 3: `AuthorizationService \$authService = null` y `AuthorizationService\? \$authService` en `src/`.
Esperado: sin coincidencias.

Si alguna coincidencia aparece, **abortar esta tarea** y crear un sub-paso para eliminar el fallback antes de cerrar PA-012.

---

(Continúa en Tarea 5 con la actualización del documento de auditoría — los cambios de Tarea 4 y 5 viajan en el mismo commit doc-only.)

---

## Tarea 5 — Actualización del documento de auditoría

**Files:**
- Modify: `docs/audits/permissions-audit-2026-05-11.md` (tabla "Estado de remediación" + bloques `> **Cierre:**` para PA-009, PA-012, PA-013, PA-014).

- [ ] **Paso 5.1 — Actualizar la tabla "Estado de remediación"**

En `docs/audits/permissions-audit-2026-05-11.md`, dentro de la tabla "## Estado de remediación", cambiar las 4 filas siguientes de `⏳ Pendiente | —` a `✅ Resuelto | commit del PR1`:

- Fila PA-009.
- Fila PA-012.
- Fila PA-013.
- Fila PA-014.

Usar el hash del commit más reciente de PR1 como referencia (resolver tras hacer los commits anteriores; típicamente el hash del commit de Tarea 3 o un rango).

- [ ] **Paso 5.2 — Añadir bloque `> **Cierre:**` bajo cada hallazgo**

Para **PA-009** (sección `## PA-009 — ...`), añadir justo después del título, antes del párrafo "Ubicación:":

```markdown
> **Cierre:** commit `<hash-tarea-3>` añadió `PipelineAuthorizationService::getEmptyMatrix()` y `AuthorizationService::getEmptyPermissionsMatrix()` (espejo simétrico). `RolesController::add` ahora usa los nuevos métodos en lugar de `getPermissionsMatrix(0)`/`[]`. Validación manual: render de `/roles/add`, creación de rol nuevo, edición posterior — comportamiento idéntico o mejor (CRUD matrix antes vacío, ahora con checkboxes desmarcados explícitos).
```

Para **PA-012**:

```markdown
> **Cierre:** verificación negativa tras PA-004. `grep` exhaustivo (`?? new (Pipeline)?AuthorizationService(...)` y constructores con parámetro nullable en services del módulo de permisos) sin coincidencias. La migración a `AuthorizationFacade` (commits `424249d..3949528`) ya había eliminado los 6 fallbacks listados en el inventario original.
```

Para **PA-013**:

```markdown
> **Cierre:** commit `<hash-tarea-1>` añadió docblock explícito en `AuthorizationService` y `PipelineAuthorizationService` describiendo la política de caché (invalidación vía `invalidate()` y `save*Permissions()`, no persiste entre requests, no promover a caché global sin invalidación cross-request).
```

Para **PA-014**:

```markdown
> **Cierre:** commit `<hash-tarea-2>` migró `PipelineStepConstants::STEP_LABELS` a referenciar `STATUS_LABELS` de cada `*Constants` donde el label coincide (5 de 7 pipelines completos, 2 entradas literales conservadas por divergencia intencional en `STATUS_REVISION_FIRMAS` de novedades). Drift entre fuentes eliminado para los matches; las divergencias quedaron documentadas inline. Validación manual: `/roles/edit`, `/roles/add`, `/roles/view` renderizan etiquetas idénticas a antes del cambio.
```

- [ ] **Paso 5.3 — Validar el documento**

Releer el documento completo, especialmente:
- La tabla "Estado de remediación" tras los cambios.
- Los 4 bloques `> **Cierre:**` con hashes reales.
- Que no haya placeholders `<hash-tarea-N>` sin resolver.

- [ ] **Paso 5.4 — Commit**

```
git add docs/audits/permissions-audit-2026-05-11.md
git commit -m "docs(auth): cerrar PA-009/PA-012/PA-013/PA-014 en audit doc"
```

---

## Validación global de PR1

Tras los 4 commits de cierre, ejecutar la batería completa:

- [ ] **Paso 6.1 — Code style**

```
composer cs-check
```
Esperado: sin errores.

- [ ] **Paso 6.2 — Boot del servidor**

```
php bin/cake server
```
Esperado: el servidor levanta sin warnings ni errores. Detener con Ctrl+C.

- [ ] **Paso 6.3 — Smoke E2E (UI permisos)**

Con el servidor levantado, login como admin:

1. `/roles` → listado renderiza.
2. `/roles/add` → formulario completo (CRUD matrix + pipeline matrix con todos los checkboxes desmarcados).
3. Crear un rol nuevo con permisos parciales (e.g., solo `can_view` en `invoices` + 2 steps de pipeline en novedades) → redirect a `/roles`.
4. `/roles/edit/{nuevo}` → checkboxes reflejan exactamente lo guardado.
5. `/roles/view/{nuevo}` → matriz idéntica.
6. `/roles/edit/1` (admin) → matriz inalterada respecto al baseline pre-PR1.
7. (Opcional) eliminar el rol de prueba si el sistema lo permite.

- [ ] **Paso 6.4 — Smoke E2E (pipeline)**

Como admin (o un rol con permisos suficientes):

1. `/invoices` → listado renderiza, los nombres de los steps en cualquier badge/pipeline-progress son los mismos que antes.
2. `/employee-novelties` → idem; específicamente verificar que el step `STATUS_REVISION_FIRMAS` se muestre en la UI con el label que correspondía antes (puede ser `'Revisión y Firmas de documentos'` si la vista usa `NoveltyConstants::STATUS_LABELS`, o `'Revisión y Firmas'` si usa `PipelineStepConstants::STEP_LABELS`; ambas son válidas según la fuente que se consulte).

Si algún label sale distinto donde no se esperaba, revisar el `*Constants` del dominio.

---

## Self-review (post-plan)

Revisión final del plan vs spec:

- ✅ PA-009 cubierto en Tarea 3 (método nuevo en ambos services + uso en `RolesController::add`).
- ✅ PA-012 cubierto en Tarea 4 (verificación + cierre doc).
- ✅ PA-013 cubierto en Tarea 1 (docblocks en ambos services).
- ✅ PA-014 cubierto en Tarea 2 (refactor de `STEP_LABELS` con divergencias documentadas).
- ✅ Doc de auditoría actualizado en Tarea 5.

**Posibles puntos de fricción durante ejecución:**

1. Paso 3.4 (template `Roles/add.php`): la decisión A/B/C exige leer el template antes de aplicar el cambio. Si el ejecutor no encuentra el template o lo lee mal, podría introducir un regression de UI en `/roles/add`. Mitigación: el paso lo obliga a leerlo explícitamente y elegir antes de seguir.

2. Paso 2.4 (PA-014): si `STATUS_LABELS` de algún `*Constants` no incluye uno de los steps que `STEPS_BY_PIPELINE` lista, la constante explota en boot. Validar con `php -l` no atrapa esto (necesita ejecución). El paso 2.4 cubre el riesgo cargando `/roles/edit/1`.

3. Paso 5.2: los hashes de commit hay que llenarlos a mano tras los commits previos. No usar placeholders sin resolver.

# Empleados — Unificar vista principal y eliminar `index` (Plan de implementación)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminar `index` como pantalla del módulo Empleados; `/employees` queda como redirect inteligente al primer empleado preservando filtros. La vista `view.php` absorbe los features que vivían solo en `index` (botones Excel, filtros de Cargo/Centro de Operación, paginación 15/pág del panel lateral).

**Architecture:** `EmployeesController::index()` se reescribe como un selector de id+redirect; cuando no hay matches renderiza un template empty-state minimal. `view()` ajusta la preparación de `navEmployees` para paginar (15/pág, scope `nav`) y expone catálogos para los nuevos selects del panel lateral. El template `view.php` enriquece el panel izquierdo (`sgi-md-left`) con: header con botones Excel, collapse de filtros, propagación completa de query string en los links, y paginación. `edit.php` no se toca.

**Tech Stack:** CakePHP 5.3, PHP 8.4, MySQL/MariaDB. Bootstrap 5, JetBrains Mono / Inter Variable. Sin tests automatizados — validación manual contra `php bin/cake server`.

**Spec:** `docs/superpowers/specs/2026-05-19-employees-unificar-vista-design.md`

**Política de testing:** Este proyecto NO usa tests automatizados (`CLAUDE.md` § Testing Policy). Cada tarea termina con **pasos de validación manual** (curl o navegador) + commit.

---

## File Structure

| Archivo | Tipo | Responsabilidad |
|---|---|---|
| `src/Controller/EmployeesController.php` | Modify | `index()` se reescribe (redirect-to-first / empty-state). `view()` pagina `navEmployees` y expone catálogos. |
| `templates/Employees/index.php` | Replace | Empty-state minimal (~40 líneas) renderizado solo cuando no hay matches. |
| `templates/Employees/view.php` | Modify | Panel izquierdo enriquecido: header con Excel, collapse de filtros, paginación, links con query string completo, modales Excel al final. |
| `webroot/css/styles.css` | Modify | Añadir `.sgi-md-pagination` (compacto). Ajustar `.sgi-md-left-head` si el header se desborda. |

Sin cambios en: `EmployeeFilterService` (ya soporta `position_id` y `operation_center_id`), routing, sidebar, permisos, `edit.php`, `add.php`, `EmployeeDocumentService`, `EmployeeHistoryService`.

---

## Tarea 1 — Reescribir `index()` y reemplazar `templates/Employees/index.php` por empty-state

**Files:**
- Modify: `src/Controller/EmployeesController.php:44-60` (acción `index`)
- Replace: `templates/Employees/index.php` (230 líneas → ~50 líneas empty-state)

- [ ] **Step 1: Reescribir la acción `index()` en `src/Controller/EmployeesController.php`**

Reemplazar las líneas 44-60 actuales por:

```php
    #[Permission(action: 'view')]
    public function index()
    {
        // El orderBy debe ser IDENTICO al de view()::navQuery para que el primer
        // empleado seleccionado coincida con la primera fila visible del panel lateral.
        $query = $this->Employees->find()
            ->select(['Employees.id'])
            ->orderBy(['Employees.last_name1' => 'ASC', 'Employees.last_name2' => 'ASC']);

        $this->filterService->apply($query, $this->request->getQueryParams());

        $firstId = $query->first()?->id;

        if ($firstId === null) {
            // Distingue "BD vacia" (no hay un solo empleado) de "filtros sin matches".
            $hasAnyEmployee = $this->Employees->exists([]);
            $this->set(compact('hasAnyEmployee'));

            return null; // renderiza templates/Employees/index.php (empty-state)
        }

        return $this->redirect([
            'action' => 'view',
            $firstId,
            '?' => $this->request->getQueryParams() ?: null,
        ]);
    }
```

Notas:
- Borrar el `compact('employees', 'positions', 'operationCenters', 'employeeStatuses')` viejo.
- No remover el atributo `#[Permission(action: 'view')]`.
- Mantener el orden de métodos del archivo (no mover la acción).

- [ ] **Step 2: Reemplazar `templates/Employees/index.php` con empty-state**

Sustituir todo el contenido del archivo por:

```php
<?php
/**
 * Empty-state de empleados. Se renderiza solo cuando index() no encuentra
 * un primer empleado para redirigir.
 *
 * @var \App\View\AppView $this
 * @var bool $hasAnyEmployee true si existe al menos un empleado en BD
 *                           (filtros sin matches) vs false (BD totalmente vacia).
 */
$this->assign('title', 'Empleados');

$canCreate = !empty($userPermissions['employees']['can_create']);
$query = $this->request->getQueryParams();
$activeStatus = $this->request->getQuery('status') ?: \App\Constants\EmployeeStatusConstants::ACTIVO;
$navTabs = [
    [\App\Constants\EmployeeStatusConstants::ACTIVO,   'Activos'],
    [\App\Constants\EmployeeStatusConstants::RETIRADO, 'Retirados'],
    ['all',                                            'Todos'],
];
$navSearch = (string)$this->request->getQuery('search', '');
$tabBaseQuery = $navSearch !== '' ? ['search' => $navSearch] : [];
?>
<div class="sgi-master-detail">
    <aside class="sgi-md-left">
        <div class="sgi-md-left-head">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <div class="sgi-title-card">Empleados</div>
                    <div class="sgi-body-faint mt-1">0 mostrados</div>
                </div>
                <?php if ($canCreate): ?>
                <?= $this->Html->link(
                    '<i class="bi bi-plus-lg" aria-hidden="true"></i>Nuevo',
                    ['action' => 'add'],
                    ['class' => 'btn btn-primary btn-sm', 'escape' => false]
                ) ?>
                <?php endif; ?>
            </div>
            <form method="get" class="sgi-md-search mb-2" role="search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="text" name="search"
                       value="<?= h($navSearch) ?>"
                       placeholder="Buscar por nombre, CC o correo…"
                       aria-label="Buscar empleados"
                       autocomplete="off">
                <input type="hidden" name="status" value="<?= h($activeStatus) ?>">
            </form>
            <div class="sgi-status-tabs" role="tablist" aria-label="Filtrar por estado">
                <?php foreach ($navTabs as [$status, $label]):
                    $isActive = ($activeStatus === $status);
                ?>
                    <?= $this->Html->link(
                        h($label),
                        ['action' => 'index', '?' => $tabBaseQuery + ['status' => $status]],
                        [
                            'class' => 'sgi-status-tab' . ($isActive ? ' is-active' : ''),
                            'escape' => false,
                            'role' => 'tab',
                            'aria-selected' => $isActive ? 'true' : 'false',
                        ]
                    ) ?>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="sgi-md-left-list">
            <div class="sgi-doc-empty">
                <i class="bi bi-search sgi-doc-empty-icon" aria-hidden="true"></i>
                <div class="sgi-fg-muted">Sin resultados</div>
            </div>
        </div>
    </aside>

    <section class="sgi-md-right">
        <div class="card">
            <div class="sgi-doc-empty" style="padding:4rem 2rem;text-align:center;">
                <i class="bi bi-people sgi-doc-empty-icon" aria-hidden="true" style="font-size:3rem;"></i>
                <?php if ($hasAnyEmployee): ?>
                    <h2 class="sgi-title-card mt-3">Sin empleados que coincidan con los filtros</h2>
                    <p class="sgi-body-muted mt-2">Prueba a limpiar la búsqueda o cambiar el estado en el panel izquierdo.</p>
                <?php else: ?>
                    <h2 class="sgi-title-card mt-3">Aún no hay empleados registrados</h2>
                    <p class="sgi-body-muted mt-2">Comienza creando el primer empleado del sistema.</p>
                <?php endif; ?>
                <?php if ($canCreate): ?>
                <div class="mt-3">
                    <?= $this->Html->link(
                        '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Crear empleado',
                        ['action' => 'add'],
                        ['class' => 'btn btn-primary', 'escape' => false]
                    ) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>
```

- [ ] **Step 3: Validación manual — redirect básico**

Levantar el servidor:

```bash
php bin/cake server
```

En el navegador (logueado como admin), abrir `http://localhost:8765/employees`. Esperado: redirect a `/employees/view/{id}` con el primer empleado alfabético seleccionado en el master-detail.

- [ ] **Step 4: Validación manual — empty-state por filtros sin matches**

En el navegador, abrir `http://localhost:8765/employees?search=zzzzzzzzz`. Esperado: NO redirige; renderiza la pantalla con:
- Panel izquierdo con el search "zzzzzzzzz", tabs y mensaje "Sin resultados".
- Panel derecho con icono de gente, título "Sin empleados que coincidan con los filtros" + botón "Crear empleado".

- [ ] **Step 5: Verificar code style**

```bash
composer cs-check
```

Esperado: sin errores en `src/Controller/EmployeesController.php` ni en `templates/Employees/index.php`.

Si hay errores: `composer cs-fix`.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/EmployeesController.php templates/Employees/index.php
git -c commit.gpgsign=false commit -m "$(cat <<'EOF'
refactor(employees): convertir index en redirect-to-first con empty-state

EmployeesController::index() ahora autoselecciona el primer empleado
por orden alfabetico y redirige a /employees/view/{id} preservando
filtros del query string. Cuando no hay matches renderiza un template
empty-state minimal que distingue "BD vacia" de "filtros sin matches".

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Tarea 2 — Paginar `navEmployees` en `view()` y exponer catálogos

**Files:**
- Modify: `src/Controller/EmployeesController.php:62-121` (acción `view`)

- [ ] **Step 1: Modificar la preparación de `navEmployees` en `view()`**

En `src/Controller/EmployeesController.php`, dentro de la acción `view()`, sustituir el bloque actual (líneas 111-119, aproximadas):

```php
        // Lista para el navegador izquierdo (master-detail). Aplica los mismos
        // filtros del query string que index() para coherencia con el atajo
        // /employees -> click en empleado -> /employees/view/{id}?search=...
        $navQuery = $this->Employees->find('withCurrentNovelty')
            ->contain(['Positions', 'OperationCenters'])
            ->orderBy(['Employees.last_name1' => 'ASC', 'Employees.last_name2' => 'ASC']);
        $this->filterService->apply($navQuery, $this->request->getQueryParams());
        $navEmployees = $navQuery->limit(200)->all()->toArray();
        $navStatus = $this->request->getQuery('status') ?: \App\Constants\EmployeeStatusConstants::ACTIVO;
        $navSearch = (string)$this->request->getQuery('search', '');

        $this->set(compact('employee', 'folders', 'currentNovelty', 'navEmployees', 'navStatus', 'navSearch'));
        $this->set('fieldLabels', EmployeeHistoryService::FIELD_LABELS);
```

por:

```php
        // Lista para el navegador izquierdo (master-detail). Aplica los mismos
        // filtros del query string que index() para coherencia con el atajo
        // /employees -> click en empleado -> /employees/view/{id}?search=...
        //
        // El orderBy debe ser IDENTICO al de index() para que la autoseleccion
        // del primer empleado coincida con la primera fila visible aqui.
        $navQuery = $this->Employees->find('withCurrentNovelty')
            ->contain(['Positions', 'OperationCenters'])
            ->orderBy(['Employees.last_name1' => 'ASC', 'Employees.last_name2' => 'ASC']);
        $this->filterService->apply($navQuery, $this->request->getQueryParams());

        $navEmployees = $this->paginate($navQuery, ['scope' => 'nav']);
        $navStatus = $this->request->getQuery('status') ?: \App\Constants\EmployeeStatusConstants::ACTIVO;
        $navSearch = (string)$this->request->getQuery('search', '');
        $navPositionId = (string)$this->request->getQuery('position_id', '');
        $navOperationCenterId = (string)$this->request->getQuery('operation_center_id', '');

        // Catalogos para los filtros avanzados del panel lateral.
        $positions = $this->Employees->Positions->find('codeList')->all();
        $operationCenters = $this->Employees->OperationCenters->find('codeList')->all();

        $this->set(compact(
            'employee',
            'folders',
            'currentNovelty',
            'navEmployees',
            'navStatus',
            'navSearch',
            'navPositionId',
            'navOperationCenterId',
            'positions',
            'operationCenters',
        ));
        $this->set('fieldLabels', EmployeeHistoryService::FIELD_LABELS);
```

- [ ] **Step 2: Ajustar el check de lista vacía en `view.php`**

`$navEmployees` pasa de `array` a `PaginatedResultSet`. El `empty()` actual del template puede no funcionar correctamente sobre el ResultSet. En `templates/Employees/view.php`, localizar (aproximadamente línea 150):

```php
        <?php if (empty($navEmployees)): ?>
        <div class="sgi-doc-empty">
            <i class="bi bi-search sgi-doc-empty-icon" aria-hidden="true"></i>
            <div class="sgi-fg-muted">Sin resultados</div>
        </div>
        <?php endif; ?>
```

Reemplazar `empty($navEmployees)` por `count($navEmployees) === 0`:

```php
        <?php if (count($navEmployees) === 0): ?>
        <div class="sgi-doc-empty">
            <i class="bi bi-search sgi-doc-empty-icon" aria-hidden="true"></i>
            <div class="sgi-fg-muted">Sin resultados</div>
        </div>
        <?php endif; ?>
```

`PaginatedResultSet` implementa `Countable`, así que `count()` es seguro y semántico.

- [ ] **Step 3: Validación manual — paginación activa pero template sin cambios visuales todavía**

Recargar `http://localhost:8765/employees/view/{cualquier-id}`. Esperado: la página carga sin errores PHP. El panel lateral muestra hasta 15 empleados (en vez de los 200 anteriores). Las nuevas variables (`navPositionId`, `navOperationCenterId`, `positions`, `operationCenters`) están disponibles pero aún no se usan en el template — eso es la siguiente tarea.

Si hay error con el scope: verificar que el `paginate` del controlador (línea 23: `public array $paginate = ['limit' => 15, 'maxLimit' => 15];`) soporta scopes — CakePHP 5 lo soporta nativo.

- [ ] **Step 4: Verificar code style**

```bash
composer cs-check
```

- [ ] **Step 5: Commit**

```bash
git add src/Controller/EmployeesController.php templates/Employees/view.php
git -c commit.gpgsign=false commit -m "$(cat <<'EOF'
refactor(employees): paginar lista lateral y exponer catalogos a view

navEmployees ahora se pagina via $this->paginate con scope 'nav' (15/pag)
en lugar de limit(200) plano. Se exponen positions y operationCenters
para alimentar los filtros del panel lateral en la siguiente tarea.
Ajustado el check de lista vacia de empty() a count() porque navEmployees
es ahora un PaginatedResultSet (Countable) y empty() sobre objetos no
inspecciona Countable.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Tarea 3 — Filtros Cargo / Centro de Operación en el panel lateral de `view.php`

**Files:**
- Modify: `templates/Employees/view.php` (header del panel izquierdo, líneas 84-92)

- [ ] **Step 1: Añadir variables al docblock del template**

En `templates/Employees/view.php`, ampliar el docblock superior (líneas 1-10) para reflejar las nuevas variables:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Employee $employee
 * @var iterable $folders
 * @var \App\Model\Entity\User|null $currentUser
 * @var array<\App\Model\Entity\Employee> $navEmployees
 * @var string $navStatus
 * @var string $navSearch
 * @var string $navPositionId
 * @var string $navOperationCenterId
 * @var iterable $positions
 * @var iterable $operationCenters
 */
```

- [ ] **Step 2: Calcular `navBaseQuery` con los filtros adicionales**

En `templates/Employees/view.php`, dentro del bloque `<?php ?>` superior (después de definir `$navTabs` ~línea 50), reemplazar:

```php
$navBaseQuery = $navSearch !== '' ? ['search' => $navSearch] : [];
```

por:

```php
// Query string base preservado al cambiar tabs/filtros/seleccionar empleado.
$navBaseQuery = array_filter([
    'search' => $navSearch,
    'position_id' => $navPositionId,
    'operation_center_id' => $navOperationCenterId,
], fn($v) => $v !== '' && $v !== null);

// Count de filtros avanzados activos (para el badge del trigger).
$navAdvancedFilterCount = ($navPositionId !== '' ? 1 : 0)
    + ($navOperationCenterId !== '' ? 1 : 0);
$navAdvancedFiltersOpen = $navAdvancedFilterCount > 0;
```

- [ ] **Step 3: Insertar el bloque de filtros entre el `<form>` de búsqueda y los tabs de estado**

En `templates/Employees/view.php`, localizar el cierre del `<form method="get" class="sgi-md-search ...">` (aproximadamente línea 92) y, antes del `<div class="sgi-status-tabs" ...>`, insertar:

```php
        <form method="get" class="mb-2">
            <input type="hidden" name="search" value="<?= h($navSearch) ?>">
            <input type="hidden" name="status" value="<?= h($navStatus) ?>">

            <button type="button"
                    class="btn btn-ghost-card btn-sm w-100 d-flex justify-content-between align-items-center"
                    data-bs-toggle="collapse"
                    data-bs-target="#empNavFilters"
                    aria-expanded="<?= $navAdvancedFiltersOpen ? 'true' : 'false' ?>"
                    aria-controls="empNavFilters">
                <span>
                    <i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtros
                    <?php if ($navAdvancedFilterCount > 0): ?>
                        · <span class="sgi-fg-primary"><?= $navAdvancedFilterCount ?></span>
                    <?php endif; ?>
                </span>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </button>

            <div class="collapse <?= $navAdvancedFiltersOpen ? 'show' : '' ?> mt-2" id="empNavFilters">
                <div class="mb-2">
                    <label class="sgi-label" for="emp-nav-position">Cargo</label>
                    <?= $this->Form->select('position_id', $positions, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $navPositionId,
                        'id' => 'emp-nav-position',
                        'onchange' => 'this.form.submit()',
                    ]) ?>
                </div>
                <div class="mb-1">
                    <label class="sgi-label" for="emp-nav-opcenter">Centro de Operación</label>
                    <?= $this->Form->select('operation_center_id', $operationCenters, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $navOperationCenterId,
                        'id' => 'emp-nav-opcenter',
                        'onchange' => 'this.form.submit()',
                    ]) ?>
                </div>
            </div>
        </form>
```

- [ ] **Step 4: Validación manual — filtros activos**

Recargar `http://localhost:8765/employees/view/{id}`. Esperado:
- Aparece un botón "Filtros" debajo del search.
- Click en "Filtros" expande dos selects: Cargo y Centro de Operación.
- Seleccionar un Cargo: el form submite, la URL ahora tiene `?position_id=X&...`, y la lista lateral se filtra.
- El collapse permanece abierto al recargar porque `navAdvancedFiltersOpen=true` cuando hay filtro activo.
- El trigger muestra el badge "Filtros · 1" (o 2 si ambos activos).

- [ ] **Step 5: Validación manual — limpiar filtros**

Seleccionar "Todos" en ambos selects (uno por uno). Esperado: la URL pierde los parámetros, la lista vuelve a mostrar todos, y el badge del trigger desaparece.

- [ ] **Step 6: Commit**

```bash
git add templates/Employees/view.php
git -c commit.gpgsign=false commit -m "$(cat <<'EOF'
feat(employees): filtros Cargo/Centro de Operacion en panel lateral

Anade collapse de filtros al panel izquierdo de view.php con dos selects
(position_id, operation_center_id) que submiten automaticamente al
cambiar. El collapse se abre por defecto cuando hay filtros activos y
muestra un badge con el count.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Tarea 4 — Propagar query string completo en los links de la lista

**Files:**
- Modify: `templates/Employees/view.php` (sección lista, línea ~128; tabs de estado, función `$navTabUrl`)

- [ ] **Step 1: Actualizar la función `$navTabUrl` para usar `$navBaseQuery` completo**

En `templates/Employees/view.php`, localizar la definición actual de `$navTabUrl` (aproximadamente líneas 55-58):

```php
$navTabUrl = function (string $status) use ($employee, $navBaseQuery) {
    return ['action' => 'view', $employee->id, '?' => $navBaseQuery + ['status' => $status]];
};
```

No requiere cambio de código (ya usa `$navBaseQuery`, que la Tarea 3 enriqueció con `position_id` y `operation_center_id`). Verificar que la definición sigue tal cual: si está, dejar; si no, restaurarla a esta forma.

- [ ] **Step 2: Actualizar el `Url->build` de cada fila de la lista**

En `templates/Employees/view.php`, localizar el `<a class="sgi-md-row...">` del foreach (aproximadamente línea 127-128) que actualmente tiene:

```php
        <a class="sgi-md-row<?= $isSelected ? ' is-selected' : '' ?>"
           href="<?= $this->Url->build(['action' => 'view', $nav->id, '?' => array_filter(['search' => $navSearch, 'status' => $navStatus])]) ?>">
```

Reemplazar el `array_filter` por `$navBaseQuery + ['status' => $navStatus]` para que también propague `position_id` y `operation_center_id`:

```php
        <a class="sgi-md-row<?= $isSelected ? ' is-selected' : '' ?>"
           href="<?= $this->Url->build(['action' => 'view', $nav->id, '?' => $navBaseQuery + ['status' => $navStatus]]) ?>">
```

- [ ] **Step 3: Validación manual — click preserva filtros**

Recargar `http://localhost:8765/employees/view/{id}?position_id=X&search=lo`. Esperado:
- La lista lateral muestra solo empleados que matchean.
- Click en otro empleado de la lista: la URL nueva preserva `position_id` y `search`.
- El collapse de filtros sigue abierto y mostrando los selects con sus valores.

- [ ] **Step 4: Validación manual — cambio de tab preserva filtros**

Estando en `?position_id=X&search=lo&status=activo`, click en el tab "Retirados". Esperado: URL pasa a `?position_id=X&search=lo&status=retirado` y la lista se actualiza.

- [ ] **Step 5: Commit**

```bash
git add templates/Employees/view.php
git -c commit.gpgsign=false commit -m "$(cat <<'EOF'
feat(employees): propagar position_id/operation_center_id en links del panel

Los links de cada empleado de la lista lateral y los tabs de estado
ahora preservan el query string completo (search, status, position_id,
operation_center_id) para que el filtro persista al navegar.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Tarea 5 — Paginación del panel lateral + CSS compacto

**Files:**
- Modify: `templates/Employees/view.php` (final del bloque `sgi-md-left-list`)
- Modify: `webroot/css/styles.css` (añadir `.sgi-md-pagination`)

- [ ] **Step 1: Añadir paginación al final del bloque `sgi-md-left-list`**

En `templates/Employees/view.php`, localizar el cierre del `<?php endforeach; ?>` de la lista de empleados y el bloque `<?php if (count($navEmployees) === 0): ?>` (que ajustamos en Tarea 2). Justo después del `</div>` que cierra `sgi-md-left-list`, antes de `</aside>`, agregar:

```php
        <?php if (count($navEmployees) > 0): ?>
        <div class="sgi-md-pagination">
            <?= $this->Paginator->counter([
                'model' => 'nav',
                'format' => '{{start}}–{{end}} de {{count}}',
            ]) ?>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?= $this->Paginator->prev('‹', [
                        'model' => 'nav',
                        'templates' => [
                            'prevActive'   => '<li class="page-item"><a class="page-link" rel="prev" href="{{url}}">{{text}}</a></li>',
                            'prevDisabled' => '<li class="page-item disabled"><span class="page-link">{{text}}</span></li>',
                        ],
                    ]) ?>
                    <?= $this->Paginator->next('›', [
                        'model' => 'nav',
                        'templates' => [
                            'nextActive'   => '<li class="page-item"><a class="page-link" rel="next" href="{{url}}">{{text}}</a></li>',
                            'nextDisabled' => '<li class="page-item disabled"><span class="page-link">{{text}}</span></li>',
                        ],
                    ]) ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
```

Nota: se usa solo prev/next (no first/last/numbers) por el ancho reducido del panel lateral.

- [ ] **Step 2: Añadir CSS `.sgi-md-pagination` al final de `webroot/css/styles.css`**

Agregar al final del archivo (antes del cierre del file, sin tocar nada existente):

```css
/* ════════════════════════════════════════════════════════════════════════
   PAGINACION COMPACTA PARA EL PANEL LATERAL DEL MASTER-DETAIL (Empleados)
   ════════════════════════════════════════════════════════════════════════ */
.sgi-md-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-2) var(--space-3);
    border-top: 1px solid var(--rule);
    background: var(--bg-subtle);
    font-size: var(--fs-meta);
    color: var(--text-faint);
}
.sgi-md-pagination .pagination {
    --bs-pagination-padding-x: .4rem;
    --bs-pagination-padding-y: .15rem;
    --bs-pagination-font-size: var(--fs-meta);
    --bs-pagination-border-radius: var(--radius-sm);
}
```

- [ ] **Step 3: Validación manual — paginación funcional**

En un entorno con más de 15 empleados activos, abrir `http://localhost:8765/employees`. Esperado:
- El panel lateral muestra 15 empleados máximo.
- Al final aparece la franja de paginación: "1–15 de N" + botones ‹ ›.
- Click en `›`: la URL pasa a `?page=2` (con scope `nav`, será `?nav[page]=2` en CakePHP) y la lista avanza.
- El empleado seleccionado en el panel derecho permanece igual aunque ya no esté visible en la lista (esto es deliberado, ver spec § Riesgos).

- [ ] **Step 4: Validación manual — paginación + filtros**

Aplicar un filtro de Cargo, paginar. Esperado: la URL combina filtros y `nav[page]=2`; al volver a `page=1` los filtros siguen activos.

- [ ] **Step 5: Verificar code style**

```bash
composer cs-check
```

- [ ] **Step 6: Commit**

```bash
git add templates/Employees/view.php webroot/css/styles.css
git -c commit.gpgsign=false commit -m "$(cat <<'EOF'
feat(employees): paginacion 15/pag en el panel lateral de view

Anade paginacion compacta (prev/next + counter) al final del panel
izquierdo del master-detail. Nuevo selector .sgi-md-pagination en
styles.css para integrar visualmente con el panel.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Tarea 6 — Botones Excel en el header del panel izquierdo + modales

**Files:**
- Modify: `templates/Employees/view.php` (header del panel izquierdo + agregar modal al final)

- [ ] **Step 1: Agregar botones Excel al header del panel izquierdo**

En `templates/Employees/view.php`, localizar el bloque del header del panel izquierdo (aproximadamente líneas 69-82) que contiene el `<div class="d-flex justify-content-between align-items-center mb-2">` con el botón "Nuevo".

Sustituir ese bloque por:

```php
        <div class="d-flex justify-content-between align-items-start mb-2 gap-2">
            <div>
                <div class="sgi-title-card">Empleados</div>
                <div class="sgi-body-faint mt-1">
                    <?= $this->Paginator->counter([
                        'model' => 'nav',
                        'format' => '{{count}} mostrados',
                    ]) ?>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-1 justify-content-end">
                <?= $this->element('excel_wizard/buttons', [
                    'module' => 'Employees',
                    'importable' => true,
                    'canCreate' => !empty($userPermissions['employees']['can_create']),
                ]) ?>
                <?php if (!empty($userPermissions['employees']['can_create'])): ?>
                <?= $this->Html->link(
                    '<i class="bi bi-plus-lg" aria-hidden="true"></i>Nuevo',
                    ['action' => 'add'],
                    ['class' => 'btn btn-primary btn-sm', 'escape' => false]
                ) ?>
                <?php endif; ?>
            </div>
        </div>
```

Cambios respecto al original:
- `align-items-center` → `align-items-start` (los botones pueden envolverse).
- `mb-2 gap-2` para separación horizontal cuando se ajustan.
- El contador "X mostrados" ahora usa `Paginator->counter` con scope `nav` en vez de `count($navEmployees)` (que solo cuenta la página actual).
- Se añade un wrapper con `flex-wrap gap-1` que contiene los botones Excel + Nuevo, así si el espacio aprieta los botones envuelven a 2 filas en vez de salirse.

- [ ] **Step 2: Agregar modal Excel al final del template**

En `templates/Employees/view.php`, al final del archivo (después del último `</div>` o modal existente), agregar:

```php
<?= $this->element('excel_wizard/modals', [
    'module' => 'Employees',
    'entityName' => 'Empleados',
    'downloadSlug' => 'empleados',
    'importable' => true,
]) ?>
```

Si el archivo ya tiene otros modales al final (como `#newFolderModal`), insertar este bloque después de todos ellos sin tocar los existentes.

- [ ] **Step 3: Validación manual — botones aparecen**

Recargar `http://localhost:8765/employees/view/{id}`. Esperado: en el header del panel izquierdo aparecen los botones "Exportar" y "Importar" junto a "Nuevo". Si el ancho del panel es ajustado, los botones envuelven a 2 filas sin desbordar.

- [ ] **Step 4: Validación manual — exportar**

Click en "Exportar". Esperado: se abre el modal del wizard de exportación. Cerrar el modal sin disparar nada.

- [ ] **Step 5: Validación manual — importar**

Click en "Importar" (solo si el usuario tiene `can_create` en `employees`). Esperado: se abre el modal de importación. Cerrar sin disparar.

- [ ] **Step 6: Validación manual — usuario sin permiso `can_create`**

Loguearse como un rol sin `employees.can_create` (por ejemplo, un rol custom de solo lectura). Esperado: el botón "Importar" no aparece; "Exportar" sí.

- [ ] **Step 7: Verificar code style**

```bash
composer cs-check
```

- [ ] **Step 8: Commit**

```bash
git add templates/Employees/view.php
git -c commit.gpgsign=false commit -m "$(cat <<'EOF'
feat(employees): mover botones Excel al header del panel lateral

Los botones Exportar/Importar Excel que vivian en el listado eliminado
ahora viven en el header del panel izquierdo de view, junto al boton
Nuevo. El contador 'X mostrados' usa Paginator->counter con scope nav
para reflejar el total real, no solo la pagina actual.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Tarea 7 — Validación manual integral

Esta tarea no escribe código; ejercita el flujo completo para detectar regresiones.

- [ ] **Step 1: Levantar servidor y hacer hard refresh**

```bash
php bin/cake server
```

En el navegador, Ctrl+F5 sobre `http://localhost:8765/employees`.

- [ ] **Step 2: Validación — autoselect sin filtros**

`/employees` → debe redirigir a `/employees/view/{id}` con el primer empleado alfabético. El panel lateral muestra hasta 15 empleados con paginación. El primero está marcado como `is-selected`.

- [ ] **Step 3: Validación — autoselect con filtros desde sidebar**

Forzar URL `http://localhost:8765/employees?status=retirado`. Esperado: redirige a `/employees/view/{first-retirado-id}?status=retirado`. El panel lateral filtra retirados, el tab "Retirados" está activo.

- [ ] **Step 4: Validación — search y filtros conviven**

En `view/{id}`, escribir algo en el search y submitir. La URL incluye `search=...&status=...`. Aplicar también un filtro de Cargo. La URL ahora tiene los tres parámetros, y la lista refleja la intersección.

- [ ] **Step 5: Validación — paginación + selección persistente**

Con suficientes empleados, paginar la lista lateral (`›`). El empleado del panel derecho NO cambia. La URL pasa a `nav[page]=2`.

- [ ] **Step 6: Validación — click en otro empleado preserva filtros**

Con `?position_id=X&status=activo` aplicados, click en otro empleado de la lista. La URL nueva: `/employees/view/{nuevo-id}?position_id=X&status=activo&nav[page]=N`. El collapse de filtros sigue abierto.

- [ ] **Step 7: Validación — empty-state filtros sin matches**

`http://localhost:8765/employees?search=xxxxxxxxxxxx`. Esperado:
- NO redirige.
- Panel izquierdo: search con el valor "xxxxxxxxxxxx", tabs, mensaje "Sin resultados".
- Panel derecho: icono de gente + título "Sin empleados que coincidan con los filtros" + botón "Crear empleado".

- [ ] **Step 8: Validación — empty-state BD vacía (opcional, solo si se tiene un entorno de prueba con BD limpia)**

En una BD sin un solo empleado, `/employees` debe mostrar empty-state con título "Aún no hay empleados registrados". Si no tienes BD limpia accesible, saltar este paso.

- [ ] **Step 9: Validación — botón Editar del panel derecho**

Click en el botón "Editar" del header del panel derecho. Esperado: abre `/employees/edit/{id}` con el formulario clásico (sin lista lateral, sin cambios). Volver a `/employees/view/{id}` con el botón "Volver".

- [ ] **Step 10: Validación — borrar empleado seleccionado**

(Con BD de prueba, no en producción.) Borrar el empleado seleccionado. Esperado: redirect a `/employees`, que autoselecciona el siguiente disponible. Si era el último, muestra empty-state "Aún no hay empleados registrados".

- [ ] **Step 11: Validación — Excel wizard funcional**

- Click en "Exportar" → modal abre → seleccionar el flujo de exportación → descargar archivo Excel correcto.
- Click en "Importar" → modal abre → no completar el flujo (a menos que tengas un archivo de prueba).

- [ ] **Step 12: Validación — bookmark legacy con `page`**

Forzar URL `http://localhost:8765/employees?status=activo&page=3`. Esperado: redirige al primer activo. El parámetro `page` se ignora silenciosamente. La URL final será `/employees/view/{id}?status=activo&page=3` (porque `index()` pasa todo el query string), pero `view()` ignora `page` global (solo usa `nav[page]`). Aceptable.

- [ ] **Step 13: Validación — sin permisos**

Loguearse como un rol sin `employees.can_view`. Esperado: acceso a `/employees` debe ser rechazado por `AuthorizationService` antes de llegar a `index()`.

- [ ] **Step 14: Validación — responsive del master-detail**

Reducir el ancho del navegador a ~700px. Esperado: el panel lateral colapsa según el comportamiento existente de `sgi-master-detail` (no se rompe layout, los nuevos elementos añadidos — filtros collapse y botones Excel — se acomodan sin desbordar).

- [ ] **Step 15: Verificar code style global y limpieza**

```bash
composer cs-check
```

Si todo pasa, no hay nada que commitear en esta tarea (es solo validación). Si en alguna validación se detectó una regresión: volver atrás, hacer fix, recomenzar la validación.

---

## Resumen de commits esperados

1. `refactor(employees): convertir index en redirect-to-first con empty-state`
2. `refactor(employees): paginar lista lateral y exponer catalogos a view`
3. `feat(employees): filtros Cargo/Centro de Operacion en panel lateral`
4. `feat(employees): propagar position_id/operation_center_id en links del panel`
5. `feat(employees): paginacion 15/pag en el panel lateral de view`
6. `feat(employees): mover botones Excel al header del panel lateral`

---

## Cobertura del spec

| Requisito del spec | Tarea(s) |
|---|---|
| `index()` se reescribe como redirect-to-first / empty-state | Tarea 1 |
| Template `index.php` reemplazado por empty-state | Tarea 1 |
| `view()` pagina `navEmployees` con scope `nav` | Tarea 2 |
| `view()` expone `positions`, `operationCenters` al template | Tarea 2 |
| Header del panel izquierdo con botones Excel | Tarea 6 |
| Collapse de filtros Cargo / Centro de Operación | Tarea 3 |
| Trigger del collapse con badge de count | Tarea 3 |
| Links de la lista propagan `position_id` y `operation_center_id` | Tarea 4 |
| Tabs de estado propagan filtros completos | Tarea 4 (vía `$navBaseQuery`) |
| Paginación del panel lateral | Tarea 5 |
| Modal Excel al final del template | Tarea 6 |
| CSS `.sgi-md-pagination` | Tarea 5 |
| Sincronización de `orderBy` entre `index()` y `view()` | Tareas 1 y 2 (con comentarios inline) |
| Permisos no se tocan | (verificado en Tarea 7, paso 13) |
| Sidebar no requiere cambio | (verificado en Tarea 7, paso 2) |
| `delete()` mantiene `redirect(['action' => 'index'])` literal | (sin cambios — verificado en Tarea 7, paso 10) |

Sin gaps identificados.

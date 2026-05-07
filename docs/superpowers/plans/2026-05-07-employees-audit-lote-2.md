# Lote 2 Auditoría Employees · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cerrar los 2 hallazgos Major restantes de la categoría Bugs (CR-007, CR-009) en el módulo Employees.

**Architecture:** Refactorizar el getter virtual `Employee::current_novelty` para que filtre en memoria (independiente del orden del finder), eliminar la query duplicada en `EmployeesController::view()`, aplicar `status='activo'` por defecto en `index()` con bypass `?status=all`, y agregar índices compuestos en `employee_novelties`.

**Tech Stack:** PHP 8.2+, CakePHP 5.3, MySQL/MariaDB, Phinx migrations (`Migrations\BaseMigration`).

**Spec origen:** `docs/superpowers/specs/2026-05-07-employees-audit-lote-2-design.md`

**Auditoría origen:** `docs/audits/employees-module-audit-2026-05-07.md` (CR-007, CR-009)

**Política de testing del proyecto:** Sin tests automatizados (ver `CLAUDE.md` → "Testing Policy"). Cada Task termina con validación manual ejecutando `php bin/cake server`.

---

## Estructura de archivos

| Archivo | Acción | Responsabilidad |
|---------|--------|-----------------|
| `src/Model/Entity/Employee.php` | Modify | Getter virtual `current_novelty` con filtro en memoria |
| `src/Controller/EmployeesController.php` | Modify | `view()` deja de duplicar la query de novedad activa |
| `src/Service/EmployeeFilterService.php` | Modify | Default `status='activo'` con bypass `'all'` |
| `templates/Employees/index.php` | Modify | Dropdown de status con 3 opciones explícitas |
| `config/Migrations/<timestamp>_AddIndexesToEmployeeNovelties.php` | Create | Índices compuestos en `employee_novelties` |

---

## Task 1: Refactor `Employee::_getCurrentNovelty` para filtrar en memoria (CR-009)

**Files:**
- Modify: `src/Model/Entity/Employee.php` (líneas 49-57)

- [ ] **Step 1: Agregar `use` de `App\Constants\NoveltyConstants`**

Edit `src/Model/Entity/Employee.php`. En el bloque de `use` al inicio, después de `use Cake\ORM\Entity;`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\NoveltyConstants;
use Cake\Chronos\Chronos;
use Cake\Chronos\ChronosDate;
use Cake\ORM\Entity;
```

- [ ] **Step 2: Reescribir `_getCurrentNovelty` para filtrar en memoria**

Reemplazar el método completo (líneas 49-57 del archivo actual):

```php
    /**
     * Get the first active novelty for today, filtering in memory.
     *
     * Independiente del orden y filtrado del finder. Funciona tanto si
     * employee_novelties fue cargado por findWithCurrentNovelty (1 fila ya
     * filtrada) como por contain plano (historial completo).
     */
    protected function _getCurrentNovelty(): ?EmployeeNovelty
    {
        $novelties = $this->employee_novelties ?? [];
        if ($novelties === []) {
            return null;
        }

        $today = date('Y-m-d');

        foreach ($novelties as $novelty) {
            if (!$this->_isNoveltyActiveOn($novelty, $today)) {
                continue;
            }

            return $novelty;
        }

        return null;
    }

    private function _isNoveltyActiveOn(EmployeeNovelty $novelty, string $today): bool
    {
        if ($novelty->pipeline_status === NoveltyConstants::STATUS_RECHAZADA) {
            return false;
        }

        $start = $novelty->start_date !== null ? (string)$novelty->start_date : null;
        $end = $novelty->end_date !== null ? (string)$novelty->end_date : null;
        $permission = $novelty->permission_date !== null ? (string)$novelty->permission_date : null;

        // Single-day permission: permission_date == today AND no range
        if ($start === null && $permission === $today) {
            return true;
        }

        // Multi-day range: today within [start_date, end_date]
        if ($start !== null && $end !== null && $start <= $today && $today <= $end) {
            return true;
        }

        return false;
    }
```

- [ ] **Step 3: Validación manual — empleado con novedad activa multi-día**

Ejecutar:
```bash
php bin/cake server
```

Abrir el navegador en `/employees/view/{id}` de un empleado que tenga una novedad con `start_date <= HOY <= end_date` y `pipeline_status != 'Rechazada'`. La sección "Novedad actual" en `view.php` debe seguir mostrándose con esa novedad.

(En esta task aún la query duplicada de `view()` está activa — se elimina en Task 2; aquí solo validamos que el getter sigue funcionando.)

- [ ] **Step 4: Validación manual — novedad rechazada en rango no aparece**

En la BD remota, marcar manualmente `pipeline_status = 'Rechazada'` en una novedad cuyo rango cubra hoy. Recargar `view` del empleado.

Expected: la novedad NO debe aparecer como "novedad actual" (la sección no renderiza o renderiza la siguiente activa). Revertir el cambio en BD al finalizar.

- [ ] **Step 5: Validación manual — novedad de un solo día (permission_date)**

Crear una novedad con `permission_date = HOY`, `start_date = NULL`, `end_date = NULL`, `pipeline_status != 'Rechazada'`. Recargar `view`.

Expected: aparece como "novedad actual".

- [ ] **Step 6: Commit**

```bash
git add src/Model/Entity/Employee.php
git commit -m "fix(employees): _getCurrentNovelty filtra en memoria (CR-009)

Independiente del orden y filtrado del finder. Funciona tanto con
findWithCurrentNovelty (1 fila ya filtrada) como con contain plano
(historial completo en view).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Eliminar query duplicada en `EmployeesController::view()` (CR-007 a)

**Files:**
- Modify: `src/Controller/EmployeesController.php` (líneas 58-113)

**Pre-condición:** Task 1 completada (el getter ya filtra en memoria correctamente).

- [ ] **Step 1: Eliminar el bloque `currentNovelty` y leer del entity**

Edit `src/Controller/EmployeesController.php`. Reemplazar el método `view()` completo (líneas 58-113):

```php
    public function view($id = null)
    {
        $employee = $this->Employees->get($id, contain: [
            'MaritalStatuses',
            'EducationLevels',
            'Positions',
            'SupervisorPositions',
            'OperationCenters',
            'CostCenters',
            'TemporaryOrganizations',
            'EmployeeNovelties' => [
                'sort' => ['EmployeeNovelties.created' => 'DESC'],
                'NoveltyTypes',
                'RegisteredByUsers',
            ],
            'EmployeeFolders' => [
                'sort' => ['EmployeeFolders.name' => 'ASC'],
                'EmployeeDocuments' => [
                    'sort' => ['EmployeeDocuments.name' => 'ASC'],
                    'UploadedByUsers',
                ],
            ],
            'EmployeeObservations' => [
                'sort' => ['EmployeeObservations.created' => 'ASC'],
                'Users',
            ],
            'EmployeeHistories' => [
                'sort' => ['EmployeeHistories.created' => 'DESC'],
                'Users',
            ],
        ]);

        $folders = $this->Employees->EmployeeFolders->find()
            ->where(['employee_id' => $id, 'parent_id IS' => null])
            ->contain(['EmployeeDocuments' => ['UploadedByUsers'], 'ChildFolders' => ['EmployeeDocuments' => ['UploadedByUsers']]])
            ->order(['EmployeeFolders.name' => 'ASC'])
            ->all();

        // Novedad activa hoy: el getter virtual current_novelty filtra en memoria
        // sobre employee_novelties (CR-007 / CR-009).
        $currentNovelty = $employee->current_novelty;

        $this->set(compact('employee', 'folders', 'currentNovelty'));
        $this->set('fieldLabels', EmployeeHistoryService::FIELD_LABELS);
    }
```

- [ ] **Step 2: Verificar que el `use` de `NoveltyConstants` ya no es necesario**

`NoveltyConstants` se usaba solo dentro de la query eliminada. Revisar el archivo:

```bash
grep -n "NoveltyConstants" src/Controller/EmployeesController.php
```

Expected: **sin coincidencias**. Si quedan, eliminar el `use App\Constants\NoveltyConstants;` de las líneas 5-17 del controller.

Edit `src/Controller/EmployeesController.php` para remover esa línea de imports si quedó huérfana.

- [ ] **Step 3: Validación manual — view sigue funcionando**

```bash
php bin/cake server
```

Abrir `/employees/view/{id}` de:
- Un empleado con novedad activa hoy → la sección "Novedad actual" se ve igual que antes.
- Un empleado sin novedades → no aparece sección de novedad actual.
- Un empleado con novedades pasadas (vencidas) → no aparece sección de novedad actual.

- [ ] **Step 4: Validación manual — historial completo sigue presente**

En el mismo `view`, verificar que la sección de **historial de novedades** (la que usa el contain plano `EmployeeNovelties` con `created DESC`) muestra TODAS las novedades del empleado, incluyendo las vencidas, no solo la activa.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/EmployeesController.php
git commit -m "perf(employees): elimina query duplicada de novedad actual en view (CR-007)

La query separada que recalculaba la novedad activa de hoy duplicaba la
logica del finder. Ahora se lee del getter virtual Employee::current_novelty
que filtra en memoria sobre el contain existente. -1 query por carga.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Default `status='activo'` en `EmployeeFilterService` (CR-007 b backend)

**Files:**
- Modify: `src/Service/EmployeeFilterService.php`

- [ ] **Step 1: Importar `EmployeeStatusConstants` y refactor del filtro de status**

Edit `src/Service/EmployeeFilterService.php`. Reemplazar el archivo completo:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\EmployeeStatusConstants;
use Cake\ORM\Query\SelectQuery;

class EmployeeFilterService
{
    /**
     * Apply search and filter parameters to an employees query.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Base query (already contains associations).
     * @param array<string,mixed> $params Query-string parameters.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function apply(SelectQuery $query, array $params): SelectQuery
    {
        $this->applySearch($query, $params['search'] ?? null);
        $this->applyExact($query, 'Employees.position_id', $params['position_id'] ?? null);
        $this->applyExact($query, 'Employees.operation_center_id', $params['operation_center_id'] ?? null);
        $this->applyEmployeeStatus($query, $params['status'] ?? null);

        return $query;
    }

    private function applySearch(SelectQuery $query, mixed $search): void
    {
        if ($search === null || $search === '') {
            return;
        }

        $like = '%' . $search . '%';
        $query->where([
            'OR' => [
                'Employees.first_name LIKE' => $like,
                'Employees.last_name1 LIKE' => $like,
                'Employees.last_name2 LIKE' => $like,
                'Employees.document_number LIKE' => $like,
                'Employees.email LIKE' => $like,
            ],
        ]);
    }

    private function applyExact(SelectQuery $query, string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $query->where([$field => $value]);
    }

    /**
     * Aplica filtro de status con default 'activo' (CR-007).
     *
     * - Sin parametro o vacio  -> filtra por 'activo'
     * - 'all'                  -> sin filtro (bypass explicito)
     * - cualquier otro         -> filtra literal (ej: 'retirado')
     */
    private function applyEmployeeStatus(SelectQuery $query, mixed $status): void
    {
        if ($status === 'all') {
            return;
        }

        $effective = (is_string($status) && $status !== '')
            ? $status
            : EmployeeStatusConstants::ACTIVO;

        $query->where(['Employees.status' => $effective]);
    }
}
```

- [ ] **Step 2: Validación manual — sin query string filtra activos**

```bash
php bin/cake server
```

Abrir `/employees` (sin query string).

Expected: solo empleados con `status='activo'`. Los retirados no aparecen.

(El dropdown todavía mostrará "Todos" hasta Task 4 — eso es esperado en este punto. La consistencia visual se completa en Task 4.)

- [ ] **Step 3: Validación manual — bypass `?status=all`**

Abrir `/employees?status=all`.

Expected: aparecen activos y retirados mezclados.

- [ ] **Step 4: Validación manual — filtro literal funciona**

Abrir `/employees?status=retirado` y luego `/employees?status=activo`.

Expected: en cada caso, solo el subconjunto correspondiente.

- [ ] **Step 5: Commit**

```bash
git add src/Service/EmployeeFilterService.php
git commit -m "feat(employees): default status=activo en index con bypass ?status=all (CR-007)

Sin query string trae solo activos. ?status=all desactiva el filtro.
?status=activo|retirado filtra literal. Resuelve la mezcla de retirados
con activos en la pantalla principal.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Dropdown explícito en `index.php` (CR-007 b frontend)

**Files:**
- Modify: `templates/Employees/index.php` (líneas 77-84 aprox.)

**Pre-condición:** Task 3 completada (el backend ya entiende `'all'`).

- [ ] **Step 1: Reemplazar el dropdown de status**

Edit `templates/Employees/index.php`. Localizar el bloque del filtro de status (líneas 77-84) y reemplazarlo:

```php
                <div class="col-md-4">
                    <label class="sgi-filter-label">Estado</label>
                    <?= $this->Form->select('status', [
                        \App\Constants\EmployeeStatusConstants::ACTIVO   => 'Activo',
                        \App\Constants\EmployeeStatusConstants::RETIRADO => 'Retirado',
                        'all'                                            => 'Todos',
                    ], [
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('status') ?: \App\Constants\EmployeeStatusConstants::ACTIVO,
                    ]) ?>
                </div>
```

Diferencias clave vs el original:
- Sin `'empty' => 'Todos'`. Las 3 opciones son explícitas.
- "Todos" mapea a `'all'` (no a string vacío).
- Default value cuando no hay query string: `EmployeeStatusConstants::ACTIVO`.

- [ ] **Step 2: Validación manual — carga inicial muestra "Activo" seleccionado**

```bash
php bin/cake server
```

Abrir `/employees`.

Expected:
- El dropdown muestra "Activo" seleccionado.
- Solo aparecen empleados activos.
- La URL final tras un submit con "Activo" será `/employees?...&status=activo`.

- [ ] **Step 3: Validación manual — cambiar a "Todos"**

En el dropdown, seleccionar "Todos" y submit.

Expected:
- URL contiene `?status=all`.
- Aparecen activos y retirados mezclados.
- Al recargar, "Todos" sigue seleccionado.

- [ ] **Step 4: Validación manual — cambiar a "Retirado"**

Seleccionar "Retirado" y submit.

Expected:
- URL contiene `?status=retirado`.
- Solo retirados.
- Al recargar, "Retirado" sigue seleccionado.

- [ ] **Step 5: Validación manual — combinación con otros filtros**

Aplicar simultáneamente: search="garcia" (o un apellido conocido) + status="Todos".

Expected: la búsqueda actúa sobre todos los estados.

- [ ] **Step 6: Commit**

```bash
git add templates/Employees/index.php
git commit -m "ui(employees): dropdown de status con 3 opciones explicitas (CR-007)

Activo / Retirado / Todos (este ultimo mapea a ?status=all). Default
seleccionado cuando no hay query string es Activo, alineado con el
default del filter service.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Migración de índices en `employee_novelties` (CR-007 c)

**Files:**
- Create: `config/Migrations/<timestamp>_AddIndexesToEmployeeNovelties.php`

- [ ] **Step 1: Generar el archivo de migración**

Ejecutar:

```bash
php bin/cake migrations create AddIndexesToEmployeeNovelties
```

Expected: el comando crea un archivo `config/Migrations/<timestamp>_AddIndexesToEmployeeNovelties.php` con un esqueleto de `BaseMigration`. Anotar el path exacto (incluyendo el timestamp generado) para los siguientes pasos.

- [ ] **Step 2: Reemplazar el contenido de la migración**

Edit el archivo recién creado y reemplazar su contenido completo:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddIndexesToEmployeeNovelties extends BaseMigration
{
    /**
     * Indices compuestos para soportar la query de novedad activa hoy:
     *
     * SELECT ... FROM employee_novelties WHERE
     *   pipeline_status != 'Rechazada' AND (
     *     (permission_date = TODAY AND start_date IS NULL)
     *     OR (start_date <= TODAY AND end_date >= TODAY)
     *   )
     *
     * - idx_novelty_pipeline_dates cubre el rango multi-dia.
     * - idx_novelty_permission_date cubre el caso single-day.
     */
    public function up(): void
    {
        $table = $this->table('employee_novelties');

        if (!$table->hasIndexByName('idx_novelty_pipeline_dates')) {
            $table
                ->addIndex(
                    ['pipeline_status', 'start_date', 'end_date'],
                    ['name' => 'idx_novelty_pipeline_dates'],
                )
                ->update();
        }

        if (!$table->hasIndexByName('idx_novelty_permission_date')) {
            $table
                ->addIndex(
                    ['permission_date'],
                    ['name' => 'idx_novelty_permission_date'],
                )
                ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('employee_novelties');

        if ($table->hasIndexByName('idx_novelty_pipeline_dates')) {
            $table->removeIndexByName('idx_novelty_pipeline_dates')->update();
        }

        if ($table->hasIndexByName('idx_novelty_permission_date')) {
            $table->removeIndexByName('idx_novelty_permission_date')->update();
        }
    }
}
```

Notas:
- Hereda de `Migrations\BaseMigration` (NO `AbstractMigration`) — convención del proyecto.
- `hasIndexByName` antes de crear/borrar la hace idempotente.

- [ ] **Step 3: Aplicar la migración**

```bash
php bin/cake migrations migrate
```

Expected: log de Phinx mostrando `AddIndexesToEmployeeNovelties` aplicada exitosamente. Sin errores.

- [ ] **Step 4: Verificar que la migración es idempotente**

Re-ejecutar `migrate`:

```bash
php bin/cake migrations migrate
```

Expected: ningún cambio (la migración ya está marcada como aplicada en `phinxlog`). Si por algún motivo se forzara re-ejecución, los `hasIndexByName` evitan duplicados.

- [ ] **Step 5: Verificar índices creados en BD**

Conectarse a la BD remota (mysql/cli o cliente equivalente) y ejecutar:

```sql
SHOW INDEX FROM employee_novelties WHERE Key_name IN ('idx_novelty_pipeline_dates', 'idx_novelty_permission_date');
```

Expected:
- `idx_novelty_pipeline_dates` con 3 columnas (`pipeline_status`, `start_date`, `end_date`).
- `idx_novelty_permission_date` con 1 columna (`permission_date`).

- [ ] **Step 6: Probar rollback (opcional pero recomendado)**

```bash
php bin/cake migrations rollback
```

Expected: los dos índices se eliminan. Re-ejecutar `SHOW INDEX` para confirmar.

Volver a aplicar:

```bash
php bin/cake migrations migrate
```

Expected: índices reaparecen.

- [ ] **Step 7: Validación manual — index y view siguen funcionando con índices**

```bash
php bin/cake server
```

- Abrir `/employees` → carga sin errores.
- Abrir `/employees/view/{id}` de empleado con novedad activa → la sección "Novedad actual" se renderiza correctamente.

(El comportamiento funcional no debe cambiar; los índices solo afectan el plan de ejecución de la BD.)

- [ ] **Step 8: (Opcional) Comparar EXPLAIN antes/después**

En la BD remota:

```sql
EXPLAIN SELECT * FROM employee_novelties
WHERE pipeline_status != 'Rechazada'
AND (
    (permission_date = '2026-05-07' AND start_date IS NULL)
    OR (start_date <= '2026-05-07' AND end_date >= '2026-05-07')
);
```

Anotar `type` y `rows`. Comparar con un EXPLAIN previo si se capturó antes de la migración (no bloqueante — solo informativo).

- [ ] **Step 9: Commit**

```bash
git add config/Migrations/
git commit -m "feat(db): indices en employee_novelties para query de novedad activa (CR-007)

idx_novelty_pipeline_dates (pipeline_status, start_date, end_date) cubre
el caso de rango multi-dia. idx_novelty_permission_date cubre el caso
single-day. La migracion es idempotente con hasIndexByName.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Cierre — actualizar tabla de remediación de la auditoría

**Files:**
- Modify: `docs/audits/employees-module-audit-2026-05-07.md`

- [ ] **Step 1: Marcar CR-007 y CR-009 como resueltos**

Edit `docs/audits/employees-module-audit-2026-05-07.md`. En la tabla "Estado de remediación", actualizar:

- Fila CR-007: cambiar `⏳ Pendiente | —` por `✅ Resuelto | Lote 2 (2026-05-07) — getter en memoria + query duplicada eliminada en view + default activo en index + migración de índices`.
- Fila CR-009: cambiar `⏳ Pendiente | —` por `✅ Resuelto | Lote 2 (2026-05-07) — _getCurrentNovelty filtra en memoria, desacoplado del orden del finder`.

(Mantener el resto de la tabla intacto.)

- [ ] **Step 2: Commit**

```bash
git add docs/audits/employees-module-audit-2026-05-07.md
git commit -m "docs(audits): mark CR-007 and CR-009 as resolved (Lote 2)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Validación end-to-end (post-merge)

Una sola pasada completa al final, antes de declarar el lote cerrado:

1. `php bin/cake migrations migrate` — sin errores.
2. `php bin/cake server` — levanta limpio.
3. `/employees` (sin query) → solo activos, dropdown muestra "Activo".
4. `/employees?status=all` → activos + retirados.
5. `/employees?status=retirado` → solo retirados.
6. `/employees/view/{id}` con novedad activa hoy → "Novedad actual" se ve, historial completo también.
7. `/employees/view/{id}` con novedad rechazada en rango → no aparece como current.
8. `/employees/view/{id}` sin novedades → no aparece sección current.
9. `composer cs-check` — sin warnings nuevos.

---

## Self-review

**Spec coverage:**
- CR-007 a (query duplicada en view) → Task 2. ✓
- CR-007 b (default activo en index) → Tasks 3 + 4. ✓
- CR-007 c (índices) → Task 5. ✓
- CR-009 (getter en memoria) → Task 1. ✓
- Cierre de auditoría → Task 6. ✓

**Placeholders:** revisado. Todos los pasos contienen el código exacto, los comandos exactos y los expected outputs. El único placeholder textual es `<timestamp>` en el nombre del archivo de migración generado por `bin/cake migrations create` — eso lo genera la herramienta, está documentado en Step 1 de Task 5.

**Type consistency:**
- `Employee::_getCurrentNovelty` retorna `?EmployeeNovelty` en Task 1 → consumido por `$employee->current_novelty` en Task 2. ✓
- `EmployeeStatusConstants::ACTIVO` es `'activo'` (verificado en `src/Constants/EmployeeStatusConstants.php`) → consistente entre Tasks 3 y 4. ✓
- `'all'` (string) es el bypass acordado entre Task 3 (backend) y Task 4 (template). ✓
- `NoveltyConstants::STATUS_RECHAZADA` se usa en Task 1 (mismo símbolo que el finder original `findWithCurrentNovelty`). ✓

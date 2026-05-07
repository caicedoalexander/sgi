# Spec — Auditoría Employees · Lote 2 (Bugs)

**Fecha:** 2026-05-07
**Auditoría origen:** `docs/audits/employees-module-audit-2026-05-07.md`
**Hallazgos cubiertos:** CR-007, CR-009
**Severidad:** 🟠 Major
**Tipo de PR:** Bug fixes + migración de índices

---

## Contexto

Lote 1 (Seguridad) ya está en `main`. Lote 2 cierra los 2 hallazgos Major restantes de la categoría Bugs:

- **CR-007** — `EmployeesController::view()` ejecuta una query separada para obtener la novedad activa de hoy, duplicando la lógica de `EmployeesTable::findWithCurrentNovelty`. `index()` no filtra por `status='activo'` por defecto, mostrando empleados retirados mezclados. Se sospechan índices faltantes en `employee_novelties`.
- **CR-009** — `Employee::_getCurrentNovelty()` retorna `$this->employee_novelties[0]` y depende de que el caller haya usado el finder filtrado y limitado a 1. Bajo un contain plano (que es lo que hace `view()` para mostrar el historial completo), el getter devuelve la novedad más reciente — no necesariamente la activa hoy.

Ambos hallazgos comparten el dominio "novedad activa de hoy" y se resuelven en una sola refactor coherente.

---

## Objetivos

1. Eliminar la query duplicada en `view()` reusando un único getter virtual.
2. Hacer que `_getCurrentNovelty()` sea correcto bajo cualquier contain (filtrado o plano), filtrando en memoria.
3. Aplicar `status='activo'` por defecto en `index()` con bypass explícito vía `?status=all`.
4. Crear índices compuestos en `employee_novelties` para soportar la query de novedad activa.

---

## Diseño

### 1. `Employee::_getCurrentNovelty()` filtra en memoria

Reescribir el getter virtual para:

- Retornar `null` si `employee_novelties` no está cargado en el entity.
- Iterar el array y aplicar la misma condición que `findWithCurrentNovelty`:
  - `pipeline_status !== NoveltyConstants::STATUS_RECHAZADA`, **y**
  - (`permission_date == today` y `start_date IS null`) **o** (`start_date <= today <= end_date`).
- Retornar la primera novedad que cumple la condición, o `null`.

**Comparación de fechas:** mantener la convención actual (`date('Y-m-d')` como string ISO). Las columnas `Date`/`Datetime` que CakePHP expone en el entity tienen `__toString` ISO; la comparación `<=` y `>=` con strings ISO es lexicográficamente válida para fechas válidas.

**Razón del cambio:** desacoplar el getter del orden y filtrado del finder. Ahora funciona consistentemente:
- En `index()` con `findWithCurrentNovelty` → el array contiene 0-1 elementos ya filtrados, el getter devuelve el primero o null.
- En `view()` con contain plano del historial completo → el getter filtra el array en PHP y devuelve la novedad activa.

**Nota sobre `created DESC`:** `findWithCurrentNovelty` ordena por `created DESC` y limita a 1; el contain en `view()` también ordena por `created DESC`. El primer elemento del array que pase el filtro será efectivamente "la más reciente que está activa hoy", que es el contrato actual.

---

### 2. `EmployeesController::view()` elimina query duplicada

Cambios:

- Eliminar el bloque `currentNovelty` (líneas 96-109 del archivo actual).
- Reemplazar `compact('employee', 'folders', 'currentNovelty')` por una expresión que lea del entity:

  ```php
  $currentNovelty = $employee->current_novelty;
  $this->set(compact('employee', 'folders', 'currentNovelty'));
  ```

- El contain de `EmployeeNovelties` (con `'sort' => ['EmployeeNovelties.created' => 'DESC']`) se mantiene tal cual: provee el historial completo para la sección de novedades del template.
- El template `view.php` no requiere cambios — sigue leyendo `$currentNovelty`.

**Resultado:** -1 query por carga de `view()`.

---

### 3. Default `status='activo'` en `index()`

#### `EmployeeFilterService::apply`

Separar el status del helper genérico `applyExact` para distinguir tres casos:

- `params['status']` ausente o vacío → aplicar `EmployeeStatusConstants::ACTIVO`.
- `params['status'] === 'all'` → no aplicar filtro (bypass explícito).
- Cualquier otro valor → aplicar literal (`activo`, `retirado`).

```php
public function apply(SelectQuery $query, array $params): SelectQuery
{
    $this->applySearch($query, $params['search'] ?? null);
    $this->applyExact($query, 'Employees.position_id', $params['position_id'] ?? null);
    $this->applyExact($query, 'Employees.operation_center_id', $params['operation_center_id'] ?? null);
    $this->applyEmployeeStatus($query, $params['status'] ?? null);

    return $query;
}

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
```

Importar `App\Constants\EmployeeStatusConstants` al inicio del archivo.

#### `templates/Employees/index.php`

Reemplazar el dropdown de status (líneas 78-83) por opciones explícitas, sin `empty`:

```php
<?= $this->Form->select('status', [
    \App\Constants\EmployeeStatusConstants::ACTIVO   => 'Activo',
    \App\Constants\EmployeeStatusConstants::RETIRADO => 'Retirado',
    'all'                                            => 'Todos',
], [
    'class' => 'form-select form-select-sm',
    'value' => $this->request->getQuery('status') ?: \App\Constants\EmployeeStatusConstants::ACTIVO,
]) ?>
```

Comportamiento:
- Sin query string → "Activo" seleccionado y filtro aplicado.
- `?status=retirado` → "Retirado" seleccionado.
- `?status=all` → "Todos" seleccionado, sin filtro.

---

### 4. Migración de índices

Crear migración `YYYYMMDDhhmmss_AddIndexesToEmployeeNovelties.php` (generar con `php bin/cake migrations create AddIndexesToEmployeeNovelties`).

Estructura:

```php
public function up(): void
{
    $table = $this->table('employee_novelties');
    if (!$table->hasIndexByName('idx_novelty_pipeline_dates')) {
        $table->addIndex(
            ['pipeline_status', 'start_date', 'end_date'],
            ['name' => 'idx_novelty_pipeline_dates'],
        )->update();
    }
    if (!$table->hasIndexByName('idx_novelty_permission_date')) {
        $table->addIndex(
            ['permission_date'],
            ['name' => 'idx_novelty_permission_date'],
        )->update();
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
```

Extender `Migrations\BaseMigration` (NO `AbstractMigration`).

**Notas:**
- Los índices son idempotentes (`hasIndexByName` antes de crear/borrar).
- En MariaDB/MySQL, agregar índices a una tabla con datos puede demorar; la cantidad de filas en `employee_novelties` debería ser baja en el ambiente actual y no afectar el deploy.

---

## Validación manual

Levantar `php bin/cake server` y ejercitar:

1. **Migración:**
   - `php bin/cake migrations migrate` → ejecuta sin errores.
   - Re-ejecutar `migrate` → no falla (idempotente).
   - `php bin/cake migrations rollback` → revierte los índices.
   - Re-aplicar antes de continuar con el resto.

2. **`view()` — query duplicada eliminada:**
   - Activar el panel de SQL queries de DebugKit (si está disponible) o contar queries con `var_dump($this->Employees->getConnection()->getDriver()->log())` puntualmente.
   - Abrir `/employees/view/{id}` de un empleado con novedad activa hoy → la novedad se muestra correctamente y se ejecuta 1 query menos vs `main`.
   - Abrir el mismo empleado con la novedad ya pasada (fechas vencidas) → no aparece como activa.

3. **`view()` — getter en memoria:**
   - Empleado con novedad rechazada (`pipeline_status='Rechazada'`) que cae en rango de fechas → no aparece como `current_novelty`.
   - Empleado con `permission_date = HOY`, sin `start_date` ni `end_date` → aparece como `current_novelty`.
   - Empleado con `start_date <= HOY <= end_date` → aparece como `current_novelty`.
   - Empleado con varias novedades vigentes → aparece la más reciente por `created DESC` (orden actual del contain).
   - Empleado sin novedades → `current_novelty = null`, sección no renderiza.

4. **`index()` — default activo:**
   - Cargar `/employees` sin query string → solo empleados con `status='activo'`. El dropdown muestra "Activo" seleccionado.
   - Cambiar dropdown a "Retirado" y enviar → URL `?status=retirado`, solo retirados.
   - Cambiar dropdown a "Todos" y enviar → URL `?status=all`, ambos.
   - Limpiar query string manualmente (`/employees`) → vuelve a default activos.

5. **Filtros combinados:**
   - `/employees?search=garcia&status=all` → busca en todos los estados.
   - `/employees?operation_center_id=1` → activos de ese centro (default activo aplica).

6. **Performance (opcional):**
   - En la BD remota, antes de aplicar la migración: `EXPLAIN SELECT * FROM employee_novelties WHERE pipeline_status != 'Rechazada' AND ((permission_date = '2026-05-07' AND start_date IS NULL) OR (start_date <= '2026-05-07' AND end_date >= '2026-05-07'));` → anotar `type` y `rows`.
   - Después de la migración: re-ejecutar `EXPLAIN`. Espera mejora en `type` (de `ALL` a `range`/`ref`) o reducción en `rows` examinadas.

---

## Archivos a tocar

| Archivo | Acción |
|---------|--------|
| `src/Model/Entity/Employee.php` | Modificar `_getCurrentNovelty()` |
| `src/Controller/EmployeesController.php` | Eliminar query duplicada en `view()` |
| `src/Service/EmployeeFilterService.php` | Refactor de filtro de status con default y bypass |
| `templates/Employees/index.php` | Dropdown de status con 3 opciones explícitas |
| `config/Migrations/YYYYMMDDhhmmss_AddIndexesToEmployeeNovelties.php` | Migración nueva |

---

## Riesgos y mitigaciones

| Riesgo | Mitigación |
|--------|-----------|
| El cambio de default a `activo` rompe filtros guardados/bookmarks de usuarios | El bypass `?status=all` es explícito y la auditoría lo recomendó. Comunicación al equipo si hay favoritos guardados. |
| Diferencia sutil de orden con el getter en memoria vs SQL | El finder y el contain ya ordenan por `created DESC`. El getter devuelve el primero que pasa el filtro = el más reciente que está activo hoy. Mismo contrato. |
| Índices agregan tiempo al deploy en BD con muchos registros | `employee_novelties` tiene volumen bajo en el ambiente actual. Si el deploy demora, considerar `ALGORITHM=INPLACE, LOCK=NONE` (MySQL 5.6+) — pero Phinx no expone esto fácilmente; se puede ejecutar el SQL manualmente si fuera necesario. |
| Comparación de fechas como string en PHP falla en zonas horarias edge | Las columnas `Date` (no `Datetime`) en CakePHP son zona-naive y `__toString` da ISO. Si fueran `Datetime`, requeriría parseo. Verificar tipo en entity al implementar. |

---

## Fuera de alcance

- Filtros UI nuevos (toggle "Solo activos" como checkbox separado) → la auditoría sugirió toggle pero el dropdown ya existente cumple.
- Combinar `findWithCurrentNovelty` y `view()` en una sola query SQL → la separación entre "novedad actual" e "historial completo" es deliberada y mantenerla con el getter en memoria es más simple.
- Resto de hallazgos pendientes (CR-011 a CR-030) — se abordan en lotes posteriores.

---

## Próximos pasos tras esta PR

Continuar con Lote 3 (Architecture): CR-011 (DI en callbacks de import) y CR-018 (split de `EmployeeDocumentService`).

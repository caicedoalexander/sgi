# Empleados — Unificar vista principal y eliminar `index`

**Fecha:** 2026-05-19
**Módulo:** Employees
**Tipo:** Refactor de UX + simplificación de controlador

---

## Contexto y motivación

El módulo de Empleados tiene actualmente tres pantallas:

- `templates/Employees/index.php` (230 líneas) — listado tabular paginado 15/pág.
- `templates/Employees/view.php` (719 líneas) — master-detail con lista lateral + panel de detalle.
- `templates/Employees/edit.php` (39 líneas) — formulario clásico de edición.

La pantalla `view` ya cumple la función del listado: su panel izquierdo (`sgi-md-left`) muestra una lista navegable con búsqueda y tabs de estado, lo que hace redundante el listado tabular de `index`. Mantener dos pantallas equivalentes duplica esfuerzo de mantenimiento y confunde el modelo mental del usuario (¿qué hace `/employees` vs `/employees/view/{id}`?).

Este spec elimina `index` como pantalla y convierte `/employees` en un **redirect inteligente** al primer empleado de la lista (autoselección), preservando los filtros del query string. La pantalla `view` absorbe los features que vivían solo en `index` (botones Excel, filtros avanzados, paginación).

`edit` se conserva sin cambios funcionales.

## Alcance

**Incluye:**

- Transformar `EmployeesController::index()` en redirect-to-first / empty-state.
- Enriquecer el panel izquierdo de `view.php` con: botones Excel, filtros Cargo / Centro de Operación, paginación 15/pág.
- Reemplazar `templates/Employees/index.php` por un template empty-state.
- Sincronizar `orderBy` entre `index()` y `view()` para que la autoselección coincida con la primera fila visible.

**Excluye:**

- Cambios en `add`, `edit`, `delete` (más allá del redirect que ya tenían a `index`, que sigue válido por el rebranding del endpoint).
- Permisos (no se tocan; siguen siendo `view` para listar/ver y `edit` para editar).
- Modificaciones a `EmployeeFilterService` — ya acepta `position_id` y `operation_center_id` (`src/Service/EmployeeFilterService.php:30-31`).
- Tests automatizados (proyecto no los usa — ver `CLAUDE.md` § Testing Policy).

## Decisiones de diseño

| Pregunta | Decisión |
|---|---|
| ¿Qué vista se conserva como pantalla principal? | `view.php` (master-detail). |
| ¿Se elimina `edit`? | No. Se elimina `index`. |
| ¿Dónde van los botones Excel? | Header del panel izquierdo de `view.php`, junto a "Nuevo". |
| ¿Dónde van los filtros avanzados (Cargo, Centro de Operación)? | Collapse dentro del panel izquierdo de `view.php`. |
| ¿Qué se muestra cuando no hay empleados? | Pantalla vacía con CTA "Crear empleado". Mensaje diferenciado entre "BD vacía" y "filtros sin matches". |
| ¿Paginación del panel lateral? | Sí, 15/pág (consistente con el resto del proyecto). |
| ¿Cómo se autoselecciona el primero? | `index()` ejecuta el mismo `find` ordenado, toma el primer id y hace `redirect` a `view($id)` preservando query string. |

## Arquitectura

### Rutas resultantes

| URL | Acción | Comportamiento |
|---|---|---|
| `GET /employees` | `index()` | Si hay empleados que matchean: 302 a `/employees/view/{firstId}?{filtros}`. Si no: renderiza empty-state. |
| `GET /employees/view/{id}` | `view($id)` | Master-detail con `$id` seleccionado + lista lateral paginada. |
| `GET /employees/edit/{id}` | `edit($id)` | Formulario clásico (sin cambios). |
| `GET /employees/add` | `add()` | Formulario de creación (sin cambios). |

### Cambios en `EmployeesController`

**`index()` — rescritura completa (~15 líneas):**

```php
#[Permission(action: 'view')]
public function index()
{
    $query = $this->Employees->find()
        ->select(['id'])
        ->orderBy(['Employees.last_name1' => 'ASC', 'Employees.last_name2' => 'ASC']);

    $this->filterService->apply($query, $this->request->getQueryParams());

    $firstId = $query->first()?->id;

    if ($firstId === null) {
        $hasAnyEmployee = $this->Employees->exists([]);
        $this->set(compact('hasAnyEmployee'));
        return; // renderiza templates/Employees/index.php (empty-state)
    }

    return $this->redirect([
        'action' => 'view',
        $firstId,
        '?' => $this->request->getQueryParams() ?: null,
    ]);
}
```

Notas:

- No usa `withCurrentNovelty` ni `contain(['Positions', 'OperationCenters'])` porque solo necesita el `id` para redirigir.
- El `orderBy` debe ser idéntico al de `view()::navQuery` para garantizar consistencia.
- `exists([])` distingue entre "BD vacía" y "filtros sin matches".

**`view()` — ajustes en preparación de `navEmployees` (líneas 111-119):**

- Sustituir `$navEmployees = $navQuery->limit(200)->all()->toArray()` por `$navEmployees = $this->paginate($navQuery, ['scope' => 'nav'])`.
- Agregar lectura de filtros adicionales del query string (`position_id`, `operation_center_id`) — `EmployeeFilterService::apply` ya los consume (`src/Service/EmployeeFilterService.php:30-31`); solo hay que pasarlos al template para repintar los selects.
- Agregar al `set()`: `$positions` y `$operationCenters` (vía `find('codeList')`) para alimentar los nuevos selects del panel lateral.

**`delete()` — sin cambios de código (línea 181):**

El redirect a `['action' => 'index']` se mantiene literalmente pero su comportamiento cambia: ahora redirige al siguiente empleado disponible (o a la pantalla vacía si era el último).

### Cambios en templates

**`templates/Employees/index.php` — reemplazo completo (~40 líneas):**

Empty-state dentro del wrapper `sgi-master-detail` para mantener coherencia visual:

- Panel izquierdo: cabecera "Empleados · 0 mostrados" + buscador + tabs + mensaje "Sin resultados".
- Panel derecho: icono `bi-people`, texto contextual (según `$hasAnyEmployee`) y botón "Crear empleado" cuando `userPermissions.employees.can_create`.

**`templates/Employees/view.php` — panel izquierdo enriquecido (líneas 68-157):**

1. **Header del panel izquierdo:** agregar `<?= $this->element('excel_wizard/buttons', [...]) ?>` antes del botón "Nuevo". El conteo "X mostrados" usa `$this->Paginator->counter(['scope' => 'nav', ...])`.
2. **Filtros avanzados:** después del search, antes de los tabs de estado, añadir un collapse `#empNavFilters` con dos selects (`position_id`, `operation_center_id`) y un trigger con icono `bi-funnel` que muestra badge con el count de filtros activos.
3. **Links de la lista (línea 128):** el `Url->build` debe propagar `position_id` y `operation_center_id` además de `search` y `status`.
4. **Paginación:** al final del bloque `sgi-md-left-list` agregar `<?= $this->element('pagination', ['scope' => 'nav']) ?>` con clase compacta.
5. **Excel wizard modals:** agregar `<?= $this->element('excel_wizard/modals', [...]) ?>` al final del template.

### Cambios en CSS

`webroot/css/styles.css` — añadir:

- `.sgi-md-pagination` — paginación compacta para el panel lateral (font-size reducido, padding ajustado).
- Ajuste a `.sgi-md-left-head` para acomodar la fila adicional de filtros sin desbordar verticalmente.

### Cambios en routing / sidebar

Ninguno. El link del sidebar (`templates/element/sidebar/rrhh.php:27`) sigue apuntando a `['controller' => 'Employees', 'action' => 'index']` y eso ahora ejecuta el redirect-to-first.

## Data flow

**Flujo 1: navegación desde sidebar (sin filtros).**

`GET /employees` → `index()` → `find` sin filtros → toma primer id alfabético → `302 GET /employees/view/{id}` → render master-detail con ese empleado seleccionado.

**Flujo 2: cambio de filtro en el panel izquierdo.**

Usuario está en `view/123`, cambia el select de Cargo. Form GET del panel envía `position_id=5&status=activo&search=` al endpoint actual `view/123`. Recargada: `view(123)` aplica filtros al `navQuery`, paginación 15/pág. El empleado 123 sigue siendo el seleccionado en el panel derecho aunque ya no esté en la página visible de la lista. Esto es deliberado.

**Flujo 3: BD vacía.**

`GET /employees` → `index()` → `find` retorna null → `$hasAnyEmployee = false` → render `index.php` (empty-state) con mensaje "Aún no hay empleados registrados" + CTA "Crear empleado".

**Flujo 4: filtros que no matchean.**

`GET /employees?search=zzz` → `index()` → `find` retorna null → `$hasAnyEmployee = true` → render `index.php` con mensaje "Sin empleados que coincidan con los filtros". El panel izquierdo aún muestra search y tabs, permitiendo limpiar el filtro sin salir de la pantalla.

**Flujo 5: borrar el empleado seleccionado.**

`POST /employees/delete/{id}` → `delete()` borra → `302 /employees` → `index()` → autoselecciona siguiente disponible o muestra empty-state. UX consistente sin pantalla intermedia.

**Flujo 6: bookmark legacy con paginación.**

`GET /employees?status=activo&page=3` → `index()` no usa `page` → redirige al primer activo. `page` se ignora silenciosamente. Aceptable.

## Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| `orderBy` divergente entre `index()` y `view()::navQuery` causa que el "primer" empleado seleccionado por `index` no aparezca como primera fila visible en el panel lateral. | Ambos métodos usan exactamente la misma cláusula `orderBy(['Employees.last_name1' => 'ASC', 'Employees.last_name2' => 'ASC'])`. Documentar en comentario inline en ambos sitios. |
| ~~`EmployeeFilterService::apply()` no soporta `position_id` o `operation_center_id`.~~ | Confirmado: ya los soporta (`src/Service/EmployeeFilterService.php:30-31`). Riesgo descartado. |
| Paginación rompe si el empleado seleccionado está en una página > 1 y el usuario espera verlo highlighted en la primera carga. | Aceptado como tradeoff: la selección persiste en el panel derecho aunque no esté visible en la primera página del navegador. El usuario puede paginar para ubicarlo. |
| Bookmarks externos a `/employees?page=N` quedan obsoletos. | Aceptable, no se ofrece compatibilidad. |
| Botones Excel en el panel izquierdo pueden afectar el espacio vertical del header del panel. | Ajuste CSS en `.sgi-md-left-head` para acomodar; medir durante implementación y reducir tamaño de botones si es necesario. |

## Validación manual

Tras la implementación, ejercitar manualmente (proyecto no usa tests automatizados, ver `CLAUDE.md` § Testing Policy):

1. `php bin/cake server` y abrir `http://localhost:8765/employees`. Verificar redirect a `/employees/view/{id}` con el primer empleado alfabético seleccionado.
2. En el panel lateral: probar buscador. Cambiar tabs Activos / Retirados / Todos. La URL refleja los cambios y la lista filtra.
3. Abrir collapse de filtros, seleccionar Cargo + Centro de Operación, aplicar. La lista lateral se restringe; el empleado en el panel derecho permanece visible aunque no esté en la lista filtrada.
4. Probar paginación del panel lateral: navegar entre páginas. El empleado seleccionado permanece estable en el panel derecho.
5. Click en cualquier empleado de la lista con filtros activos: la URL preserva `search`, `status`, `position_id`, `operation_center_id`.
6. Botones Excel del panel izquierdo: abrir el wizard de exportar e importar.
7. Borrar el empleado actualmente seleccionado: redirect a `/employees` autoselecciona el siguiente disponible.
8. `GET /employees?search=xxxxxxxxxxx`: debe mostrar empty-state con mensaje "Sin empleados que coincidan con los filtros".
9. Acceder con un usuario sin permiso `can_view` en `employees`: debe rechazar el acceso (permisos no se tocan).
10. Botón "Editar" del header del panel derecho: abre `/employees/edit/{id}` con el formulario clásico.
11. Bookmark a `/employees/index?status=retirado&page=2`: redirige a `view` de un retirado (ignora `page`).
12. Verificar que CSS del panel lateral no se rompe en pantallas estrechas (responsive del `sgi-master-detail`).

## Archivos afectados

| Archivo | Cambio |
|---|---|
| `src/Controller/EmployeesController.php` | Reescribir `index()`. Ajustar `view()` para paginar `navEmployees` y exponer `$positions`, `$operationCenters`. |
| `templates/Employees/index.php` | **Reemplazar** por template empty-state (~40 líneas). |
| `templates/Employees/view.php` | Enriquecer panel izquierdo: header con botones Excel, collapse de filtros avanzados, links con query string completo, paginación, modales Excel. |
| `webroot/css/styles.css` | Añadir `.sgi-md-pagination`. Ajustar `.sgi-md-left-head` si es necesario. |

## Out of scope para este spec

- Refactor del template de `view.php` más allá del panel izquierdo (el panel derecho sigue como está).
- Cambios en la acción `edit`.
- Sustituir `edit` por edición inline en el panel derecho (sería otro spec si se quisiera).
- Acción `add` no se toca.

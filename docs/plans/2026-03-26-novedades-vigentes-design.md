# Novedades Vigentes — Diseño

## Objetivo

Agregar una nueva vista "Vigentes" al submenu de novedades en el sidebar, que muestre en un calendario mensual las novedades cuyo periodo abarca la fecha actual y que ya han sido aprobadas/procesadas.

## Definicion de "vigente"

Una novedad es vigente si cumple ambas condiciones:

1. **Pipeline status** en: `rrhh`, `contabilidad`, `revision_firmas`, `gdp`, `tesoreria`, `pagada`
2. **Periodo cubre hoy:**
   - Si `schedule_type = 'days'`: `start_date <= hoy <= end_date`
   - Si `schedule_type = 'hours'`: `permission_date = hoy`

## Backend

### Nuevo action: `EmployeeNoveltiesController::active()`

- Renderiza la vista del calendario con filtros.
- No usa paginacion (el calendario necesita todos los eventos del mes visible).
- Calcula los filtros disponibles (tipos de novedad, empleados).

### Nuevo action: `EmployeeNoveltiesController::activeEvents()`

- Endpoint JSON para FullCalendar.
- `GET /employee-novelties/active-events?start=YYYY-MM-DD&end=YYYY-MM-DD`
- Parametros opcionales: `novelty_type_id`, `employee_id`
- Retorna array JSON con eventos:
  ```json
  {
    "id": 123,
    "title": "Juan Perez - Vacaciones",
    "start": "2026-03-20",
    "end": "2026-03-27",
    "color": "#469D61",
    "url": "/employee-novelties/view/123"
  }
  ```
- Para novedades tipo `hours`: start y end son el mismo dia (`permission_date`).

### Contador en AppController

- Nueva variable global `$activeNoveltiesCount` calculada en `beforeFilter()`.
- Misma logica de "vigente" pero solo cuenta (no carga datos).

### Rutas (`config/routes.php`)

Antes de `fallbacks()`:

```php
$builder->connect('/employee-novelties/active', ['controller' => 'EmployeeNovelties', 'action' => 'active']);
$builder->connect('/employee-novelties/active-events', ['controller' => 'EmployeeNovelties', 'action' => 'activeEvents']);
```

### Permisos

Reutiliza `can_view` del modulo `EmployeeNovelties`. No requiere permiso adicional.

## Frontend

### Sidebar

Nuevo subitem debajo de "Rechazadas":

- **Label:** "Vigentes"
- **Icono:** `bi-calendar-check`
- **Ruta:** `/employee-novelties/active`
- **Badge:** `$activeNoveltiesCount` con `bg-success` (verde)

### Vista `templates/EmployeeNovelties/active.php`

- **Libreria:** FullCalendar (CDN, cargado solo en esta vista).
- **Vista por defecto:** `dayGridMonth`, locale `es`.
- **Navegacion:** Botones prev/next y boton "Hoy".
- **Eventos:** Barras horizontales multi-dia para novedades tipo `days`. Eventos de un dia para tipo `hours`.
- **Colores:** Paleta predefinida asignada por `novelty_type_id` (color consistente por tipo).
- **Click en evento:** Navega a `/employee-novelties/view/{id}`.

### Filtros (encima del calendario)

- **Tipo de novedad:** Select2 dropdown con todos los tipos.
- **Empleado:** Select2 dropdown con busqueda.
- Los filtros recargan eventos via AJAX (refetch de FullCalendar) sin refrescar la pagina.

### Layout

Mismo layout que otras vistas de novedades: titulo "Novedades Vigentes", breadcrumb, contenido a ancho completo.

## Archivos a crear/modificar

| Archivo | Cambio |
|---------|--------|
| `config/routes.php` | 2 rutas nuevas |
| `src/Controller/EmployeeNoveltiesController.php` | Actions `active()` y `activeEvents()` |
| `src/Controller/AppController.php` | Calculo de `$activeNoveltiesCount` |
| `templates/EmployeeNovelties/active.php` | Vista con calendario FullCalendar |
| `templates/layout/default.php` | Nuevo subitem "Vigentes" en sidebar |

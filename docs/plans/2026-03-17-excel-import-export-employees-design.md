# Diseño: Mejora Exportación/Importación Excel — Empleados

**Fecha:** 2026-03-17
**Módulo:** Employees (extensible a otros módulos)

---

## Resumen

Mejorar el sistema de exportación e importación Excel de empleados con:
- Encabezados en español
- Selección y reordenamiento de columnas en exportación (modal con SortableJS)
- Mapeo interactivo de columnas en importación (modal AJAX con auto-detección)
- Validaciones backend robustas
- Arquitectura reutilizable para otros módulos

---

## Arquitectura

### Componentes nuevos

| Archivo | Responsabilidad |
|---|---|
| `src/Service/ExcelMappingService.php` | Definiciones de campos por módulo, auto-mapeo, validación de mapeo |
| `src/Service/ExcelImportService.php` | Lectura de archivo, aplicar mapeo, upsert, validación de datos por fila |
| `webroot/js/excel-mapper.js` | UI de modales exportación/importación, AJAX, SortableJS |
| `webroot/js/vendor/Sortable.min.js` | Librería SortableJS para drag-and-drop |

### Componentes modificados

| Archivo | Cambios |
|---|---|
| `src/Controller/EmployeesController.php` | Nuevas acciones: `exportConfig()`, `export()` (modificar), `importUpload()`, `importProcess()` |
| `src/Service/ExcelService.php` | Adaptar `exportCatalog()` para recibir campos seleccionados + orden + labels español |
| `templates/Employees/index.php` | Reemplazar modales actuales por modales enriquecidos |

---

## Definición de campos (ExcelMappingService)

Cada módulo define un array de campos con esta estructura:

```php
[
    'field_name' => [
        'label' => 'Nombre en español',
        'type' => 'string|integer|decimal|date|boolean',
        'required' => true,        // siempre requerido (campo llave)
        'required_new' => true,    // requerido solo al crear nuevos registros
        'is_key' => true,          // campo llave para upsert
        'fk' => true,             // es foreign key
        'display_only' => true,    // se exporta pero se ignora al importar
    ],
]
```

### Campos de Employees

- `document_number` — Cédula (required, is_key)
- `document_type` — Tipo de documento
- `first_name` — Nombres (required_new)
- `last_name` — Apellidos (required_new)
- `birth_date` — Fecha de nacimiento (date)
- `gender` — Género
- `email` — Correo electrónico
- `phone` — Teléfono
- `address` — Dirección
- `city` — Ciudad
- `hire_date` — Fecha de ingreso (date)
- `termination_date` — Fecha de retiro (date)
- `salary` — Salario (decimal)
- `contract_type` — Tipo de contrato
- `vest_number` — Número de chaleco
- `eps` — EPS
- `pension_fund` — Fondo de pensión
- `arl` — ARL
- `severance_fund` — Fondo de cesantías
- `notes` — Observaciones
- `active` — Activo (boolean)
- `position_id` — ID Cargo (integer, fk)
- `position` — Cargo (display_only)
- `supervisor_position_id` — ID Cargo supervisor (integer, fk)
- `supervisor_position` — Cargo supervisor (display_only)
- `operation_center_id` — ID Centro de operación (integer, fk)
- `operation_center` — Centro de operación (display_only)
- `cost_center_id` — ID Centro de costos (integer, fk)
- `cost_center` — Centro de costos (display_only)
- `employee_status_id` — ID Estado (integer, fk)
- `employee_status` — Estado (display_only)
- `marital_status_id` — ID Estado civil (integer, fk)
- `marital_status` — Estado civil (display_only)
- `education_level_id` — ID Nivel educativo (integer, fk)
- `education_level` — Nivel educativo (display_only)
- `temporary_organization_id` — ID Temporal (integer, fk)
- `temporary_organization` — Temporal (display_only)

---

## Endpoints AJAX

### `GET /employees/export-config`
Retorna JSON con campos disponibles para exportar (field, label, checked=true).

### `POST /employees/export`
Recibe `{"fields": ["document_number", "first_name", ...]}` en el orden deseado.
Retorna descarga XLSX con solo esos campos en ese orden, encabezados en español.

### `POST /employees/import-upload`
Recibe archivo .xlsx. Guarda temporal, lee encabezados, auto-mapea (español + inglés).
Retorna JSON: `{temp_file, file_headers, auto_mapping, system_fields}`.

### `POST /employees/import-process`
Recibe `{temp_file, mapping: {"ColumnaArchivo": "campo_sistema"}, enabled: ["col1","col2"]}`.
Procesa importación con upsert por document_number.
Retorna JSON: `{success, created, updated, skipped, errors}`.

---

## Interfaz de Usuario

### Modal Exportación
- Lista de campos con checkboxes (nombres en español)
- Drag handles (SortableJS) para reordenar
- "Seleccionar todos" arriba
- Botón Exportar descarga el archivo

### Modal Importación — 3 estados
1. **Subida:** Input file + botón Subir
2. **Mapeo:** Tabla con columnas detectadas ↔ dropdowns de campos del sistema, checkboxes para activar/desactivar, indicador de campos obligatorios
3. **Resultado:** Resumen con creados, actualizados, omitidos, errores detallados

---

## Validación Backend

### Importación por fila:
1. Leer valores según mapeo (solo columnas activadas)
2. Buscar por document_number → existe=UPDATE, no existe=CREATE
3. En CREATE: validar required_new (first_name, last_name)
4. Validar tipos: date (múltiples formatos + serial Excel), decimal, integer, string
5. Ignorar campos display_only
6. Cada fila independiente (sin transacción global)

### Auto-mapeo:
- Match por label español (case-insensitive, trim)
- Match por nombre de campo inglés (case-insensitive)
- El usuario siempre puede corregir antes de confirmar

---

## Reusabilidad

Para agregar otro módulo:
1. Definir mapa de campos en ExcelMappingService
2. Agregar las 4 acciones al controlador (delegando a los servicios)
3. Incluir excel-mapper.js con `data-module="NombreModulo"` en la vista

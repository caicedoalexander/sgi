# Gestión documental de empleados — navegación maestro-detalle por carpetas

**Fecha:** 2026-06-01
**Ámbito:** `templates/Employees/view.php` (sección de documentos) + JS asociado.
**Tipo:** mejora de comportamiento frontend. Sin cambios de backend, servicio, controlador ni BD.

## Problema

En la vista de un empleado (`/employees/view/{id}`), la sección de documentos tiene un panel
izquierdo de carpetas y un panel derecho de archivos, pero:

1. El panel izquierdo **no filtra**: cada carpeta es un `<a href="#folder-section-X">` que solo
   hace scroll por ancla.
2. El panel derecho mezcla **nombres de carpetas** (filas-encabezado) y textos "Carpeta vacía"
   con los documentos. El usuario quiere ver **solo documentos**.
3. Al entrar a un empleado no hay una selección coherente: debería quedar seleccionado el nodo
   raíz (nombre del empleado) y listarse **todos** los documentos.

## Solución

Maestro-detalle **100% del lado del cliente**. Toda la data (`$folders` con `employee_documents`
y `child_folders`) ya está cargada en el DOM por el controlador. Un script muestra/oculta filas
de documentos según la carpeta seleccionada en el árbol izquierdo.

### Decisiones acordadas

- **Vista raíz** (nodo "nombre del empleado", `data-folder-id="all"`): lista plana de **todos**
  los documentos, con una columna **"Carpeta"** que da contexto de origen. Sin filas-encabezado
  de carpeta.
- **Subcarpetas:** se muestran **anidadas e indentadas** bajo su carpeta padre en el árbol
  izquierdo y son **seleccionables de forma independiente** (cada una con su propio
  `data-folder-id`). Seleccionar una carpeta padre filtra solo los documentos cuyo
  `folder_id` es esa carpeta padre; los de la subcarpeta se ven al seleccionar la subcarpeta.
- **Enfoque:** JS del lado del cliente. Sin recargas, sin cambios de controlador.

## Cambios concretos

### A. Preámbulo PHP (cerca de línea 32 de `view.php`)

Construir un arreglo plano `$allDocs` aplanando carpetas y subcarpetas:

```
$allDocs = [ { doc, folderId, folderName }, ... ]
```

Recorre `$folders` → documentos propios (folderId = folder.id, folderName = folder.name) y
luego `child_folders` → sus documentos (folderId = subfolder.id, folderName = subfolder.name).
Mantener el cálculo de `$totalDocs` existente.

### B. Árbol izquierdo (≈ líneas 494-533)

- Nodo raíz: ítem clicable con `data-folder-id="all"`, **activo por defecto**. Se elimina el
  resaltado estático actual y se reemplaza por una clase de estado que el JS gestiona.
- Cada carpeta de primer nivel: ítem clicable con `data-folder-id="{folder.id}"`.
- Cada subcarpeta: ítem clicable **indentado** bajo su padre, con `data-folder-id="{subfolder.id}"`.
- Se eliminan los `href="#folder-section-X"` (ya no hay anclas).
- Contadores por carpeta se mantienen.
- Estado activo: fondo `var(--primary-soft)`, texto `var(--primary-color)`, peso 700 (igual al
  resaltado raíz actual).

### C. Panel derecho (≈ líneas 535-613)

- Header de columnas: **Documento · Carpeta · Cargado · Acciones**.
- Una **única lista plana** de documentos a partir de `$allDocs`. Cada fila:
  - lleva `data-folder-id="{folderId}"`,
  - muestra la columna "Carpeta" con `folderName`,
  - reutiliza los átomos visuales actuales (icono por mime, link de descarga, pill de tipo,
    tamaño, subido por, fecha, acciones abrir/eliminar).
- Se **eliminan** todas las filas-encabezado de carpeta y los textos "Carpeta vacía" /
  "Subcarpeta vacía" del panel derecho.
- Un bloque **empty-state oculto** (`display:none`) que el JS muestra cuando la carpeta
  seleccionada no tiene documentos.
- Footer de estado se mantiene (puede actualizarse opcionalmente por JS; no es requisito).

Nota: el panel derecho deja de usar el elemento `employee_documents_table.php` para esta vista
(necesitamos columna "Carpeta" y `data-folder-id` por fila). El elemento queda intacto; hoy solo
lo referencia `view.php`.

### D. JavaScript

Script (inline al final de `view.php` o en `sgi-common.js`) con:

- Delegación de eventos sobre el contenedor del árbol izquierdo.
- `selectFolder(id)`:
  - mueve el resaltado activo al ítem clicado,
  - muestra filas cuyo `data-folder-id` coincide; oculta el resto (`all` → todas),
  - togglea el empty-state según haya o no filas visibles.
- Estado inicial: raíz (`all`) activa → todas las filas visibles.

## Casos borde

- **Empleado sin carpetas:** el empty-state previo de la sección (≈ línea 429) queda intacto.
- **Carpeta sin documentos:** empty-state inline del panel derecho.
- **Clic en raíz:** muestra todos los documentos.

## Verificación

Cambio solo de frontend; CLAUDE.md no define suite de tests para vistas. Verificación manual en
navegador (Chrome) sobre `/employees/view/{id}`:

1. La raíz aparece seleccionada y lista todos los documentos.
2. Clic en una carpeta filtra solo sus documentos.
3. El panel derecho no muestra nombres de carpeta como filas (solo documentos + columna "Carpeta").
4. Las subcarpetas aparecen anidadas y son seleccionables.
5. Carpeta vacía → muestra empty-state, no filas residuales.

## Fuera de alcance

- Carga por servidor / paginación de documentos.
- Reordenar, buscar o renombrar documentos/carpetas.
- Cambios al modal de subida o a `EmployeeDocumentService`.

# Selección obligatoria de carpeta en el gestor documental del empleado

**Fecha:** 2026-07-11
**Módulo:** Employees (tab "Documentos" de `Employees/view`) — módulo **CRUD**, no de flujo:
no tiene pipeline, ni `PipelineStatus`, ni ViewModel/Presentation. No aplican las reglas de
paridad de módulos de flujo ni la regla anti-drift de `{Modulo}Presentation`.
**Tipo:** Cambio de UX en la vista + ajuste menor de controller y service.
**Esquema de BD:** sin cambios. No hay migración.
**RBAC:** sin cambios. No se tocan `permissions`, `$controllerModuleMap` ni los atributos
`#[Permission]` de las acciones existentes.

## Problema

El gestor documental de `templates/Employees/view.php` renderiza a la izquierda un árbol
cuyo primer ítem es un **nodo raíz sintético** (`data-folder-id="all"`, etiquetado con el
nombre del empleado) y a la derecha una **lista plana con todos los documentos** de todas
las carpetas y subcarpetas. Al entrar a la vista se selecciona ese nodo raíz, de modo que
lo primero que ve el usuario es el volcado completo de documentos, con una columna
"Carpeta" en cada fila para saber de dónde sale cada archivo.

Se quiere que el gestor se comporte como un explorador de carpetas real: no existe la vista
"todos los documentos"; siempre hay una carpeta seleccionada y la tabla muestra únicamente
los documentos de esa carpeta.

## Comportamiento objetivo

1. El árbol muestra **solo carpetas reales** (raíces + subcarpetas indentadas, como hoy). El
   nodo sintético del empleado desaparece.
2. Siempre hay exactamente una carpeta seleccionada. La tabla de la derecha lista
   únicamente los documentos de esa carpeta.
3. Al entrar al empleado se **autoselecciona la primera carpeta del árbol**.
4. La carpeta activa vive en la URL (`?folder=<id>`). Cambiar de carpeta no recarga la
   página, pero sí actualiza la URL, de modo que refrescar o volver atrás conserva la
   carpeta abierta.
5. Las tres acciones mutadoras (crear carpeta, subir documento, eliminar documento)
   redirigen a la carpeta afectada, no a la primera.
6. El modal "Subir documento" preselecciona la carpeta activa como destino.
7. La columna "Carpeta" de la tabla se elimina (es redundante con la selección) y el
   breadcrumb del toolbar pasa a mostrar la carpeta activa.

### Fuera de alcance (decisiones explícitas)

- Una carpeta padre sigue mostrando **solo sus propios documentos**, no los de sus
  subcarpetas. Es el comportamiento actual y no cambia.
- El select "Carpeta Padre (opcional)" del modal "Nueva Carpeta" **se conserva**: las
  subcarpetas siguen existiendo.
- El cálculo de "Completitud Documental" y el footer de totales siguen siendo **globales**
  (todos los documentos del empleado), no por carpeta.
- `src/Service/AssetDocumentService.php` se autodescribe como "réplica del patrón de
  EmployeeDocumentService" y su `deleteDocument()` también retorna `ServiceResult::ok()` sin
  payload. **No se toca**: la divergencia es intencional y no debe "armonizarse" de rebote.
- El select del modal de subida tiene ≥7 opciones y por convención del proyecto debería ser
  `select2-enable`. Es deuda preexistente; **no se corrige aquí**.
- Los dos `<form method="get">` de filtros del directorio izquierdo (buscador, tabs de estado,
  filtros avanzados) **no** propagan `?folder=`: filtrar estando dentro de una carpeta te
  devuelve a la primera. Se acepta — el requisito es el inverso (cambiar de carpeta sin perder
  los filtros), y ese sí se cumple vía `$navBaseQuery`.

## Diseño

### Orden del árbol y fuente única del orden

El orden de pintado actual es: cada carpeta raíz (ordenadas alfabéticamente por el
`orderBy` de `EmployeesController::view()`), y tras cada una sus subcarpetas (sin `sort`
explícito en el `contain` → orden de BD). El aplanado que decide "cuál es la primera
carpeta" **debe recorrer `$folders` exactamente igual que el render** (raíz, sus hijas,
siguiente raíz…), para que el primer elemento del array sea siempre el primer ítem pintado.
No se reordena nada ni se añade un `sort` al `contain`.

### Controller — `src/Controller/EmployeesController.php`

**`view()`** deriva la selección y la pasa a la plantilla (la plantilla queda tonta; el
controller ya es el dueño del query de `$folders` y ya deriva otros valores del query
string como `$navStatus` / `$navSearch`):

- Aplanar `$folders` a `$folderIds` en orden de render, **con los ids casteados a `string`**.
- Resolver la carpeta activa:

  ```php
  $requested = $this->request->getQuery('folder');
  $requested = is_scalar($requested) ? (string)$requested : '';
  $selectedFolderId = in_array($requested, $folderIds, true) ? $requested : ($folderIds[0] ?? null);
  ```

  El casteo a `string` en **ambos** lados es lo que hace que la comparación estricta
  funcione: los ids de BD son `int` y el query siempre llega como `string`, así que
  `in_array('7', [5, 7, 9], true)` daría `false` y el parámetro quedaría como código muerto.
- Derivar también `$selectedFolderName` (nombre de la carpeta activa) para el valor inicial
  del breadcrumb.
- `set()` de `selectedFolderId` y `selectedFolderName` junto al resto.

El id que llega por `?folder=` **no se confía**: se valida contra las carpetas realmente
cargadas del empleado y cae a la primera si no coincide. No hay superficie de IDOR — el
parámetro solo decide qué ítem del árbol se resalta, y los nombres de todas las carpetas ya
se renderizan en el árbol para cualquiera con `employees.can_view`.

**Redirects de las acciones mutadoras** — las tres pasan a llevar la carpeta afectada:

- `uploadDocument()`: ya lee `employee_folder_id` del POST → redirigir con
  `['action' => 'view', $employeeId, '?' => ['folder' => $folderId]]` en la rama de éxito.
  En la rama de fallo (sin archivo / carpeta inválida) se redirige **sin** el parámetro.
- `deleteDocument()`: tomar el id de carpeta de `$result->data['employee_folder_id']` cuando
  `$result->success` y redirigir igual. Si el borrado falla, redirigir sin el parámetro.
- `addFolder()`: `createFolder()` ya devuelve `ServiceResult::ok($folder)` con la entidad →
  redirigir con `'?' => ['folder' => $result->data->id]` en la rama de éxito, para cerrar el
  flujo "creo carpeta → subo documentos a ella".

### Service — `src/Service/EmployeeDocumentService.php`

`deleteDocument()` pasa de `ServiceResult::ok()` a:

```php
return ServiceResult::ok(['employee_folder_id' => $document->employee_folder_id]);
```

La entidad `$document` ya está cargada en ese método (vía `assertDocumentOwnership`), así que
no hay consulta extra.

### Template — `templates/Employees/view.php`

- **Eliminar el nodo raíz** del árbol (bloque `data-folder-id="all"`, hoy líneas 516-527).
- **Ítems del árbol = enlaces reales.** Cada carpeta pasa de `<div>` clicable a
  `<a href="...?folder=<id>">`, construido con `$this->Url->build()` reusando el
  `$navBaseQuery` que ya existe en la plantilla (línea 74) para **no perder los filtros del
  directorio izquierdo** al cambiar de carpeta:

  ```php
  ['action' => 'view', $employee->id, '?' => $navBaseQuery + ['status' => $navStatus, 'folder' => $folder->id]]
  ```

  Esto da navegación por teclado, URL correcta y degradación sin JS (cada carpeta sigue
  siendo alcanzable con una recarga). Los `<a>` conservan las clases y estilos actuales de
  `.doc-tree-item` (`text-decoration:none`), y llevan `data-folder-id` y
  `data-folder-name="<?= h($folder->name) ?>"` — este último es el **hook para el
  breadcrumb**: un `textContent` del ítem devolvería `"CONTRATO 3"` porque el ítem contiene
  también el `<span class="mono">` del contador.
- **Estado inicial server-side, sin parpadeo.** El `is-active` del ítem activo y el
  `is-hidden` de las `.doc-row` de otras carpetas se emiten **ya en el HTML**, no los aplica
  el JS al arrancar. Igual para el `display` inicial de `#docEmpty` (visible solo si la
  carpeta activa no tiene documentos) y para el texto inicial del breadcrumb. Sin esto, entre
  el paint y la ejecución del script el usuario vería todos los documentos del empleado — y
  ahora sin la columna "Carpeta" que los desambiguaba.
- **Eliminar la columna "Carpeta"**: del header de columnas, de cada `.doc-row`, y de los dos
  `grid-template-columns`, que pasan de `2fr 1fr 0.8fr 1fr 96px` a `2fr 0.8fr 1fr 96px` (uno
  en el `<style>` inline de `.doc-row`, línea 507; otro en el div del header, línea 563).
- **Limpiar el huérfano**: al desaparecer la columna, la clave `folderName` de `$allDocs`
  (líneas 37 y 42) se queda sin consumidor → eliminarla del array.
- **Breadcrumb del toolbar**: `Documentos · / <first_name>` pasa a
  `Documentos · / <NOMBRE DE LA CARPETA ACTIVA>`, en un `<span id="docCrumb">` renderizado
  server-side con `$selectedFolderName` y actualizado por el JS al cambiar de carpeta desde
  el `data-folder-name` del ítem.
- **Modal de subida**: el control `employee_folder_id` (línea 971) recibe
  `'default' => $selectedFolderId`, y el JS sincroniza el `<select>` (`#upload-folder-select`)
  con la carpeta activa cada vez que cambia. Sin esto, el usuario parado en *VACACIONES*
  pulsa "Subir documento" y, si no toca el select, el archivo se va a la primera carpeta
  y encima el redirect lo lleva ahí.
- **JS inline**: `selectFolder(id)` pierde la rama `id === 'all'` (el filtro pasa a ser
  estricto por `data-folder-id`) y suma la actualización del breadcrumb y del select del
  modal. El listener de clic del árbol hace `preventDefault()` sobre el `<a>`, filtra en
  cliente y sincroniza la URL con `history.pushState(…, item.href)` — el `href` del ancla ya
  trae todos los parámetros correctos. Un listener de `popstate` re-selecciona la carpeta
  leyendo `?folder=` de la URL, para que "atrás" funcione. Si el id de la URL no existe en el
  árbol, cae a la primera carpeta.
- Se conserva el **filtrado client-side**: se renderizan todas las filas y el JS muestra/oculta
  según la carpeta activa. Cambiar de carpeta sigue siendo instantáneo, sin request.
- Se conservan sin cambios: el empty-state por carpeta vacía (`#docEmpty`), el empty-state
  global "Sin carpetas ni documentos" (cuando el empleado no tiene carpetas — el guard de la
  línea 437 garantiza que dentro del `else` siempre hay al menos una carpeta), la card de
  completitud y el footer de totales.

## Archivos tocados

| Archivo | Cambio |
|---|---|
| `src/Service/EmployeeDocumentService.php` | `deleteDocument()` devuelve `employee_folder_id` en el payload |
| `src/Controller/EmployeesController.php` | `view()` deriva `selectedFolderId`/`selectedFolderName`; `uploadDocument()`, `deleteDocument()` y `addFolder()` redirigen con `?folder=` |
| `templates/Employees/view.php` | Árbol sin nodo raíz y con anclas; estado inicial server-side; sin columna "Carpeta"; breadcrumb dinámico; modal preseleccionado; JS |
| `tests/TestCase/Service/EmployeeDocumentServiceTest.php` | Nuevo test del payload de `deleteDocument()` |
| `tests/TestCase/Controller/EmployeesFolderSelectionTest.php` | **Nuevo.** Derivación de la carpeta activa (autoselección, `?folder=` válido/ajeno/no-escalar), redirects con `?folder=` y render del árbol/modal |

## Criterios de verificación

**Automáticos:**

1. Nuevo test de service: `deleteDocument()` de un documento existente retorna
   `ServiceResult::ok()` con `data['employee_folder_id']` igual a la carpeta del documento.
   (Hoy `deleteDocument()` no tiene ningún test; los 5 existentes cubren `uploadDocuments`.)
2. `tests/TestCase/Controller/EmployeesDocumentUploadTest.php` sigue verde (cubre la rama de
   fallo del upload, que redirige sin `?folder=`).
3. `composer cs-check` en verde.

**Manuales (la vista no tiene tests):**

4. Entrar a un empleado con varias carpetas → la **primera carpeta** del árbol aparece
   seleccionada y la tabla muestra **solo sus documentos**. El ítem con el nombre del empleado
   ya no existe.
5. Clic en otra carpeta → la tabla muestra solo los documentos de esa carpeta, el breadcrumb
   del toolbar cambia al nombre de la carpeta y la URL pasa a `?folder=<id>` sin recargar.
6. Refrescar (F5) estando en una carpeta → se conserva esa carpeta. Botón "atrás" → vuelve a
   la carpeta anterior.
7. Navegar el árbol con Tab + Enter (sin ratón) → se puede abrir cualquier carpeta.
8. Seleccionar una carpeta vacía → aparece el empty-state "Esta carpeta no tiene documentos".
9. Estando en una carpeta que no es la primera, abrir "Subir documento" → el select "Carpeta
   de Destino" viene preseleccionado con esa carpeta. Al subir, se permanece en ella y el
   archivo recién subido es visible.
10. Eliminar un documento de una carpeta que no sea la primera → tras la recarga, se
    permanece en esa carpeta.
11. Crear una carpeta nueva → tras la recarga, la carpeta recién creada queda seleccionada.
12. Manipular la URL con `?folder=<id de otro empleado>`, `?folder=abc` o `?folder[]=1` → la
    vista cae a la primera carpeta, sin error ni warning de PHP.
13. Empleado sin carpetas → sigue apareciendo el empty-state "Sin carpetas ni documentos".
14. Cambiar de carpeta con filtros activos en el directorio izquierdo (p. ej. `?search=…`) →
    los filtros se conservan.

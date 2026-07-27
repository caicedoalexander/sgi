# Multiselección de archivos al subir documentos — módulo Empleados

**Fecha:** 2026-07-08
**Módulo:** Empleados (`Employees`)
**Clasificación del módulo:** CRUD (no es módulo de flujo/pipeline).
**Migración:** ninguna. Reusa la tabla `EmployeeDocuments` existente (se crean N filas, una por archivo).
**Alcance:** Solo el módulo de empleados. No se toca el modal compartido `upload_doc_modal.php` ni ningún otro módulo de flujo.

## Problema

Hoy el modal "Subir Documento" del módulo de empleados (`templates/Employees/view.php`, `#uploadDocModal`) permite subir **un solo archivo** por operación a la carpeta de destino elegida. Para cargar varios documentos a una misma carpeta el usuario debe repetir el flujo N veces. Se quiere permitir seleccionar y subir **múltiples archivos** en una sola operación.

## Comportamiento acordado

- **Best-effort ante fallos parciales:** cada archivo se valida y procesa por separado. Los válidos se guardan; los que fallan (tipo no permitido, exceden tamaño, MIME que no coincide con la extensión) se reportan en el flash con su motivo. No es todo-o-nada.
- **Excepción — carpeta inválida:** si la carpeta de destino no existe o no pertenece al empleado, falla el **lote completo** (no tiene sentido procesar archivos sin destino válido).
- **Límite de cantidad:** el que impone PHP (`max_file_uploads`, típicamente 20). No se añade un tope adicional en la aplicación.

## Diseño

### 1. Vista — `templates/Employees/view.php` (modal `#uploadDocModal`)

- El input de archivo pasa de `name="file"` a `name="file[]"` con atributo `multiple`. Se usa un `<input>` crudo (dentro del `$this->Form->create(...)`, que ya aporta el token CSRF) en lugar de `$this->Form->control(...)`, para garantizar de forma predecible `name="file[]"` + `multiple` sin la ambigüedad de nombre/id que introduce el FormHelper con `[]`. Es el mismo enfoque de input plano del modal compartido `templates/element/upload_doc_modal.php`:
  ```php
  <label class="form-label" for="upload-files">Archivos</label>
  <input type="file" name="file[]" id="upload-files" class="form-control" required multiple
         accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.txt">
  ```
- Ajustar el help text a plural (p. ej. "Puedes seleccionar varios archivos. Máximo … por archivo — PDF, imágenes, Word, Excel o texto.").
- Sin cambios en el select `employee_folder_id` (carpeta de destino).

### 2. Servicio — `src/Service/EmployeeDocumentService.php`

Nuevo método público que orquesta el lote reutilizando el `uploadDocument()` por archivo ya existente:

```php
public function uploadDocuments(
    int $employeeId,
    int $folderId,
    array $files,          // lista de UploadedFile
    ?int $uploadedBy,
): ServiceResult
```

Responsabilidades:
1. **Filtrar entradas sin archivo real:** descartar los `UploadedFile` con `getError() === UPLOAD_ERR_NO_FILE`. Con `name="file[]"`, un submit sin selección normaliza a un array de **un** `UploadedFile` con `UPLOAD_ERR_NO_FILE` (no a `[]`); sin este filtro el lote reportaría un fallido con `name` vacío y motivo genérico. Tras filtrar, si no queda ningún archivo → `ServiceResult::fail('No se recibió ningún archivo válido.')`.
2. Verificar ownership de la carpeta **una sola vez** (fail-fast: si es inválida, `ServiceResult::fail('La carpeta seleccionada no existe o no pertenece al empleado.')` para todo el lote).
3. Iterar cada `UploadedFile` (ya filtrado) delegando en `uploadDocument()` (reúso total de la validación de extensión/MIME/tamaño y del guardado por archivo). El `name` de cada fallido se toma de `getClientFilename()`; si viene vacío, usar un placeholder `'(archivo sin nombre)'`.
4. Acumular resultados y retornar:
   ```php
   ServiceResult::ok([
       'uploaded' => int,                                  // cantidad guardada
       'failed'   => [['name' => string, 'error' => string], ...], // fallidos con motivo
   ]);
   ```

**Semántica best-effort:** un lote donde todos los archivos fallan validación (pero la carpeta es válida) devuelve `ServiceResult::ok(['uploaded' => 0, 'failed' => [...]])` — es `ok`, no `fail`. El `fail` se reserva para condiciones de lote completo (carpeta inválida / sin archivos). El controlador **debe** inspeccionar `->data`, no ramificar solo por `->success`.

`uploadDocument()` (por archivo) **queda intacto**. La verificación de carpeta que ya hace internamente `uploadDocument()` es redundante con la del lote pero inofensiva; no se refactoriza para mantener el cambio quirúrgico.

### 3. Controlador — `src/Controller/EmployeesController.php` (`uploadDocument`)

- Reemplazar la lectura de un solo archivo por la colección:
  ```php
  $uploaded = $this->request->getUploadedFiles()['file'] ?? [];
  $files = is_array($uploaded) ? $uploaded : [$uploaded];
  ```
- **Detección de `post_max_size` excedido:** si el POST agregado supera `post_max_size`, PHP descarta *todos* los archivos y `getUploadedFiles()` queda vacío, pero el `CONTENT_LENGTH` de la request es > 0. La multiselección hace este caso mucho más probable que en single-file. Antes de llamar al servicio, si `$files` está vacío **y** `CONTENT_LENGTH > 0`, emitir un `Flash->error` distinguible (p. ej. "Los archivos superan el tamaño total permitido por el servidor. Sube menos archivos a la vez.") y redirigir — evita el mensaje engañoso "No se recibió ningún archivo válido".
- Llamar a `$this->documentService->uploadDocuments(...)`.
- Construir el flash best-effort a partir de `$result->data` (**no** ramificar solo por `->success`, por la semántica best-effort de §2):
  - Si `uploaded >= 1`: `Flash->success` con el conteo pluralizado vía `__n('%d documento subido.', '%d documentos subidos.', $n, $n)`.
  - Si `failed` no está vacío: `Flash->error` listando `name (error)` de cada fallido.
  - Si el lote falló por carpeta inválida / sin archivos (`->success === false`): `Flash->error` con el mensaje del `ServiceResult`.
- Mantener `return $this->redirect(['action' => 'view', $employeeId]);`.
- El atributo `#[Permission(action: 'add')]` no cambia.

### 4. Tests — `tests/TestCase/Service/EmployeeDocumentServiceTest.php`

Seeding vía Factories del repo (`tests/Factory/`): `EmployeeFactory` (no existe `EmployeeFolderFactory` — la carpeta se crea con el método real `EmployeeDocumentService::createFolder()`, que además ejercita esa ruta). Los archivos de prueba se construyen como `Laminas\Diactoros\UploadedFile` apuntando a archivos temporales reales (`tempnam` + bytes de un PDF mínimo) para que `moveTo()` y `finfo` operen sobre contenido válido, replicando `tests/TestCase/Service/Integration/AssetDocumentServiceTest.php`.

Cobertura del método nuevo `uploadDocuments()`:
- Lote de N archivos todos válidos → `uploaded === N`, `failed === []`, N filas en `EmployeeDocuments`.
- Lote mixto (algunos válidos, alguno con extensión/MIME inválido) → los válidos se guardan, `failed` lista los rechazados con su motivo; resultado sigue siendo `ok`.
- Lote donde todos fallan validación pero la carpeta es válida → `ok` con `uploaded === 0` y `failed` poblado (verifica la semántica best-effort).
- Entradas con `UPLOAD_ERR_NO_FILE` filtradas → si el lote queda vacío tras filtrar, `ServiceResult::fail('No se recibió ningún archivo válido.')`.
- Carpeta inválida (no pertenece al empleado) → `ServiceResult::fail`, ninguna fila creada.

## Criterios de aceptación

1. El modal "Subir Documento" de Empleados permite seleccionar **varios** archivos en una sola operación (input `multiple`).
2. Subir N archivos válidos a una carpeta crea N filas en `EmployeeDocuments` y muestra un flash de éxito con el conteo pluralizado correctamente.
3. En un lote mixto, los archivos válidos se guardan y los inválidos se listan en un flash de error con su motivo (best-effort, no todo-o-nada).
4. Carpeta de destino inválida → no se guarda ningún archivo y se muestra el error de carpeta.
5. Submit sin archivo real → mensaje "No se recibió ningún archivo válido." (sin entradas fantasma con nombre vacío).
6. POST que excede `post_max_size` → mensaje distinguible sobre el tamaño total, no el genérico de "ningún archivo".
7. Ningún otro módulo (Refunds, PettyCash, etc.) ni el modal compartido `upload_doc_modal.php` cambian su comportamiento.
8. El permiso `add` sigue gobernando la acción; un rol sin `can_create` en `employees` no ve el botón ni puede subir.

## Fuera de alcance

- Modal compartido `upload_doc_modal.php` y los demás módulos de flujo (Refunds, PettyCash, EmployeeNovelties, NoveltyLiquidationDocs, PaymentSchedulings).
- Subida asíncrona / drag-and-drop / barra de progreso. El flujo sigue siendo POST síncrono con redirect.
- Cambios en la imagen de perfil (`handleProfileImage`) o en la estructura de carpetas.

# Auditoría — Módulo Employees

**Fecha:** 2026-05-07
**Alcance:** 19 archivos · Controller + 5 Entities + 5 Tables + 4 Services + 1 Constants + 4 Templates
**Modo:** Auditoría arquitectónica del módulo (PATH, sin diff de rama)
**Nivel:** HIGH (PSR + Encapsulation + Code Smells + Bug + Readability + SOLID + Security + Performance + DDD + Architecture)
**Verdicto global:** ❌ **REQUEST CHANGES** — funcionalmente completo y alineado con varias convenciones del SGI, pero con 3 vulnerabilidades de seguridad críticas y 8 issues mayores que bloquean aprobación.

> **Nota:** los planes describen *cómo llegar* a la solución (pasos, archivos a tocar, criterios de validación). El código concreto se decide al ejecutar cada plan.

---

## Estado de remediación

| ID | Severidad | Hallazgo | Estado | Resuelto en |
|----|-----------|----------|--------|-------------|
| CR-001 | 🔴 Critical | Validación de archivos basada en MIME del cliente + sin whitelist de extensiones | ✅ Resuelto | Lote 1 (2026-05-07) — whitelist de extensiones + finfo post-moveTo + .htaccess defense-in-depth |
| CR-002 | 🔴 Critical | IDOR en `uploadDocument` / `deleteDocument` / `addFolder` (sin verificar ownership) | ✅ Resuelto | Lote 1 (2026-05-07) — `assertFolderOwnership` / `assertDocumentOwnership` aplicados en todas las actions |
| CR-003 | 🔴 Critical | Documentos personales servidos directo desde `webroot/uploads/` sin auth | ✅ Resuelto | Lote 1 (2026-05-07) — storage en `ROOT/storage/employees/` + action `downloadDocument` con auth+ownership. Decisión: solo aplica a uploads nuevos; legacy queda bajo backwards-compat |
| CR-004 | 🟠 Major | `add()` y `delete()` no son atómicos; `delete()` borra archivos antes que la fila | ✅ Resuelto | Lote 1 (2026-05-07) — `_persistEmployee` envuelve add/edit en `transactional` con cleanup de archivos en catch; `delete()` invierte orden BD→FS |
| CR-005 | 🟠 Major | Resource leaks y `mkdir` sin verificar resultado | ✅ Resuelto | Lote 1 (2026-05-07) — `ensureDir` con patrón is_dir+mkdir+is_dir; try/catch en `moveTo` |
| CR-006 | 🟠 Major | `createDefaultFolders` ignora resultado de `save()` | ✅ Resuelto | Lote 1 (2026-05-07) — `saveMany(['atomic' => true])` retorna ServiceResult |
| CR-007 | 🟠 Major | Query duplicada en `view()`; falta filtro `status='activo'` en `index()`; índices probables faltantes | ✅ Resuelto | Lote 2 (2026-05-07) — getter en memoria + query duplicada eliminada en view + default activo en index + migración de índices |
| CR-008 | 🟠 Major | Doble `save()` en `add()` por imagen de perfil; archivo en disco si segundo save falla | ✅ Resuelto | Lote 1 (2026-05-07) — `handleProfileImage` ya no hace save; mutación in-memory + re-save dentro de transacción + cleanup en catch |
| CR-009 | 🟠 Major | `_getCurrentNovelty` acoplado al orden del finder | ✅ Resuelto | Lote 2 (2026-05-07) — _getCurrentNovelty filtra en memoria, desacoplado del orden del finder |
| CR-010 | 🟠 Major | `EmployeeDocumentService` retorna tipos heterogéneos — viola convención `ServiceResult` | ✅ Resuelto | Lote 1 (2026-05-07) — todos los métodos públicos retornan `ServiceResult`; controller usa `->success` / `firstError()` |
| CR-011 | 🟠 Major | `EmployeesTable` instancia services con `new` dentro de callbacks de import | ✅ Resuelto | Lote 3 (2026-05-07) — setters DI en EmployeesTable + inyección en EmployeesController::initialize, fallback a `new` para compat |
| CR-012 | 🟡 Minor | Magic strings de tipo doc, género, contrato en templates | ✅ Resuelto | Lote 4 (2026-05-07) — DocumentTypeConstants, GenderConstants creados; ContractTypeConstants reusado en _form |
| CR-013 | 🟡 Minor | Lógica duplicada de `currentNovelty` (controller vs finder) | ✅ Resuelto | Lote 2 (2026-05-07) — query duplicada del controller eliminada en CR-007; view() usa $employee->current_novelty |
| CR-014 | 🟡 Minor | `createDefaultFolders` hace inserts uno-a-uno | ⏳ Pendiente | — |
| CR-015 | 🟡 Minor | N×M de queries en chart de tipos de contrato | ⏳ Pendiente | — |
| CR-016 | 🟡 Minor | `iterator_to_array` innecesario en `index.php` | ⏳ Pendiente | — |
| CR-017 | 🟡 Minor | Entity `Employee` anémica (lógica de negocio fuera de la entity) | ⏳ Pendiente | — |
| CR-018 | 🟢 Aceptado | `EmployeeDocumentService` viola SRP (4 responsabilidades) | ✅ Aceptado | Lote 3 (2026-05-07) — service cohesionado en torno a gestión de archivos del empleado (~360 LOC). Re-evaluar si supera 500 LOC o aparece 5ta responsabilidad |
| CR-019 | 🟡 Minor | 5 closures de iconografía de archivos en `view.php` | ✅ Resuelto | Lote 4 (2026-05-07) — DocumentIconHelper creado y cargado en AppView |
| CR-020 | 🟡 Minor | `add.php` y `edit.php` ~95% duplicados | ✅ Resuelto | Lote 4 (2026-05-07) — extraído a templates/element/Employees/form.php con $mode |
| CR-021 | 🟡 Minor | JS de toggle inline duplicado entre `add.php` y `edit.php` | ✅ Resuelto | Lote 4 (2026-05-07) — extraído a webroot/js/employees-form.js |
| CR-022 | 🟡 Minor | `_setFormDropdowns` carga 7 catálogos sin caché | ⏳ Pendiente | — |
| CR-023 | 🟡 Minor | Inconsistencia de orden en contains (histories DESC vs observations ASC) | ✅ Resuelto | Lote 4 (2026-05-07) — divergencia intencional documentada con comentario en EmployeesController::view() |
| CR-024 | 🟢 Sugerencia | VO `SocialSecurityInfo` (eps + pension_fund + arl + severance_fund) | ⏳ Pendiente | — |
| CR-025 | 🟢 Sugerencia | VO `Identification` (document_type + document_number) | ⏳ Pendiente | — |
| CR-026 | 🟢 Sugerencia | Extraer `BaseFilterService` reusable | ⏳ Pendiente | — |
| CR-027 | 🟢 Sugerencia | Combinar dos AVG en una sola query | ⏳ Pendiente | — |
| CR-028 | 🟢 Sugerencia | Defense-in-depth en uploads (rate limit, AV, rename por finfo) | ⏳ Pendiente | — |
| CR-029 | 🟢 Sugerencia | Inyectar `StructuredLogger` por DI | ⏳ Pendiente | — |
| CR-030 | 🟢 Sugerencia | Conteo de documentos vía `hasManyCount` finder | ⏳ Pendiente | — |

---

## Resumen por categoría

| Categoría | 🔴 | 🟠 | 🟡 | 🟢 | Total |
|-----------|----|----|----|----|-------|
| Security | 3 | 0 | 0 | 1 | 4 |
| Bug | 0 | 5 | 1 | 0 | 6 |
| Performance | 0 | 1 | 3 | 2 | 6 |
| Architecture / SOLID | 0 | 2 | 1 | 1 | 4 |
| Code Smell / Readability | 0 | 0 | 5 | 0 | 5 |
| Encapsulation / DDD | 0 | 0 | 2 | 2 | 4 |
| **Total** | **3** | **8** | **12** | **6** | **29** |

---

# Hallazgos y planes de resolución

## 🔴 Critical

### CR-001 — Validación de archivos insuficiente (Path Traversal / RCE / XSS persistente)

**Categoría:** Security · OWASP A01/A03/A04
**Ubicación:** `src/Service/EmployeeDocumentService.php:47-62, 114-126`

**Problema**
La extensión del archivo se obtiene con `pathinfo($file->getClientFilename(), PATHINFO_EXTENSION)` y se concatena directamente al nombre en disco (`uniqid('doc_') . '.' . $extension`, `'profile.' . $extension`). El MIME se valida con `getClientMediaType()`, header **controlado por el cliente** y trivialmente falsificable. No hay whitelist de extensiones — solo whitelist de MIMEs cliente.

Vector: subir `payload.php` con header `Content-Type: image/jpeg` → archivo queda en `webroot/uploads/employees/{id}/doc_xxx.php` y es ejecutable si Apache/Nginx procesa PHP en esa ruta. Vector secundario: SVG con `<script>` → XSS persistente al ser renderizado inline.

**Plan de resolución**
1. Crear método `private function detectRealMime(string $path): string` que use `finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path)` **después** del `moveTo` (antes de `moveTo` el path es temporal de PHP).
2. Definir constante `ALLOWED_DOC_EXTENSIONS = ['pdf','jpg','jpeg','png','gif','doc','docx','xls','xlsx','txt']` y `ALLOWED_PROFILE_EXTENSIONS = ['jpg','jpeg','png','gif','webp']`.
3. Antes de `moveTo`: extraer extensión del nombre original, pasarla a `strtolower`, validar contra la whitelist; si no está → retornar error.
4. Después de `moveTo`: ejecutar `detectRealMime` y comparar contra `ALLOWED_DOC_MIMES`/`ALLOWED_PROFILE_MIMES` (la lista existente, pero ahora aplicada a MIME real). Si discrepa → `unlink` y retornar error.
5. Para uploads en `webroot/uploads/employees/`, agregar archivo `.htaccess` (Apache) o regla en `nginx.conf` que niegue ejecución de `*.php`, `*.phtml`, `*.phar`, `*.html`, `*.htm`, `*.svg`, y fuerce `Content-Disposition: attachment` para todo lo que no sea imagen verificada.
6. (Opcional, defense in depth) Para imágenes de perfil, re-encodear con GD: `imagecreatefromstring(file_get_contents)` → `imagejpeg`/`imagepng` → guardar. Esto destruye payloads embebidos en EXIF / metadatos.
7. Validación manual: subir `test.php` renombrado a `test.jpg` con `Content-Type: image/jpeg` → debe ser rechazado por `detectRealMime`. Subir `test.svg` con script embebido → debe ser rechazado por whitelist de extensiones.

**Archivos a tocar:** `src/Service/EmployeeDocumentService.php`, `webroot/uploads/employees/.htaccess` (nuevo), eventualmente `config/nginx-uploads.conf` si se usa Nginx.

**Prioridad:** Bloqueante — ejecutar antes de cualquier otra tarea del módulo.

---

### CR-002 — IDOR en operaciones sobre documentos y carpetas

**Categoría:** Security · OWASP A01 · Authorization
**Ubicación:** `src/Controller/EmployeesController.php:191-253`

**Problema**
`uploadDocument($employeeId)`, `deleteDocument($employeeId, $documentId)` y `addFolder($employeeId)` validan permiso de módulo `employees` vía `_enforcePermission`, pero **no verifican que el `folder_id` / `document_id` pasados en el body pertenezcan al `$employeeId` de la URL**. Un usuario con `can_edit` en Employees puede:
- En `addFolder`: enviar `parent_id` de carpeta de otro empleado → árbol cruzado.
- En `uploadDocument`: enviar `employee_folder_id` de otro empleado → archivo se guarda físicamente bajo `{employeeId-URL}/...` pero el registro queda apuntando a carpeta de otro empleado.
- En `deleteDocument`: borrar **cualquier** documento sin importar a qué empleado pertenezca (el `$employeeId` de la URL ni siquiera se usa para filtrar).

**Plan de resolución**
1. Crear en `EmployeeDocumentService` un método `assertFolderOwnership(int $employeeId, int $folderId): void` que haga `EmployeeFolders->get($folderId, ['conditions' => ['employee_id' => $employeeId]])` y lance `RecordNotFoundException` si no coincide. Equivalente `assertDocumentOwnership(int $employeeId, int $documentId)` con join a `EmployeeFolders`.
2. En `addFolder`: si `parent_id` no es null, llamar `assertFolderOwnership($employeeId, $parentId)` antes del save.
3. En `uploadDocument`: llamar `assertFolderOwnership($employeeId, $folderIdDelRequest)` antes de delegar al service.
4. En `deleteDocument`: cambiar el contrato del service a `deleteDocument(int $employeeId, int $documentId)` y filtrar por employee_id en el `get()`. El controller pasa ambos.
5. Capturar `RecordNotFoundException` y devolver Flash error genérico (no exponer si el recurso existe en otro empleado — evitar enumeration).
6. Validación manual: con dos empleados A y B, intentar via DevTools POST a `/employees/upload-document/A` con `employee_folder_id` de B → debe rechazar. Igual para delete-document y add-folder.

**Archivos a tocar:** `src/Controller/EmployeesController.php`, `src/Service/EmployeeDocumentService.php`.

**Prioridad:** Bloqueante.

---

### CR-003 — Documentos personales accesibles sin autenticación

**Categoría:** Security · OWASP A01 · Privacy / Ley 1581 de 2012
**Ubicación:** `src/Service/EmployeeDocumentService.php:52, 68` + `templates/Employees/view.php` (links a `/uploads/...`)

**Problema**
Los documentos (probablemente cédulas escaneadas, contratos, certificados de EPS, exámenes médicos) se guardan en `webroot/uploads/employees/{id}/...` y se enlazan con `<a href="/uploads/...">`. Cualquiera con la URL accede sin login. La aleatoriedad de `uniqid('doc_')` es trivial (timestamp + microsegundos). Riesgo legal por datos personales en Colombia.

**Plan de resolución**
1. Decidir destino: opción A (preferida) — mover storage a `ROOT . DS . 'storage' . DS . 'employees'` (fuera de `webroot/`); opción B — mantener en webroot pero proteger con `.htaccess` `Deny from all` + Nginx `internal;`.
2. Crear action `EmployeesController::downloadDocument($employeeId, $documentId)`:
   - Aplicar `_enforcePermission('view')` en el controller.
   - Llamar `assertDocumentOwnership($employeeId, $documentId)` (definido en CR-002).
   - Resolver path absoluto desde el `file_path` del registro.
   - Devolver con `$this->response->withFile($absolutePath, ['name' => $document->name, 'download' => true])`.
3. Migración de datos:
   - Script idempotente que recorra `employee_documents.file_path`, mueva físicamente cada archivo de `webroot/uploads/employees/...` a `storage/employees/...` y reescriba `file_path` para que sea ruta relativa al nuevo storage root (no a webroot).
   - Antes de borrar el origen, verificar `is_file()` en destino.
4. Reemplazar todos los `<a href="/uploads/...">` en `templates/Employees/view.php` por `<a href="<?= $this->Url->build(['action' => 'downloadDocument', $employee->id, $document->id]) ?>">`.
5. Actualizar `EmployeeDocumentService::uploadDocument` para que escriba en el nuevo path base; agregar constante `STORAGE_ROOT`.
6. Validación manual:
   - Logout y pegar URL directa de un documento → debe redirigir a login (404 si la action es la nueva).
   - Login como rol sin `can_view` en Employees → 403 al pegar URL de descarga.
   - Login como usuario válido pero pidiendo doc de otro empleado → error genérico.
   - Documento real → descarga con nombre original.

**Archivos a tocar:** `src/Controller/EmployeesController.php`, `src/Service/EmployeeDocumentService.php`, `templates/Employees/view.php`, `config/routes.php` (ruta nueva), un script CLI/migration de datos.

**Prioridad:** Bloqueante. Coordinar con CR-001 (whitelist) y CR-002 (ownership) — los tres tocan el mismo flujo y conviene resolverlos en una sola PR.

---

## 🟠 Major

### CR-004 — `add()` y `delete()` sin atomicidad

**Categoría:** Bug · Data integrity
**Ubicación:** `src/Controller/EmployeesController.php:115-123, 167`

**Problema**
- `add()` ejecuta `Employees->save` → `documentService->handleProfileImage` → `documentService->createDefaultFolders` sin transacción. Si el segundo o tercero falla, el empleado queda sin estructura documental o sin imagen de perfil persistida.
- `delete()` borra archivos físicos **antes** de intentar `Employees->delete`. Si el delete falla por FK/regla, los archivos ya se perdieron.

**Plan de resolución**
1. Envolver `add()` en `$this->Employees->getConnection()->transactional(function () { ... })`. Mover el `handleProfileImage` y `createDefaultFolders` dentro del closure. Si cualquiera lanza, la transacción de BD revierte.
2. Para los efectos en filesystem (archivo de imagen de perfil ya movido) que la transacción de BD **no** puede revertir, capturar excepción dentro del closure → `unlink` del archivo físico → re-lanzar.
3. En `delete()`: invertir el orden. Primero `Employees->delete($employee)`. Si retorna `true`, llamar `documentService->deleteEmployeeFiles($id)`. Si retorna `false`, mostrar Flash error y no tocar disco.
4. Validación manual:
   - Forzar fallo en `createDefaultFolders` (renombrar tabla `default_folders` temporalmente) → empleado no debe quedar creado.
   - En `delete`, agregar FK no-on-cascade temporal con datos relacionados → delete debe fallar y archivos del empleado deben seguir intactos.

**Archivos a tocar:** `src/Controller/EmployeesController.php` (acciones `add` y `delete`).

---

### CR-005 — Resource leaks y `mkdir` sin verificar resultado

**Categoría:** Bug
**Ubicación:** `src/Service/EmployeeDocumentService.php:53-55, 62-77, 119-126`

**Problema**
- `mkdir($uploadDir, 0755, true)` no verifica retorno. Si falla por permisos, el `moveTo` siguiente lanza excepción sin mensaje de dominio.
- Si `moveTo` lanza excepción, no hay try/catch y el flujo se interrumpe sin limpiar.
- `mkdir` no maneja race condition (otro proceso creando el directorio en paralelo).

**Plan de resolución**
1. Reemplazar el patrón `if (!is_dir(...)) mkdir(...)` por el idiom estándar PHP: `if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException(...)`.
2. Envolver `moveTo` + `save` en try/catch; si `save` falla → `unlink` (ya existe); si `moveTo` lanza → loggear con `StructuredLogger` y retornar mensaje user-friendly.
3. Considerar mover el chequeo de tamaño y MIME **antes** del `mkdir` para no crear directorios vacíos cuando la validación posterior va a fallar (orden actual: tamaño → MIME → mkdir → moveTo, ya está bien — verificar).

**Archivos a tocar:** `src/Service/EmployeeDocumentService.php`.

---

### CR-006 — `createDefaultFolders` ignora errores de save

**Categoría:** Bug · Silent failure
**Ubicación:** `src/Service/EmployeeDocumentService.php:147-164`

**Problema**
El loop hace `$foldersTable->save($folder)` sin verificar el resultado. Si una validación falla (p.ej. `name` excede límite), la carpeta no se crea y nadie se entera.

**Plan de resolución**
1. Cambiar el loop a `saveMany` con `['atomic' => true]`. Si retorna falso, lanzar excepción con detalles del primer entity con errores.
2. Alternativa equivalente: mantener loop pero acumular `$folder->getErrors()` y lanzar excepción al final si la lista no está vacía.
3. La excepción se captura en `add()` (CR-004) y dispara rollback transaccional.
4. Loggear con `StructuredLogger` el detalle de errores antes de lanzar.

**Archivos a tocar:** `src/Service/EmployeeDocumentService.php`.

**Bonus:** se puede combinar con CR-014 (saveMany como optimización).

---

### CR-007 — Query duplicada en `view()` + falta filtro `activo` en `index()`

**Categoría:** Performance · Code Smell
**Ubicación:** `src/Controller/EmployeesController.php:38-44, 92-104`; `src/Model/Table/EmployeesTable.php:186-213`

**Problema**
- `view()` carga `EmployeeNovelties` por contain (líneas 63-67) y **además** ejecuta una segunda query `currentNovelty` (92-104) que duplica la lógica de `findWithCurrentNovelty`.
- `index()` no filtra `Employees.status = 'activo'` por defecto, mostrando empleados retirados mezclados con activos.
- Sin verificación de índices en `employee_novelties (pipeline_status, start_date, end_date)` y `(permission_date)`.

**Plan de resolución**
1. En `EmployeesTable`, añadir custom finder `findCurrentNovelty(Query $q, int $employeeId)` que retorne 1 sola novedad activa (la lógica ya está en `findWithCurrentNovelty` — extraerla a método privado reusable).
2. En `view()`: eliminar el bloque de `currentNovelty` (92-104) y reusar `$employeesTable->find('currentNovelty', employeeId: $id)->first()`. Alternativa más limpia: agregar la novedad activa al contain del `get()` con un finder y dejar de hacer query separada.
3. En `index()`: añadir filtro por defecto `where(['Employees.status' => EmployeeStatusConstants::STATUS_ACTIVE])` y exponer un toggle en la UI para mostrar retirados (checkbox o filtro de status). Confirmar con usuario qué comportamiento espera.
4. Verificar índices ejecutando `SHOW INDEX FROM employee_novelties` en la BD remota. Si no existen los compuestos `(pipeline_status, start_date, end_date)` y `(permission_date)`, crear migración que los añada.
5. Validación manual:
   - Profiler de CakePHP en `view`: número de queries debe bajar en 1.
   - `index` por defecto solo activos; con filtro toggle, mostrar retirados.

**Archivos a tocar:** `src/Controller/EmployeesController.php`, `src/Model/Table/EmployeesTable.php`, posible migración nueva, `templates/Employees/index.php` (filtro UI).

---

### CR-008 — Doble `save()` por imagen de perfil + archivo en disco si segundo save falla

**Categoría:** Bug
**Ubicación:** `src/Service/EmployeeDocumentService.php:128-141` + `EmployeesController::add`

**Problema**
- En `add()`, primero `Employees->save($employee)` persiste el empleado, luego `handleProfileImage` mueve archivo y vuelve a hacer `Employees->save($employee)` solo para actualizar `profile_image`. Son **dos escrituras a la misma fila**.
- Si el segundo save falla, el archivo ya está en disco → inconsistencia (registro sin path, archivo huérfano).
- `handleProfileImage` no verifica el resultado del save.

**Plan de resolución**
1. Refactor del contrato: `handleProfileImage` deja de hacer save; solo mueve el archivo y **muta** `$employee->profile_image` en memoria. El controller hace el único `save()` después.
2. Reordenar el flujo en `add()`:
   - `newEmptyEntity` + `patchEntity`
   - Llamar `handleProfileImage($employee, $file)` → este mueve el archivo y setea `profile_image` en el entity (aún sin guardar).
   - `save($employee)` único.
   - Si `save` falla → llamar `documentService->cleanupProfileImage($employee->profile_image)` para borrar el archivo huérfano.
3. Igual flujo en `edit()` (revisar también).
4. `handleProfileImage` retorna `void` o lanza excepción (alinear con CR-010 → `ServiceResult`).
5. Validación manual: forzar fallo de validación en `Employees::save` después de subir imagen → no debe quedar archivo en `uploads/employees/{id}/profile.*`.

**Archivos a tocar:** `src/Service/EmployeeDocumentService.php`, `src/Controller/EmployeesController.php`.

---

### CR-009 — Virtual `current_novelty` acoplado al orden del finder

**Categoría:** Bug · Hidden coupling
**Ubicación:** `src/Model/Entity/Employee.php:52-57`

**Problema**
`_getCurrentNovelty` retorna `$this->employee_novelties[0]`. Funciona solo si quien cargó la entity usó el finder con `limit(1)` y filtros de fecha. Si otro caller hace `contain('EmployeeNovelties')` plano, retornará la novedad más reciente, no necesariamente la activa hoy.

**Plan de resolución**
1. Reescribir `_getCurrentNovelty` para que filtre **en memoria** sobre `$this->employee_novelties`:
   - Iterar el array.
   - Aplicar la misma condición de fechas que tiene el finder (`permission_date == today` OR `start_date <= today AND end_date >= today`).
   - Retornar la primera coincidencia o `null`.
2. Como Today se necesita para comparar, importar `FrozenDate::now()` (CakePHP) o `DateTimeImmutable` y compararlo con los campos.
3. Si `employee_novelties` no está cargado (lazy proxy CakePHP), el getter debe retornar `null` sin cargar — agregar `if (!$this->has('employee_novelties')) return null;`.
4. Validación manual: en `view.php` y `index.php` confirmar que `$employee->current_novelty` sigue mostrando lo correcto bajo distintos contains.

**Archivos a tocar:** `src/Model/Entity/Employee.php`.

---

### CR-010 — `EmployeeDocumentService` viola convención `ServiceResult`

**Categoría:** Architecture · Convention drift
**Ubicación:** `src/Service/EmployeeDocumentService.php` (todo el service)

**Problema**
El service retorna `object|string` en `uploadDocument`, `bool` en `deleteDocument`, `?string` en `handleProfileImage`. CLAUDE.md exige `ServiceResult::ok($data) / ::fail($errors)`. El controller debe hacer `is_string($result)` para distinguir éxito/error, lo que rompe consistencia con `InvoicePaymentService`, `InvoiceApprovalService`, etc.

**Plan de resolución**
1. Mapear cada método del service a `ServiceResult`:
   - `uploadDocument` → `ServiceResult::ok(EmployeeDocument)` / `ServiceResult::fail(['file' => 'mensaje'])`.
   - `deleteDocument` → `ServiceResult::ok(null)` / `ServiceResult::fail(['delete' => 'mensaje'])`.
   - `handleProfileImage` → `ServiceResult::ok(null)` / `ServiceResult::fail([...])`. Si el contrato es "no había archivo y eso es OK", retornar `ServiceResult::ok(null)` con bandera `data['skipped'] = true`.
   - `createDefaultFolders` → `ServiceResult::ok(int $count)` / `ServiceResult::fail([...])`.
2. Adaptar `EmployeesController` para verificar `->success` antes de leer `->data`. Reemplazar `is_string($result)` por `if (!$result->success) { Flash::error($result->errors[0]) }`.
3. Coordinar con CR-008 (refactor de `handleProfileImage`).
4. Validación manual: ejercitar upload/delete/add con casos de éxito y error → mensajes Flash deben aparecer correctos en ambos.

**Archivos a tocar:** `src/Service/EmployeeDocumentService.php`, `src/Controller/EmployeesController.php`.

---

### CR-011 — `EmployeesTable` instancia services con `new` dentro de callbacks

**Categoría:** Architecture · DI violation
**Ubicación:** `src/Model/Table/EmployeesTable.php:367, 378` (callbacks `onExcelImportCreated` / `onExcelImportUpdated`)

**Problema**
`new EmployeeDocumentService()` y `new EmployeeHistoryService()` dentro de la Table rompen la dirección de dependencias (Service → Table, no al revés) y el patrón DI del proyecto. Además, `EmployeeDocumentService` hace efectos en filesystem (mkdir, mover archivos) durante un import masivo que debería ser puro DB.

**Plan de resolución**
1. Identificar quién llama estos callbacks: probablemente `ExcelWizardTrait` o el controller. Pasar los services al callback como parámetros (closure binding) o como propiedades inyectadas en el trait.
2. Refactor preferido (más invasivo): convertir los callbacks a eventos `Model.afterSave` con un listener registrado por el controller con services inyectados. Reduce acoplamiento.
3. Refactor mínimo (pragmático): en `EmployeesTable`, exponer setters opcionales (`setDocumentService`, `setHistoryService`) que el controller llene antes de invocar `runImport`. Si están null, fallback a `new` (conservar compat).
4. Documentar en el header del archivo por qué los services se acceden así.

**Archivos a tocar:** `src/Model/Table/EmployeesTable.php`, `src/Controller/EmployeesController.php` (o donde se dispara el import wizard), posible trait `ExcelWizardTrait`.

---

## 🟡 Minor

### CR-012 — Magic strings en templates

**Categoría:** Code Smell
**Ubicación:** `templates/Employees/add.php` y `edit.php` (tipo de documento, género, contrato)

**Plan de resolución**
1. Crear `src/Constants/DocumentTypeConstants.php` con `CC, CE, TI, PP, NIT` y `LABELS`.
2. Crear `src/Constants/GenderConstants.php` con `MALE, FEMALE, OTHER` y `LABELS` en español.
3. Verificar si `ContractTypeConstants` ya tiene una constante para `'OBRA O LABOR DETERMINADA'` — si no, añadirla.
4. Reemplazar arrays inline en los templates por `select($field, FooConstants::LABELS, ...)`.
5. Reusar las constantes en validation rules de `EmployeesTable` para evitar drift.

**Archivos a tocar:** dos archivos nuevos en `src/Constants/`, `templates/Employees/add.php`, `templates/Employees/edit.php`, `src/Model/Table/EmployeesTable.php` (si reusa constantes en validación).

---

### CR-013 — Lógica duplicada entre controller y finder

**Categoría:** Code Smell
**Ubicación:** `EmployeesController.php:92-104` vs `EmployeesTable::findWithCurrentNovelty`

**Plan de resolución**
Resuelve junto con CR-007 (extraer `findCurrentNovelty` finder reusable). Una vez exista, `view()` lo invoca en lugar de duplicar la cláusula.

---

### CR-014 — Inserts uno-a-uno en `createDefaultFolders`

**Categoría:** Performance
**Ubicación:** `src/Service/EmployeeDocumentService.php:156-163`

**Plan de resolución**
1. Construir array completo de entities con `newEntities([...])` o map sobre `$defaults`.
2. `$foldersTable->saveMany($entities, ['atomic' => true])` en una sola transacción.
3. Verificar resultado y retornar `ServiceResult` (CR-006 y CR-010).

**Archivos a tocar:** `src/Service/EmployeeDocumentService.php`.

---

### CR-015 — N×M de queries en chart de tipos de contrato

**Categoría:** Performance
**Ubicación:** `src/Service/Dashboard/EmployeeStatisticsService.php:172-180`

**Plan de resolución**
1. Sustituir el loop por una sola query: `Employees->find()->select(['contract_type', 'count' => $q->func()->count('*')])->group('contract_type')->where(['status' => 'activo'])->toArray()`.
2. Mapear el resultado a un array indexado por `contract_type` y rellenar con `0` los tipos definidos en `ContractTypeConstants::ALL` que no aparezcan.
3. Validar que el chart sigue mostrando los mismos números que antes.

---

### CR-016 — `iterator_to_array` innecesario

**Categoría:** Code Smell
**Ubicación:** `templates/Employees/index.php:91`

**Plan de resolución**
Reemplazar `$hasResults = !empty(iterator_to_array($employees))` por `$hasResults = count($employees) > 0;` (o evaluar directamente en el `if`/`foreach`). El `ResultSet` de CakePHP soporta `count()` sin materializar.

---

### CR-017 — Entity `Employee` anémica

**Categoría:** DDD · Encapsulation
**Ubicación:** `src/Model/Entity/Employee.php`

**Plan de resolución**
Añadir métodos de dominio sin cambiar el storage:
1. `isRetired(): bool` → `return $this->status === EmployeeStatusConstants::STATUS_RETIRED;`.
2. `isActive(): bool`.
3. `requiresTemporaryOrg(): bool` → encapsula la lógica del JS toggle (`contract_type === ContractTypeConstants::OBRA_LABOR`).
4. `hasActiveNoveltyToday(): bool` → reusa CR-009.
5. Reemplazar comparaciones a constantes en services y templates por estos métodos.

---

### CR-018 — `EmployeeDocumentService` viola SRP

**Categoría:** SOLID · SRP
**Ubicación:** `src/Service/EmployeeDocumentService.php`

**Plan de resolución**
Considerar separar en 3 services con foco único:
1. `EmployeeDocumentUploader` — `upload`, `delete`, `deleteEmployeeFiles`.
2. `EmployeeProfileImageService` — `handle` (única responsabilidad).
3. `EmployeeFolderInitializer` — `createDefaults`.

Coordinar con CR-010 (todos retornan `ServiceResult`). Si el costo del split es alto, dejar como nota/sugerencia y abordar cuando crezca el service.

---

### CR-019 — 5 closures de iconografía en view

**Categoría:** Readability · DRY
**Ubicación:** `templates/Employees/view.php:17-48`

**Plan de resolución**
Crear `src/View/Helper/DocumentIconHelper.php` con métodos `iconClass(string $mime): string`, `colorClass(string $mime)`, `typeLabel(string $mime)`, `badgeClass(string $mime)`. Cargar el helper en `AppView` y reemplazar los closures por `$this->DocumentIcon->iconClass($mime)`. Reusable también por Invoices y Novelties.

---

### CR-020 — `add.php` y `edit.php` duplicados

**Categoría:** DRY · Maintainability
**Ubicación:** `templates/Employees/add.php`, `templates/Employees/edit.php`

**Plan de resolución**
1. Crear `templates/Employees/_form.php` con todo el contenido común (cards de Datos Personales, Contacto, Datos Laborales, Prestacional).
2. `add.php` y `edit.php` quedan reducidos a wrapping (título, breadcrumb, `Form->create`, `<?= $this->element('Employees/form'); ?>`, botones, `Form->end`).
3. Si hay diferencias menores entre add y edit (p.ej. un readonly), pasar variables al element vía `$this->element('Employees/form', ['mode' => 'add'])`.
4. Validación manual: ambos formularios deben renderizar y guardar igual que antes.

---

### CR-021 — JS toggle inline duplicado

**Plan de resolución**
1. Mover el código JS de toggle del wrapper de `temporary_organization_id` a `webroot/js/employees-form.js`.
2. Cargarlo solo en `add` y `edit` (con `$this->Html->script('employees-form', ['block' => 'scriptBottom'])` dentro del element o en cada vista).
3. Bonus: parametrizar el selector y exponer como helper si surgen patrones similares en otros módulos.

---

### CR-022 — Catálogos sin caché en `_setFormDropdowns`

**Plan de resolución**
1. Identificar catálogos estables (cambian rara vez): `MaritalStatuses`, `EducationLevels`, posiblemente `OperationCenters`, `CostCenters`, `Positions`.
2. Envolver cada `find()` en `Cache::remember('employees.dropdown.marital', fn() => ..., 'short')` con TTL configurado en `config/app.php` (5-15 min).
3. Disparar invalidación cuando se modifique cualquier catálogo: hook `afterSave`/`afterDelete` en cada Table → `Cache::delete('employees.dropdown.X')`.
4. Validación manual: cargar `add` 2 veces seguidas → 2da carga debe hacer 0 queries de catálogos. Editar un catálogo → caché invalidada.

---

### CR-023 — Inconsistencia de orden en contains

**Plan de resolución**
1. Confirmar con UI: ¿el chat de observaciones se lee cronológicamente (ASC) y el historial cronológicamente inverso (DESC)? Probablemente sí.
2. Si la inconsistencia es intencional → añadir comentario `// chat ASC: lectura natural; histories DESC: cambios recientes primero`.
3. Si no → unificar.

---

## 🟢 Sugerencias

### CR-024 — VO `SocialSecurityInfo`
Agrupar `eps`, `pension_fund`, `arl`, `severance_fund`, `vest_number` en un VO inmutable. Útil para mostrar secciones, exportar Excel, validar consistencia.

### CR-025 — VO `Identification`
`document_type` + `document_number` viajan juntos siempre. VO con métodos `formatted()`, `equals()` y validación de formato por tipo.

### CR-026 — `BaseFilterService`
Extraer de `EmployeeFilterService` los métodos `applySearch(fields, term)` y `applyExact()` a una clase base reusable por `Invoices`, `Novelties`, `PaymentSchedulings`.

### CR-027 — Combinar AVG en una query
`EmployeeStatisticsService::getExtendedStats:114-126` ejecuta dos `AVG(TIMESTAMPDIFF(...))` separadas. Combinar en un solo `select(['avg_age' => ..., 'avg_tenure' => ...])`.

### CR-028 — Defense-in-depth en uploads
- Rate limit por usuario/empleado (max N uploads/hora).
- Renombrar archivo a la extensión real detectada por `finfo`, no la del cliente.
- (Opcional) escaneo antivirus si el ambiente lo permite (ClamAV).

### CR-029 — DI de `StructuredLogger`
`EmployeeStatisticsService` instancia `new StructuredLogger(...)` directamente. Inyectar opcional con fallback (patrón estándar del SGI).

### CR-030 — `hasManyCount` para totales de documentos
`templates/Employees/view.php:51-57` itera todas las carpetas en PHP para contar documentos. Reemplazar por un finder que precalcule `count(*)` en SQL o un helper que use `subquery`.

---

## Orden sugerido de ejecución

Para un plan secuencial:

1. **Lote 1 — Seguridad (bloqueante):** CR-001, CR-002, CR-003 + CR-004 + CR-010 (los cinco tocan el mismo flujo upload/delete y conviene hacerlos juntos).
2. **Lote 2 — Bugs:** CR-005, CR-006, CR-007, CR-008, CR-009.
3. **Lote 3 — Architecture:** CR-011, CR-018.
4. **Lote 4 — DRY / Code Smells:** CR-012, CR-013, CR-019, CR-020, CR-021, CR-023.
5. **Lote 5 — Performance:** CR-014, CR-015, CR-016, CR-022.
6. **Lote 6 — Encapsulation/DDD:** CR-017, CR-024, CR-025.
7. **Lote 7 — Sugerencias:** CR-026, CR-027, CR-028, CR-029, CR-030.

Cada lote produce una PR independiente con su propia validación manual (recordar: el proyecto no usa tests automatizados; validar levantando `php bin/cake server` y ejercitando los endpoints).

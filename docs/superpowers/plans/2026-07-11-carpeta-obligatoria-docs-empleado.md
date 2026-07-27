# Selección obligatoria de carpeta en el gestor documental del empleado — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el tab "Documentos" de `Employees/view` funcione como un explorador de carpetas real: sin vista "todos los documentos", siempre con una carpeta seleccionada (la primera por defecto), con la carpeta activa en la URL y con todas las acciones (crear carpeta / subir / borrar) devolviendo al usuario a la carpeta afectada.

**Architecture:** Tres capas, en este orden. (1) `EmployeeDocumentService::deleteDocument()` empieza a devolver el `employee_folder_id` del documento borrado en su payload. (2) `EmployeesController::view()` deriva la carpeta activa desde `?folder=` (validada contra las carpetas del empleado, con fallback a la primera) y la pasa a la vista; las tres acciones mutadoras redirigen con `?folder=<id>`. (3) `templates/Employees/view.php` pinta el árbol sin nodo raíz, con las carpetas como enlaces `<a href="?folder=ID">`, el estado inicial (activo / filas ocultas / breadcrumb) resuelto server-side, y un JS que intercepta el clic para filtrar al instante y sincronizar la URL con `pushState`.

**Tech Stack:** CakePHP 5.3 / PHP 8.4, PHPUnit + CakePHP fixture factories, Bootstrap 5, JS vanilla inline en la plantilla.

**Spec:** `docs/superpowers/specs/2026-07-11-carpeta-obligatoria-docs-empleado-design.md`

## Global Constraints

- **Módulo CRUD, no de flujo.** Employees no tiene pipeline, `PipelineStatus`, ViewModel ni `Presentation`. No inventes ninguno de esos artefactos.
- **Sin cambios de esquema.** No hay migración. No toques `config/Migrations/`.
- **Sin cambios de RBAC.** No toques `permissions`, `$controllerModuleMap` ni los atributos `#[Permission]` existentes.
- **Servicios devuelven `ServiceResult`**: `ServiceResult::ok($data)` / `ServiceResult::fail($errors)`. Verificar `->success` antes de usar `->data`.
- **Prefijo CSS del proyecto:** `.spi-`. No introduzcas átomos nuevos del sistema de diseño; este cambio reusa los existentes.
- **Copy en español** con acentos correctos.
- **NO tocar** `src/Service/AssetDocumentService.php` (es una réplica del patrón de `EmployeeDocumentService`; la divergencia tras este cambio es intencional).
- **NO tocar** el select "Carpeta Padre (opcional)" del modal "Nueva Carpeta" (las subcarpetas siguen existiendo), ni el cálculo de "Completitud Documental", ni el footer de totales (siguen siendo globales), ni convertir a `select2` el select del modal de subida (deuda preexistente, fuera de alcance).
- **Correr los tests con `vendor/bin/phpunit` directo** (NO `composer test`), timeout de 300s. La suite sale con exit code 1 aun estando verde por *notices* preexistentes: juzga por el conteo de failures/errors, no por el exit code.
- **Estilo de código:** `composer cs-check` debe quedar verde (usar `composer cs-fix` si hace falta).

---

### Task 1: `deleteDocument()` devuelve la carpeta del documento borrado

Hoy `EmployeeDocumentService::deleteDocument()` retorna `ServiceResult::ok()` sin payload, así que el controller no sabe a qué carpeta devolver al usuario tras borrar. La entidad ya está cargada dentro del método (vía `assertDocumentOwnership`), así que exponer su `employee_folder_id` no cuesta ninguna consulta extra. Este método **no tiene ningún test hoy** (los 5 existentes cubren `uploadDocuments`).

**Files:**
- Modify: `src/Service/EmployeeDocumentService.php:286-306` (método `deleteDocument`)
- Test: `tests/TestCase/Service/EmployeeDocumentServiceTest.php` (añadir un test al final de la clase)

**Interfaces:**
- Consumes: nada (primera tarea).
- Produces: `EmployeeDocumentService::deleteDocument(int $employeeId, int $documentId): ServiceResult` — en éxito, `$result->data` pasa de `null` a `['employee_folder_id' => int]`. La Task 2 lo consume desde `EmployeesController::deleteDocument()`.

- [ ] **Step 1: Escribir el test que falla**

En `tests/TestCase/Service/EmployeeDocumentServiceTest.php`, añadir este método justo antes del `}` que cierra la clase (después de `testUploadDocumentsInvalidFolderFailsBatch`):

```php
    public function testDeleteDocumentReturnsFolderIdInPayload(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $folder = $service->createFolder($employee->id, 'Contratos', null)->data;
        $service->uploadDocuments($employee->id, $folder->id, [$this->makePdfUpload('a.pdf')], null);
        $document = $this->fetchTable('EmployeeDocuments')->find()->firstOrFail();

        $result = $service->deleteDocument((int)$employee->id, (int)$document->id);

        $this->assertTrue($result->success);
        $this->assertSame((int)$folder->id, $result->data['employee_folder_id']);
        $this->assertCount(0, $this->fetchTable('EmployeeDocuments')->find()->all()->toArray());
    }
```

(No hace falta registrar el path en `$this->createdPaths`: `deleteDocument()` borra el archivo físico.)

- [ ] **Step 2: Correr el test y verificar que falla**

```bash
vendor/bin/phpunit --filter testDeleteDocumentReturnsFolderIdInPayload tests/TestCase/Service/EmployeeDocumentServiceTest.php
```

Esperado: FAIL — `Trying to access array offset on value of type null` o `assertSame` fallando, porque `$result->data` es `null`.

- [ ] **Step 3: Implementar**

En `src/Service/EmployeeDocumentService.php`, dentro de `deleteDocument()`, reemplazar la línea final:

```php
        return ServiceResult::ok();
```

por:

```php
        return ServiceResult::ok(['employee_folder_id' => (int)$document->employee_folder_id]);
```

No cambies nada más del método: el `assertDocumentOwnership`, el `delete()` de la tabla y el `@unlink` se quedan igual.

- [ ] **Step 4: Correr el test y verificar que pasa**

```bash
vendor/bin/phpunit tests/TestCase/Service/EmployeeDocumentServiceTest.php
```

Esperado: los 6 tests (5 previos + el nuevo) en verde, `OK` o `Failures: 0, Errors: 0`.

- [ ] **Step 5: Estilo de código**

```bash
composer cs-check
```

Esperado: sin errores. Si los hay, `composer cs-fix` y volver a correr.

- [ ] **Step 6: Commit**

```bash
git add src/Service/EmployeeDocumentService.php tests/TestCase/Service/EmployeeDocumentServiceTest.php
git commit -m "feat: deleteDocument devuelve el employee_folder_id en el payload"
```

---

### Task 2: Carpeta activa en `view()` y redirects con `?folder=`

`EmployeesController::view()` pasa a derivar la carpeta activa y a exponerla a la plantilla, y las tres acciones mutadoras (`addFolder`, `uploadDocument`, `deleteDocument`) redirigen con `?folder=<id>` para no expulsar al usuario a la primera carpeta.

**Punto crítico — tipos.** El query string siempre llega como `string`; los ids de BD son `int`. Con comparación estricta, `in_array('7', [5, 7, 9], true)` es **`false`**. Por eso el aplanado castea los ids a `string` y el valor del query también: si te saltas esto, `?folder=` queda como código muerto y toda la tarea no sirve para nada.

El aplanado de `$folders` debe recorrer el árbol **en el mismo orden en que la plantilla lo pinta** (carpeta raíz, sus hijas, siguiente carpeta raíz…), para que el primer id del array sea siempre el primer ítem pintado. No añadas `sort` al `contain` ni reordenes nada.

**Files:**
- Modify: `src/Controller/EmployeesController.php` — `view()` (líneas ~108-152), `addFolder()` (~231-250), `uploadDocument()` (~253-288), `deleteDocument()` (~291-304)
- Create: `tests/TestCase/Controller/EmployeesFolderSelectionTest.php`

**Interfaces:**
- Consumes: `EmployeeDocumentService::deleteDocument()` devolviendo `['employee_folder_id' => int]` (Task 1). También `EmployeeDocumentService::createFolder()`, que **ya** devuelve `ServiceResult::ok($folder)` con la entidad `EmployeeFolder`.
- Produces: dos variables de vista nuevas que consume la Task 3:
  - `$selectedFolderId` (`string|null`) — id de la carpeta activa, ya validado. `null` sólo si el empleado no tiene ninguna carpeta.
  - `$selectedFolderName` (`string`) — nombre de esa carpeta; `''` si no hay carpetas.

- [ ] **Step 1: Escribir los tests que fallan**

Crear `tests/TestCase/Controller/EmployeesFolderSelectionTest.php` con este contenido completo:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Service\EmployeeDocumentService;
use App\Test\Factory\EmployeeFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\UploadedFile;

final class EmployeesFolderSelectionTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Loguea un usuario con permisos completos sobre el módulo employees.
     */
    private function login(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'employees',
            'can_view' => true,
            'can_create' => true,
            'can_edit' => true,
            'can_delete' => true,
        ]));
        $this->session(['Auth' => UserFactory::new(['role_id' => $role->id])->save()]);
    }

    private function service(): EmployeeDocumentService
    {
        return new EmployeeDocumentService();
    }

    private function makePdfUpload(string $name = 'a.pdf'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'empdoc');
        file_put_contents($tmp, "%PDF-1.4\n%minimal\n");

        return new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, $name, 'application/pdf');
    }

    public function testViewAutoselectsFirstFolder(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        // El orderBy de view() es por nombre ASC, asi que 'Antecedentes' va primero
        // aunque se cree despues.
        $service->createFolder($employee->id, 'Contratos', null);
        $primera = $service->createFolder($employee->id, 'Antecedentes', null)->data;

        $this->login();
        $this->get('/employees/view/' . $employee->id);

        $this->assertResponseOk();
        $this->assertSame((string)$primera->id, $this->viewVariable('selectedFolderId'));
        $this->assertSame('Antecedentes', $this->viewVariable('selectedFolderName'));
    }

    public function testViewHonorsValidFolderQuery(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $service->createFolder($employee->id, 'Antecedentes', null);
        $contratos = $service->createFolder($employee->id, 'Contratos', null)->data;

        $this->login();
        $this->get('/employees/view/' . $employee->id . '?folder=' . $contratos->id);

        $this->assertResponseOk();
        $this->assertSame((string)$contratos->id, $this->viewVariable('selectedFolderId'));
        $this->assertSame('Contratos', $this->viewVariable('selectedFolderName'));
    }

    public function testViewFallsBackToFirstFolderWhenQueryIsForeign(): void
    {
        $employee = EmployeeFactory::new()->save();
        $otro = EmployeeFactory::new()->save();
        $service = $this->service();
        $primera = $service->createFolder($employee->id, 'Antecedentes', null)->data;
        $ajena = $service->createFolder($otro->id, 'Ajena', null)->data;

        $this->login();
        $this->get('/employees/view/' . $employee->id . '?folder=' . $ajena->id);

        $this->assertResponseOk();
        $this->assertSame((string)$primera->id, $this->viewVariable('selectedFolderId'));
    }

    public function testViewFallsBackToFirstFolderWhenQueryIsNotScalar(): void
    {
        $employee = EmployeeFactory::new()->save();
        $primera = $this->service()->createFolder($employee->id, 'Antecedentes', null)->data;

        $this->login();
        $this->get('/employees/view/' . $employee->id . '?folder[]=1');

        $this->assertResponseOk();
        $this->assertSame((string)$primera->id, $this->viewVariable('selectedFolderId'));
    }

    public function testDeleteDocumentRedirectsToItsFolder(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $service->createFolder($employee->id, 'Antecedentes', null);
        $contratos = $service->createFolder($employee->id, 'Contratos', null)->data;
        $service->uploadDocuments($employee->id, $contratos->id, [$this->makePdfUpload()], null);
        $document = TableRegistry::getTableLocator()->get('EmployeeDocuments')->find()->firstOrFail();

        $this->login();
        $this->enableCsrfToken();
        $this->post('/employees/delete-document/' . $employee->id . '/' . $document->id);

        $this->assertResponseCode(302);
        $this->assertRedirectContains('/employees/view/' . $employee->id);
        $this->assertRedirectContains('folder=' . $contratos->id);
    }

    public function testAddFolderRedirectsToTheNewFolder(): void
    {
        $employee = EmployeeFactory::new()->save();
        $this->service()->createFolder($employee->id, 'Antecedentes', null);

        $this->login();
        $this->enableCsrfToken();
        $this->post('/employees/add-folder/' . $employee->id, ['name' => 'Vacaciones']);

        $nueva = TableRegistry::getTableLocator()->get('EmployeeFolders')
            ->find()->where(['name' => 'Vacaciones'])->firstOrFail();

        $this->assertResponseCode(302);
        $this->assertRedirectContains('folder=' . $nueva->id);
    }
}
```

- [ ] **Step 2: Correr los tests y verificar que fallan**

```bash
vendor/bin/phpunit tests/TestCase/Controller/EmployeesFolderSelectionTest.php
```

Esperado: los 6 tests fallan. Los 4 de `view` porque `selectedFolderId` / `selectedFolderName` no existen como variables de vista (`viewVariable()` devuelve `null`); los 2 de redirect porque el `Location` no contiene `folder=`.

- [ ] **Step 3: Derivar la carpeta activa en `view()`**

En `src/Controller/EmployeesController.php`, dentro de `view()`, **después** del bloque que construye `$folders` (el `find()` que termina en `->all();`, hoy líneas 108-112) y **antes** del comentario de `$currentNovelty`, insertar:

```php
        // ─── Carpeta activa del gestor documental ───────────────────────────
        // El aplanado recorre $folders en el MISMO orden en que la vista pinta el
        // árbol (raíz, sus hijas, siguiente raíz), para que el primer id del array
        // sea siempre el primer ítem pintado.
        $folderIds = [];
        $folderNames = [];
        foreach ($folders as $folder) {
            $folderIds[] = (string)$folder->id;
            $folderNames[(string)$folder->id] = $folder->name;
            foreach ($folder->child_folders as $childFolder) {
                $folderIds[] = (string)$childFolder->id;
                $folderNames[(string)$childFolder->id] = $childFolder->name;
            }
        }

        // El id que llega por ?folder= no se confía: se valida contra las carpetas
        // del empleado y cae a la primera si no coincide. Ambos lados son string
        // porque el query llega como string y los ids son int: con comparación
        // estricta, in_array('7', [7], true) sería false.
        $requestedFolder = $this->request->getQuery('folder');
        $requestedFolder = is_scalar($requestedFolder) ? (string)$requestedFolder : '';
        $selectedFolderId = in_array($requestedFolder, $folderIds, true)
            ? $requestedFolder
            : ($folderIds[0] ?? null);
        $selectedFolderName = $selectedFolderId !== null ? $folderNames[$selectedFolderId] : '';
```

Y añadir las dos variables al `compact()` del `set()` que ya existe al final del método:

```php
        $this->set(compact(
            'employee',
            'folders',
            'selectedFolderId',
            'selectedFolderName',
            'currentNovelty',
            'navEmployees',
            'navStatus',
            'navSearch',
            'navPositionId',
            'navOperationCenterId',
            'positions',
            'operationCenters',
        ));
```

- [ ] **Step 4: Redirigir a la carpeta afectada en las tres acciones mutadoras**

En el mismo archivo:

**`addFolder()`** — reemplazar el bloque final (`if (!$result->success) { … } else { … }` + el `return`) por:

```php
        if (!$result->success) {
            $this->Flash->error(__($result->firstError() ?? 'No se pudo crear la carpeta.'));

            return $this->redirect(['action' => 'view', $employeeId]);
        }

        $this->Flash->success(__('La carpeta ha sido creada.'));

        return $this->redirect(['action' => 'view', $employeeId, '?' => ['folder' => $result->data->id]]);
```

**`uploadDocument()`** — extraer la carpeta destino a una variable y usarla en el redirect de éxito. El método queda así desde la llamada al servicio:

```php
        $identity = $this->Authentication->getIdentity();
        $folderId = (int)$this->request->getData('employee_folder_id');
        $result = $this->documentService->uploadDocuments(
            (int)$employeeId,
            $folderId,
            $files,
            $identity ? (int)$identity->getIdentifier() : null,
        );

        if (!$result->success) {
            $this->Flash->error(__($result->firstError() ?? 'No se pudo subir el documento.'));

            return $this->redirect(['action' => 'view', $employeeId]);
        }

        $data = $result->data;
        if ($data['uploaded'] >= 1) {
            $this->Flash->success(__n('{0} documento subido.', '{0} documentos subidos.', $data['uploaded'], $data['uploaded']));
        }
        if (!empty($data['failed'])) {
            $lines = array_map(
                fn($f) => $f['name'] . ' (' . $f['error'] . ')',
                $data['failed'],
            );
            $this->Flash->error(__('No se pudieron subir: {0}', implode(', ', $lines)));
        }

        return $this->redirect(['action' => 'view', $employeeId, '?' => ['folder' => $folderId]]);
```

**`deleteDocument()`** — reemplazar el bloque final por:

```php
        $result = $this->documentService->deleteDocument((int)$employeeId, (int)$documentId);
        if (!$result->success) {
            $this->Flash->error(__($result->firstError() ?? 'No se pudo eliminar el documento.'));

            return $this->redirect(['action' => 'view', $employeeId]);
        }

        $this->Flash->success(__('El documento ha sido eliminado.'));

        return $this->redirect([
            'action' => 'view',
            $employeeId,
            '?' => ['folder' => $result->data['employee_folder_id']],
        ]);
```

- [ ] **Step 5: Correr los tests y verificar que pasan**

```bash
vendor/bin/phpunit tests/TestCase/Controller/EmployeesFolderSelectionTest.php tests/TestCase/Controller/EmployeesDocumentUploadTest.php tests/TestCase/Service/EmployeeDocumentServiceTest.php
```

Esperado: todo en verde (`Failures: 0, Errors: 0`). `EmployeesDocumentUploadTest` cubre la rama de fallo del upload, que sigue redirigiendo **sin** `?folder=`, así que debe seguir pasando sin tocarla.

- [ ] **Step 6: Estilo de código**

```bash
composer cs-check
```

Esperado: sin errores. Si los hay, `composer cs-fix` y volver a correr.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/EmployeesController.php tests/TestCase/Controller/EmployeesFolderSelectionTest.php
git commit -m "feat: carpeta activa en la vista de empleado y redirects con ?folder="
```

---

### Task 3: Árbol sin nodo raíz, con enlaces y estado inicial server-side

Toda la UI. El HTML renderizado se cubre con tres aserciones de integración (el proyecto ya tiene precedente: `tests/TestCase/Controller/AdvancesLegalizationRenderTest.php`); el comportamiento interactivo (JS) sólo se puede verificar a mano, y ese checklist está al final.

**Trampa visual a evitar:** hoy los ítems del árbol llevan `color:var(--text-default);font-weight:500;` **en el atributo `style` inline**, que gana por especificidad sobre la regla `.doc-tree-item.is-active { color: …; font-weight: 700; }`. Con el nodo raíz eso no se notaba (el raíz no tenía color inline y por eso salía verde y en negrita, como en la captura). Si dejas el color y el peso inline en las carpetas, la carpeta activa saldrá con fondo verde pero con el texto **gris y sin negrita**. Por eso `color` y `font-weight` se mueven del `style` inline al bloque `<style>`.

**Trampa de DOM a evitar:** el `<script>` del gestor documental vive en la línea ~635, y el modal `#uploadDocModal` (con el `<select id="upload-folder-select">`) está más abajo, en la línea ~952. Un `<script>` inline se ejecuta durante el parseo, así que en ese momento **el modal todavía no existe**: un `document.getElementById('upload-folder-select')` al arrancar el IIFE devuelve `null` para siempre y la sincronización del select queda muerta. Por eso el select se resuelve **dentro** de `selectFolder()`, en cada llamada.

**Files:**
- Modify: `templates/Employees/view.php` — docblock (~líneas 6-18), cálculo de `$allDocs` (~30-46), toolbar/breadcrumb (~490-492), bloque árbol + lista (~502-632), JS inline (~635-663), modal de subida (~971)
- Test: `tests/TestCase/Controller/EmployeesFolderSelectionTest.php` (añadir 3 tests de render a la clase creada en la Task 2)

**Interfaces:**
- Consumes: `$selectedFolderId` (`string|null`) y `$selectedFolderName` (`string`) de la Task 2. También `$navBaseQuery` y `$navStatus`, que **ya existen** en la plantilla (línea 74 y variable de vista).
- Produces: nada (capa final).

- [ ] **Step 1: Escribir los tests de render que fallan**

Añadir estos tres tests a `tests/TestCase/Controller/EmployeesFolderSelectionTest.php` (la clase creada en la Task 2), antes del `}` que la cierra:

```php
    public function testViewNoLongerRendersTheSyntheticRootNode(): void
    {
        $employee = EmployeeFactory::new()->save();
        $this->service()->createFolder($employee->id, 'Antecedentes', null);

        $this->login();
        $this->get('/employees/view/' . $employee->id);

        $this->assertResponseOk();
        $this->assertResponseNotContains('data-folder-id="all"');
    }

    public function testViewMarksTheSelectedFolderAsActive(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $primera = $service->createFolder($employee->id, 'Antecedentes', null)->data;
        $contratos = $service->createFolder($employee->id, 'Contratos', null)->data;

        $this->login();
        $this->get('/employees/view/' . $employee->id . '?folder=' . $contratos->id);

        $this->assertResponseOk();
        $body = (string)$this->_response->getBody();
        // El ancla de la carpeta pedida es la activa...
        $this->assertMatchesRegularExpression(
            '/class="doc-tree-item is-active"[^>]*data-folder-id="' . $contratos->id . '"/s',
            $body,
        );
        // ...y la primera carpeta NO lo es.
        $this->assertDoesNotMatchRegularExpression(
            '/class="doc-tree-item is-active"[^>]*data-folder-id="' . $primera->id . '"/s',
            $body,
        );
    }

    public function testUploadModalPreselectsTheActiveFolder(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $service->createFolder($employee->id, 'Antecedentes', null);
        $contratos = $service->createFolder($employee->id, 'Contratos', null)->data;

        $this->login();
        $this->get('/employees/view/' . $employee->id . '?folder=' . $contratos->id);

        $this->assertResponseOk();
        $this->assertResponseContains('value="' . $contratos->id . '" selected="selected"');
    }
```

Los regex de `testViewMarksTheSelectedFolderAsActive` dependen de que el ancla del árbol emita `class="doc-tree-item is-active"` y `data-folder-id` en ese orden — es exactamente el markup del Step 5. `testUploadModalPreselectsTheActiveFolder` espera `selected="selected"`, que es cómo CakePHP 5 renderiza la opción seleccionada de un `<select>`.

- [ ] **Step 2: Correr los tests y verificar que fallan**

```bash
vendor/bin/phpunit tests/TestCase/Controller/EmployeesFolderSelectionTest.php
```

Esperado: los 3 tests nuevos fallan (la plantilla vieja aún pinta `data-folder-id="all"`, no marca la carpeta pedida como activa y no preselecciona el select). Los 6 de la Task 2 siguen verdes.

- [ ] **Step 3: Documentar las variables nuevas y limpiar el huérfano `folderName`**

En el docblock de cabecera de `templates/Employees/view.php`, añadir bajo `@var iterable $folders`:

```php
 * @var string|null $selectedFolderId
 * @var string $selectedFolderName
```

Y reemplazar el bloque de cálculo de documentos (hoy líneas 30-46) por:

```php
// ─── Conteo de documentos + lista plana para el navegador docs ──────
// $allDocs aplana carpetas y subcarpetas en filas {doc, folderId} para renderizar
// una única lista filtrable del lado del cliente. $selectedDocCount es cuántos
// documentos tiene la carpeta activa (decide el empty-state inicial, server-side).
$totalDocs = 0;
$allDocs = [];
foreach ($folders as $folder) {
    foreach ($folder->employee_documents as $doc) {
        $allDocs[] = ['doc' => $doc, 'folderId' => (string)$folder->id];
    }
    $totalDocs += count($folder->employee_documents);
    foreach ($folder->child_folders as $sf) {
        foreach ($sf->employee_documents as $doc) {
            $allDocs[] = ['doc' => $doc, 'folderId' => (string)$sf->id];
        }
        $totalDocs += count($sf->employee_documents);
    }
}
$selectedDocCount = count(array_filter(
    $allDocs,
    fn(array $row) => $row['folderId'] === $selectedFolderId,
));
```

La clave `folderName` desaparece: su único consumidor era la columna "Carpeta" de la tabla, que este cambio elimina.

- [ ] **Step 4: Breadcrumb del toolbar**

En el toolbar de la card de documentos (hoy líneas 490-492), reemplazar:

```php
                            <div class="mono" style="font-size:11.5px;color:var(--text-faint);">
                                / <?= h($employee->first_name) ?>
                            </div>
```

por:

```php
                            <div class="mono" style="font-size:11.5px;color:var(--text-faint);">
                                / <span id="docCrumb"><?= h($selectedFolderName) ?></span>
                            </div>
```

- [ ] **Step 5: Árbol de carpetas — sin nodo raíz y con enlaces**

Reemplazar el bloque `<style>` + el árbol (hoy líneas 503-558, desde `<style>` hasta el `</div>` que cierra `#docTree`) por:

```php
                        <style>
                            .doc-tree-item { transition: background var(--t-fast) ease; text-decoration: none;
                                             color: var(--text-default); font-weight: 500; }
                            .doc-tree-item.is-child { color: var(--text-muted); }
                            .doc-tree-item:hover { background: var(--bg-muted); }
                            .doc-tree-item.is-active { background: var(--primary-soft); color: var(--primary-color); font-weight: 700; }
                            .doc-row { display: grid; grid-template-columns: 2fr 0.8fr 1fr 96px; gap: 12px;
                                       align-items: center; padding: 11px 18px; border-bottom: 1px solid var(--rule);
                                       font-size: 12px; transition: background var(--t-fast) ease; }
                            .doc-row:hover { background: var(--bg-subtle); }
                            .doc-row.is-hidden { display: none; }
                        </style>
                        <!-- ── Árbol de carpetas (izquierda) ── -->
                        <div id="docTree" style="background:var(--bg-subtle);padding:12px 0;
                                    border-right:1px solid var(--rule);font-size:12px;">
                            <?php foreach ($folders as $folder): ?>
                            <a class="doc-tree-item<?= (string)$folder->id === $selectedFolderId ? ' is-active' : '' ?>"
                               href="<?= $this->Url->build(['action' => 'view', $employee->id, '?' => $navBaseQuery + ['status' => $navStatus, 'folder' => $folder->id]]) ?>"
                               data-folder-id="<?= $folder->id ?>"
                               data-folder-name="<?= h($folder->name) ?>"
                               <?= (string)$folder->id === $selectedFolderId ? 'aria-current="true"' : '' ?>
                               style="display:flex;align-items:center;gap:8px;padding:7px 14px 7px 24px;">
                                <i class="bi bi-folder" style="font-size:13px;color:var(--secondary-color);flex-shrink:0;" aria-hidden="true"></i>
                                <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= h($folder->name) ?>
                                </span>
                                <span class="mono" style="font-size:9.5px;color:var(--text-faint);font-weight:600;flex-shrink:0;">
                                    <?= count($folder->employee_documents) ?>
                                </span>
                            </a>
                            <?php foreach ($folder->child_folders as $subfolder): ?>
                            <a class="doc-tree-item is-child<?= (string)$subfolder->id === $selectedFolderId ? ' is-active' : '' ?>"
                               href="<?= $this->Url->build(['action' => 'view', $employee->id, '?' => $navBaseQuery + ['status' => $navStatus, 'folder' => $subfolder->id]]) ?>"
                               data-folder-id="<?= $subfolder->id ?>"
                               data-folder-name="<?= h($subfolder->name) ?>"
                               <?= (string)$subfolder->id === $selectedFolderId ? 'aria-current="true"' : '' ?>
                               style="display:flex;align-items:center;gap:8px;padding:6px 14px 6px 38px;">
                                <i class="bi bi-folder" style="font-size:12px;color:var(--secondary-color);flex-shrink:0;" aria-hidden="true"></i>
                                <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= h($subfolder->name) ?>
                                </span>
                                <span class="mono" style="font-size:9.5px;color:var(--text-faint);font-weight:600;flex-shrink:0;">
                                    <?= count($subfolder->employee_documents) ?>
                                </span>
                            </a>
                            <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
```

Lo que desaparece: el bloque `<div class="doc-tree-item is-active" data-folder-id="all">` completo. Lo que cambia: `<div>` → `<a href>` (navegable con teclado, con la carpeta en la URL, y funcional aunque el JS no cargue), `color`/`font-weight` fuera del `style` inline, y `data-folder-name` como hook del breadcrumb (un `textContent` del ítem devolvería `"CONTRATO 3"` porque el ítem también contiene el contador).

El `$navBaseQuery + ['status' => $navStatus, …]` preserva los filtros del directorio izquierdo (`search`, `position_id`, `operation_center_id`, tab de estado) al cambiar de carpeta — es el mismo patrón que ya usan los enlaces del directorio en la línea 235.

- [ ] **Step 6: Lista de archivos — sin columna "Carpeta" y con las filas ocultas ya desde el servidor**

En el header de columnas (hoy líneas 563-572), cambiar el grid a 4 columnas y quitar el `<span>Carpeta</span>`:

```php
                            <div style="display:grid;grid-template-columns:2fr 0.8fr 1fr 96px;padding:10px 18px;
                                        background:var(--bg-muted);font-size:9.5px;font-weight:700;
                                        color:var(--text-faint);letter-spacing:0.7px;text-transform:uppercase;
                                        gap:12px;align-items:center;border-bottom:1px solid var(--rule);">
                                <span>Documento</span>
                                <span>Tamaño</span>
                                <span>Cargado</span>
                                <span style="text-align:right;">Acciones</span>
                            </div>
```

En el `foreach` de filas (hoy líneas 575-614), abrir la fila con la clase `is-hidden` ya calculada y eliminar la celda de carpeta:

```php
                                <?php foreach ($allDocs as $row):
                                    $doc = $row['doc'];
                                    $isVisible = $row['folderId'] === $selectedFolderId;
                                ?>
                                <div class="doc-row<?= $isVisible ? '' : ' is-hidden' ?>" data-folder-id="<?= h($row['folderId']) ?>">
                                    <span style="min-width:0;display:flex;align-items:center;gap:7px;overflow:hidden;">
                                        <i class="bi <?= h($this->DocumentIcon->iconClass($doc->mime_type)) ?>"
                                           style="color:<?= h($this->DocumentIcon->iconColor($doc->mime_type)) ?>;font-size:1.15rem;flex-shrink:0;"></i>
                                        <?= $this->Html->link(
                                            h($doc->name),
                                            ['action' => 'downloadDocument', $employee->id, $doc->id],
                                            ['target' => '_blank', 'class' => 'text-decoration-none text-truncate']
                                        ) ?>
                                    </span>
                                    <span style="color:var(--text-faint);font-size:.8rem;">
                                        <?= $doc->file_size ? $this->Number->toReadableSize($doc->file_size) : '—' ?>
                                    </span>
                                    <span style="color:var(--text-faint);font-size:.8rem;line-height:1.35;">
                                        <?= $doc->has('uploaded_by_user') ? h($doc->uploaded_by_user->full_name) : '—' ?>
                                        <span style="color:var(--text-disabled);display:block;font-size:.72rem;"><?= $doc->created?->format('d/m/Y H:i') ?></span>
                                    </span>
                                    <span class="d-flex gap-1 justify-content-end">
                                        <?= $this->Html->link(
                                            '<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>',
                                            ['action' => 'downloadDocument', $employee->id, $doc->id],
                                            ['class' => 'btn btn-sm btn-outline-primary', 'escape' => false, 'target' => '_blank', 'title' => 'Abrir']
                                        ) ?>
                                        <?php if (!empty($userPermissions['employees']['can_delete'])): ?>
                                        <?= $this->Form->postLink(
                                            '<i class="bi bi-trash" aria-hidden="true"></i>',
                                            ['action' => 'deleteDocument', $employee->id, $doc->id],
                                            ['confirm' => '¿Eliminar este documento?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar', 'style' => 'margin:0;display:inline-flex;']
                                        ) ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
```

Y el empty-state por carpeta vacía (hoy líneas 617-621) pasa a decidir su visibilidad inicial en el servidor:

```php
                                <!-- Empty-state de carpeta vacía (el JS lo alterna al cambiar de carpeta) -->
                                <div id="docEmpty" style="display:<?= $selectedDocCount === 0 ? 'block' : 'none' ?>;padding:28px 18px;text-align:center;
                                            font-size:12px;color:var(--text-faint);font-style:italic;">
                                    <i class="bi bi-folder2-open d-block" style="font-size:22px;margin-bottom:6px;" aria-hidden="true"></i>
                                    Esta carpeta no tiene documentos.
                                </div>
```

El footer de totales (líneas 625-630) **no se toca**: sigue siendo global.

- [ ] **Step 7: JS — filtrado, breadcrumb, select del modal y URL**

Reemplazar el `<script>` completo (hoy líneas 635-663) por:

```html
                <script>
                (function () {
                    var tree = document.getElementById('docTree');
                    if (!tree) { return; }
                    var rows = Array.prototype.slice.call(document.querySelectorAll('#docList .doc-row'));
                    var empty = document.getElementById('docEmpty');
                    var crumb = document.getElementById('docCrumb');

                    // El estado inicial (is-active, filas ocultas, breadcrumb, empty-state)
                    // ya viene renderizado del servidor: aqui NO se llama a selectFolder()
                    // al arrancar, para no repintar ni provocar parpadeo.
                    function selectFolder(id) {
                        var items = Array.prototype.slice.call(tree.querySelectorAll('.doc-tree-item'));
                        var active = null;
                        items.forEach(function (el) {
                            if (el.getAttribute('data-folder-id') === id) { active = el; }
                        });
                        // Id desconocido (?folder= manipulado, o un popstate a una URL vieja):
                        // caer a la primera carpeta, igual que hace el servidor.
                        if (!active) { active = items[0]; }
                        if (!active) { return; }
                        var activeId = active.getAttribute('data-folder-id');

                        items.forEach(function (el) {
                            var match = el === active;
                            el.classList.toggle('is-active', match);
                            if (match) {
                                el.setAttribute('aria-current', 'true');
                            } else {
                                el.removeAttribute('aria-current');
                            }
                        });

                        var visible = 0;
                        rows.forEach(function (row) {
                            var show = row.getAttribute('data-folder-id') === activeId;
                            row.classList.toggle('is-hidden', !show);
                            if (show) { visible++; }
                        });
                        if (empty) { empty.style.display = visible === 0 ? 'block' : 'none'; }
                        if (crumb) { crumb.textContent = active.getAttribute('data-folder-name') || ''; }

                        // OJO: el modal #uploadDocModal vive MAS ABAJO en el DOM que este
                        // script, asi que al arrancar el IIFE todavia no existe. Hay que
                        // resolverlo en cada llamada, no cachearlo arriba.
                        var uploadSelect = document.getElementById('upload-folder-select');
                        if (uploadSelect) { uploadSelect.value = activeId; }
                    }

                    tree.addEventListener('click', function (e) {
                        var item = e.target.closest('.doc-tree-item');
                        if (!item) { return; }
                        e.preventDefault();
                        var id = item.getAttribute('data-folder-id');
                        selectFolder(id);
                        // El href del ancla ya trae los filtros del directorio + ?folder=.
                        window.history.pushState({ folder: id }, '', item.getAttribute('href'));
                    });

                    window.addEventListener('popstate', function () {
                        selectFolder(new URLSearchParams(window.location.search).get('folder') || '');
                    });
                })();
                </script>
```

Dos detalles que no son estéticos y que el código de arriba ya resuelve — no los "simplifiques":

1. `selectFolder()` busca primero el ítem y **sólo entonces** repinta. Una versión que apague `is-active` mientras busca y aborte después dejaría el árbol sin ninguna carpeta resaltada ante un id desconocido.
2. `upload-folder-select` se resuelve **dentro** de la función. Cacheado arriba sería `null` para siempre (ver "Trampa de DOM" en la cabecera de esta tarea) y el modal seguiría apuntando a la primera carpeta tras cambiar de carpeta con clic — justo el bug que este cambio quiere matar.

- [ ] **Step 8: El modal de subida preselecciona la carpeta activa**

En el modal `#uploadDocModal` (hoy línea 971), añadir `'default' => $selectedFolderId` al control:

```php
                    <?= $this->Form->control('employee_folder_id', ['class' => 'form-select', 'label' => ['text' => 'Carpeta de Destino', 'class' => 'form-label'], 'options' => $allFolderOptions, 'default' => $selectedFolderId, 'required' => true, 'id' => 'upload-folder-select']) ?>
```

Sin esto, el `<select>` marca la primera opción y el usuario parado en *VACACIONES* que pulsa "Subir documento" sin tocar el select manda el archivo a la primera carpeta — y encima el redirect lo lleva allí. Esto cubre la carga inicial de la página; el JS del Step 7 mantiene el select sincronizado cuando el usuario cambia de carpeta con clic, sin recargar.

- [ ] **Step 9: Correr los tests y verificar que pasan**

```bash
vendor/bin/phpunit tests/TestCase/Controller/EmployeesFolderSelectionTest.php
composer cs-check
vendor/bin/phpunit
```

Esperado: los 9 tests de `EmployeesFolderSelectionTest` en verde (6 de la Task 2 + 3 de render); `cs-check` sin errores; la suite completa sin failures ni errors (recuerda: exit code 1 aun en verde por notices preexistentes — mira el conteo).

Ojo: `phpcs.xml` sólo cubre `src/` y `tests/`, **no** `templates/`. `cs-check` no valida el archivo que toca esta tarea: el gate real de la parte interactiva es el checklist manual del paso siguiente.

- [ ] **Step 10: Verificación manual en el navegador**

Levantar el servidor (`php bin/cake server`) e ir a `/employees`, entrar a un empleado con varias carpetas y comprobar:

1. La **primera carpeta** del árbol aparece activa (fondo verde, texto verde y en negrita) y la tabla muestra **solo sus documentos**. El ítem con el nombre del empleado ya no existe.
2. Clic en otra carpeta → la tabla muestra solo los documentos de esa carpeta, el breadcrumb del toolbar cambia al nombre de la carpeta, y la URL pasa a `?folder=<id>` **sin recargar**.
3. F5 estando en una carpeta → se conserva esa carpeta. Botón "atrás" → vuelve a la carpeta anterior.
4. Navegar el árbol con Tab + Enter, sin ratón → se puede abrir cualquier carpeta.
5. Seleccionar una carpeta vacía → aparece "Esta carpeta no tiene documentos".
6. **Llegando a la carpeta con clic** (no por URL ni tras un redirect — es el camino donde el select del modal fallaba): estando en una carpeta que no sea la primera, abrir "Subir documento" → el select "Carpeta de Destino" viene preseleccionado con esa carpeta. Al subir, se permanece en ella y el archivo recién subido es visible.
7. Eliminar un documento de una carpeta que no sea la primera → tras la recarga, se permanece en esa carpeta.
8. Crear una carpeta nueva → tras la recarga, la carpeta recién creada queda seleccionada.
9. Filtrar el directorio izquierdo (p. ej. escribir algo en el buscador) y luego cambiar de carpeta → el filtro se conserva en la URL.
10. Un empleado sin carpetas → sigue mostrando el empty-state "Sin carpetas ni documentos".

- [ ] **Step 11: Commit**

```bash
git add templates/Employees/view.php tests/TestCase/Controller/EmployeesFolderSelectionTest.php
git commit -m "feat: gestor documental del empleado con carpeta siempre seleccionada"
```

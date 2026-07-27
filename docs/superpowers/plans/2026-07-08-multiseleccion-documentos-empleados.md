# Multiselección de archivos en subida de documentos (Empleados) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir seleccionar y subir varios archivos en una sola operación al modal "Subir Documento" del módulo de Empleados, con manejo best-effort de fallos por archivo.

**Architecture:** Se añade un método orquestador `uploadDocuments()` en `EmployeeDocumentService` que reúsa el `uploadDocument()` por-archivo existente (validación de extensión/MIME/tamaño intacta). El controlador `EmployeesController::uploadDocument` lee la colección de archivos y construye el flash a partir del resultado agregado. La vista cambia el input a `name="file[]"` con `multiple`. No se toca el modal compartido ni ningún otro módulo.

**Tech Stack:** CakePHP 5.3, PHP 8.4+, PHPUnit + cakephp-fixture-factories, Laminas Diactoros `UploadedFile`.

## Global Constraints

- **Alcance:** solo módulo Empleados. NO modificar `templates/element/upload_doc_modal.php` ni los módulos Refunds/PettyCash/EmployeeNovelties/NoveltyLiquidationDocs/PaymentSchedulings.
- **Best-effort:** cada archivo se valida por separado; los válidos se guardan, los inválidos se reportan. `fail` de lote completo SOLO para carpeta inválida o cero archivos reales.
- **Storage privado:** los documentos siguen guardándose en `ROOT/storage/employees/{employeeId}` vía el `uploadDocument()` existente. NO cambiar la ruta ni usar `DocumentUploadTrait`.
- **Sin migración:** reusa la tabla `EmployeeDocuments`; una fila por archivo.
- **Copy:** plurales reales con `__n(...)`, nunca la forma "(s)". Todo texto envuelto en `__()`.
- **RBAC:** `#[Permission(action: 'add')]` sobre `uploadDocument` no cambia.
- **Tests:** seeding vía Factories (`tests/Factory/`); `UploadedFile` construidos sobre archivos temporales reales (`tempnam`) para que `moveTo()`/`finfo` operen sobre bytes válidos; limpiar archivos creados en `tearDown()`.

---

### Task 1: Método `uploadDocuments()` en `EmployeeDocumentService`

**Files:**
- Modify: `src/Service/EmployeeDocumentService.php` (añadir método público tras `uploadDocument()`, ~línea 197)
- Test: `tests/TestCase/Service/EmployeeDocumentServiceTest.php` (crear)

**Interfaces:**
- Consumes: `EmployeeDocumentService::uploadDocument(int $employeeId, int $folderId, UploadedFile $file, ?int $uploadedBy): ServiceResult` (existente), `EmployeeDocumentService::createFolder(int $employeeId, ?string $name, ?int $parentId): ServiceResult` (existente, usado por los tests para crear una carpeta real).
- Produces: `EmployeeDocumentService::uploadDocuments(int $employeeId, int $folderId, array $files, ?int $uploadedBy): ServiceResult`. En éxito retorna `ServiceResult::ok(['uploaded' => int, 'failed' => array<int, array{name: string, error: string}>])`. En fallo de lote retorna `ServiceResult::fail(string)`.

- [ ] **Step 1: Write the failing test**

Crear `tests/TestCase/Service/EmployeeDocumentServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\EmployeeDocumentService;
use App\Test\Factory\EmployeeFactory;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\UploadedFile;

final class EmployeeDocumentServiceTest extends TestCase
{
    /** @var array<int, string> */
    private array $createdPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->createdPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    private function service(): EmployeeDocumentService
    {
        return new EmployeeDocumentService();
    }

    private function makePdfUpload(string $name = 'doc.pdf'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'empdoc');
        file_put_contents($tmp, "%PDF-1.4\n%minimal\n");

        return new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, $name, 'application/pdf');
    }

    private function makeSpoofUpload(string $name = 'fake.pdf'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'empspoof');
        file_put_contents($tmp, "esto no es un PDF\n");

        return new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, $name, 'application/pdf');
    }

    private function makeNoFileUpload(): UploadedFile
    {
        return new UploadedFile('', 0, UPLOAD_ERR_NO_FILE, '', '');
    }

    public function testUploadDocumentsAllValid(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $folder = $service->createFolder($employee->id, 'Contratos', null)->data;

        $result = $service->uploadDocuments(
            $employee->id,
            $folder->id,
            [$this->makePdfUpload('a.pdf'), $this->makePdfUpload('b.pdf')],
            null,
        );

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->data['uploaded']);
        $this->assertSame([], $result->data['failed']);

        $rows = $this->fetchTable('EmployeeDocuments')
            ->find()->where(['employee_folder_id' => $folder->id])->all()->toArray();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->createdPaths[] = $service->resolveStoragePath($row->file_path);
        }
    }

    public function testUploadDocumentsMixedReportsFailed(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $folder = $service->createFolder($employee->id, 'Contratos', null)->data;

        $result = $service->uploadDocuments(
            $employee->id,
            $folder->id,
            [$this->makePdfUpload('ok.pdf'), $this->makeSpoofUpload('mal.pdf')],
            null,
        );

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->data['uploaded']);
        $this->assertCount(1, $result->data['failed']);
        $this->assertSame('mal.pdf', $result->data['failed'][0]['name']);
        $this->assertNotSame('', $result->data['failed'][0]['error']);

        foreach ($this->fetchTable('EmployeeDocuments')->find()->all() as $row) {
            $this->createdPaths[] = $service->resolveStoragePath($row->file_path);
        }
    }

    public function testUploadDocumentsAllInvalidIsStillOkWithZeroUploaded(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $folder = $service->createFolder($employee->id, 'Contratos', null)->data;

        $result = $service->uploadDocuments(
            $employee->id,
            $folder->id,
            [$this->makeSpoofUpload('x.pdf'), $this->makeSpoofUpload('y.pdf')],
            null,
        );

        $this->assertTrue($result->success);
        $this->assertSame(0, $result->data['uploaded']);
        $this->assertCount(2, $result->data['failed']);
        $this->assertCount(0, $this->fetchTable('EmployeeDocuments')->find()->all()->toArray());
    }

    public function testUploadDocumentsFiltersNoFileEntries(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $folder = $service->createFolder($employee->id, 'Contratos', null)->data;

        $result = $service->uploadDocuments(
            $employee->id,
            $folder->id,
            [$this->makeNoFileUpload()],
            null,
        );

        $this->assertFalse($result->success);
        $this->assertSame('No se recibió ningún archivo válido.', $result->firstError());
    }

    public function testUploadDocumentsInvalidFolderFailsBatch(): void
    {
        $employee = EmployeeFactory::new()->save();
        $other = EmployeeFactory::new()->save();
        $service = $this->service();
        $foreignFolder = $service->createFolder($other->id, 'Ajena', null)->data;

        $result = $service->uploadDocuments(
            $employee->id,
            $foreignFolder->id,
            [$this->makePdfUpload('a.pdf')],
            null,
        );

        $this->assertFalse($result->success);
        $this->assertCount(0, $this->fetchTable('EmployeeDocuments')->find()->all()->toArray());
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/TestCase/Service/EmployeeDocumentServiceTest.php`
Expected: FAIL — `Error: Call to undefined method App\Service\EmployeeDocumentService::uploadDocuments()`.

- [ ] **Step 3: Implement `uploadDocuments()`**

En `src/Service/EmployeeDocumentService.php`, añadir el método justo después de `uploadDocument()` (tras la línea 197). `Laminas\Diactoros\UploadedFile` ya está importado (línea 11).

```php
    /**
     * Subir múltiples documentos a una misma carpeta (best-effort).
     *
     * Filtra entradas sin archivo real (UPLOAD_ERR_NO_FILE), valida la carpeta
     * una sola vez y delega cada archivo en uploadDocument(). Los archivos que
     * fallan validación NO abortan el lote: se acumulan en 'failed'.
     *
     * @param array<int, \Laminas\Diactoros\UploadedFile> $files
     * @return ServiceResult ok(['uploaded' => int, 'failed' => array{name,error}[]])
     *                        o fail() si la carpeta es inválida / no hay archivos.
     */
    public function uploadDocuments(
        int $employeeId,
        int $folderId,
        array $files,
        ?int $uploadedBy,
    ): ServiceResult {
        $files = array_values(array_filter(
            $files,
            fn($file) => $file instanceof UploadedFile && $file->getError() !== UPLOAD_ERR_NO_FILE,
        ));

        if ($files === []) {
            return ServiceResult::fail('No se recibió ningún archivo válido.');
        }

        try {
            $this->assertFolderOwnership($employeeId, $folderId);
        } catch (RecordNotFoundException) {
            return ServiceResult::fail('La carpeta seleccionada no existe o no pertenece al empleado.');
        }

        $uploaded = 0;
        $failed = [];
        foreach ($files as $file) {
            $result = $this->uploadDocument($employeeId, $folderId, $file, $uploadedBy);
            if ($result->success) {
                $uploaded++;
                continue;
            }

            $name = $file->getClientFilename();
            $failed[] = [
                'name' => ($name !== null && $name !== '') ? $name : '(archivo sin nombre)',
                'error' => $result->firstError() ?? 'No se pudo subir el documento.',
            ];
        }

        return ServiceResult::ok(['uploaded' => $uploaded, 'failed' => $failed]);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/TestCase/Service/EmployeeDocumentServiceTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Run cs-check on the changed files**

Run: `composer cs-check -- src/Service/EmployeeDocumentService.php tests/TestCase/Service/EmployeeDocumentServiceTest.php`
Expected: sin errores (si los hay, `composer cs-fix -- <archivos>` y re-verificar).

- [ ] **Step 6: Commit**

```bash
git add src/Service/EmployeeDocumentService.php tests/TestCase/Service/EmployeeDocumentServiceTest.php
git commit -m "feat: uploadDocuments() best-effort para lote de documentos de empleado"
```

---

### Task 2: Controlador `EmployeesController::uploadDocument` para múltiples archivos

**Files:**
- Modify: `src/Controller/EmployeesController.php:252-280` (método `uploadDocument`)
- Test: `tests/TestCase/Controller/EmployeesDocumentUploadTest.php` (crear)

**Interfaces:**
- Consumes: `EmployeeDocumentService::uploadDocuments(int, int, array, ?int): ServiceResult` (Task 1).
- Produces: acción HTTP `POST /employees/upload-document/{employeeId}` con input `file[]`; redirige a `view` con flashes de éxito/error.

- [ ] **Step 1: Write the failing test**

Crear `tests/TestCase/Controller/EmployeesDocumentUploadTest.php`. Sigue el patrón de `PettyCashRecordsDocumentGateTest` (no inyecta archivos por integración; la mecánica multi-archivo se cubre en Task 1) y de `AdvancesViewTest` para sembrar permiso CRUD en la tabla `Permissions`.

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\Factory\EmployeeFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class EmployeesDocumentUploadTest extends TestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        // assertFlashMessage()/assertFlashElement() leen de _requestSession, que
        // sólo recibe el flash re-inyectado si retain está activo (patrón de
        // UsersControllerTest.php:27).
        $this->enableRetainFlashMessages();
    }

    private function userWithCreate(bool $canCreate): object
    {
        $role = RoleFactory::new()->save();
        if ($canCreate) {
            $permissions = TableRegistry::getTableLocator()->get('Permissions');
            $permissions->saveOrFail($permissions->newEntity([
                'role_id' => $role->id,
                'module' => 'employees',
                'can_create' => true,
            ]));
        }

        return UserFactory::new(['role_id' => $role->id])->save();
    }

    public function testUploadForbiddenWithoutCreatePermission(): void
    {
        $employee = EmployeeFactory::new()->save();

        $this->session(['Auth' => $this->userWithCreate(false)]);
        $this->enableCsrfToken();
        $this->post('/employees/upload-document/' . $employee->id, ['employee_folder_id' => 1]);

        $this->assertResponseCode(403);
    }

    public function testUploadWithoutFileRedirectsWithError(): void
    {
        $employee = EmployeeFactory::new()->save();

        $this->session(['Auth' => $this->userWithCreate(true)]);
        $this->enableCsrfToken();
        $this->post('/employees/upload-document/' . $employee->id, ['employee_folder_id' => 1]);

        $this->assertResponseCode(302);
        $this->assertRedirectContains('/employees/view/' . $employee->id);
        $this->assertFlashElement('flash/error');
        $this->assertFlashMessage('No se recibió ningún archivo válido.');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail (or reveal current single-file behavior)**

Run: `vendor/bin/phpunit tests/TestCase/Controller/EmployeesDocumentUploadTest.php`
Expected: `testUploadWithoutFileRedirectsWithError` FALLA con el mensaje actual del código single-file ("No se recibió ningún archivo válido." lo emite hoy el propio controller en el guard `if (!$file)`, así que este test podría pasar antes del cambio; el objetivo real del test es fijar el comportamiento tras migrar a `uploadDocuments()`). `testUploadForbiddenWithoutCreatePermission` debe PASAR ya (RBAC no cambia). Si ambos pasan aquí, continúa igual: son la red de regresión del cambio del Step 3.

- [ ] **Step 3: Reemplazar el cuerpo del método `uploadDocument`**

En `src/Controller/EmployeesController.php`, reemplazar el método completo (líneas 252-280) por:

```php
    #[Permission(action: 'add')]
    public function uploadDocument($employeeId = null)
    {
        $this->request->allowMethod(['post']);
        $this->Employees->get($employeeId);

        $uploaded = $this->request->getUploadedFiles()['file'] ?? [];
        $files = is_array($uploaded) ? $uploaded : [$uploaded];

        // post_max_size excedido: PHP descarta todos los archivos (getUploadedFiles
        // vacío) pero el cuerpo llegó (CONTENT_LENGTH > 0). Mensaje distinguible.
        if ($files === [] && (int)$this->request->getEnv('CONTENT_LENGTH') > 0) {
            $this->Flash->error(__('Los archivos superan el tamaño total permitido por el servidor. Sube menos archivos a la vez.'));

            return $this->redirect(['action' => 'view', $employeeId]);
        }

        $identity = $this->Authentication->getIdentity();
        $result = $this->documentService->uploadDocuments(
            (int)$employeeId,
            (int)$this->request->getData('employee_folder_id'),
            $files,
            $identity ? (int)$identity->getIdentifier() : null,
        );

        if (!$result->success) {
            $this->Flash->error(__($result->firstError() ?? 'No se pudo subir el documento.'));

            return $this->redirect(['action' => 'view', $employeeId]);
        }

        $data = $result->data;
        if ($data['uploaded'] >= 1) {
            $this->Flash->success(__n('%d documento subido.', '%d documentos subidos.', $data['uploaded'], $data['uploaded']));
        }
        if (!empty($data['failed'])) {
            $lines = array_map(
                fn($f) => $f['name'] . ' (' . $f['error'] . ')',
                $data['failed'],
            );
            $this->Flash->error(__('No se pudieron subir: {0}', implode(', ', $lines)));
        }

        return $this->redirect(['action' => 'view', $employeeId]);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/TestCase/Controller/EmployeesDocumentUploadTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Run cs-check**

Run: `composer cs-check -- src/Controller/EmployeesController.php tests/TestCase/Controller/EmployeesDocumentUploadTest.php`
Expected: sin errores.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/EmployeesController.php tests/TestCase/Controller/EmployeesDocumentUploadTest.php
git commit -m "feat: uploadDocument acepta múltiples archivos con flash best-effort"
```

---

### Task 3: Vista — input multiple en el modal "Subir Documento"

**Files:**
- Modify: `templates/Employees/view.php:973-976` (bloque del input de archivo dentro del modal `#uploadDocModal`)

**Interfaces:**
- Consumes: la acción `uploadDocument` (Task 2) que lee `getUploadedFiles()['file']`.
- Produces: HTML con `<input type="file" name="file[]" multiple>` dentro del form del modal.

- [ ] **Step 1: Reemplazar el control de archivo por input `file[]` multiple**

En `templates/Employees/view.php`, reemplazar el bloque actual (líneas 973-976):

```php
                <div class="mb-3">
                    <?= $this->Form->control('file', ['type' => 'file', 'class' => 'form-control', 'label' => ['text' => 'Archivo', 'class' => 'form-label'], 'required' => true, 'accept' => '.pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.txt']) ?>
                    <div class="form-text">Máximo <?= h(\App\Constants\UploadConstants::MAX_BYTES_LABEL) ?> — PDF, imágenes, Word, Excel o texto.</div>
                </div>
```

por:

```php
                <div class="mb-3">
                    <label class="form-label" for="upload-files">Archivos</label>
                    <input type="file" name="file[]" id="upload-files" class="form-control" required multiple
                           accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.txt">
                    <div class="form-text">Puedes seleccionar varios archivos. Máximo <?= h(\App\Constants\UploadConstants::MAX_BYTES_LABEL) ?> por archivo — PDF, imágenes, Word, Excel o texto.</div>
                </div>
```

Nota: se usa `<input>` crudo (dentro del `$this->Form->create(...)`, que ya aporta el token CSRF) en lugar de `$this->Form->control('file', ...)` para garantizar de forma predecible el atributo `name="file[]"` + `multiple`. Es el mismo enfoque de input plano que usa el modal compartido `templates/element/upload_doc_modal.php`.

- [ ] **Step 2: Verificar el render manualmente**

Levantar el servidor (`php bin/cake server`), entrar a la vista de un empleado, abrir el modal "Subir Documento" y confirmar en el DOM que el input es `<input type="file" name="file[]" ... multiple>` y que el selector de archivos del navegador permite elegir varios. Subir 2-3 archivos válidos → flash "N documentos subidos."; incluir uno inválido → flash de fallidos con su motivo.

- [ ] **Step 3: Run cs-check**

Run: `composer cs-check -- templates/Employees/view.php`
Expected: sin errores.

- [ ] **Step 4: Commit**

```bash
git add templates/Employees/view.php
git commit -m "feat: modal de subida de documentos de empleado acepta múltiples archivos"
```

---

### Task 4: Verificación integral

- [ ] **Step 1: Suite completa de los archivos tocados**

Run: `vendor/bin/phpunit tests/TestCase/Service/EmployeeDocumentServiceTest.php tests/TestCase/Controller/EmployeesDocumentUploadTest.php`
Expected: PASS (7 tests).

- [ ] **Step 2: cs-check global de lo modificado**

Run: `composer cs-check`
Expected: sin errores nuevos introducidos por este cambio.

- [ ] **Step 3: Regresión — módulos que comparten el flujo de documentos NO cambian**

Confirmar que no se tocó `templates/element/upload_doc_modal.php` ni servicios/controladores de otros módulos:
Run: `git diff --name-only main...HEAD`
Expected: solo `docs/superpowers/**`, `src/Service/EmployeeDocumentService.php`, `src/Controller/EmployeesController.php`, `templates/Employees/view.php`, y los 2 archivos de test nuevos.

---

## Notas de verificación contra la spec

- **Criterios de aceptación 1-3** (multiselección + conteo + best-effort mixto): la mecánica (guardado, conteo, lista de fallidos) está cubierta por tests de servicio en Task 1. La construcción del **texto** del flash de éxito (`__n`) y del listado de fallidos en el controller NO se testea por integración: `Cake\TestSuite\IntegrationTestTrait::_buildRequest` hardcodea `files => []` (no inyecta `UploadedFile`), por eso el repo no tiene ningún test de subida por integración. El texto del flash se verifica manualmente en Task 3 Step 2. La lógica es un `__n(...)` y un `implode` triviales, de bajo riesgo.
- **Criterio 4** (carpeta inválida): Task 1 `testUploadDocumentsInvalidFolderFailsBatch`.
- **Criterio 5** (submit sin archivo real): Task 1 `testUploadDocumentsFiltersNoFileEntries` + Task 2 `testUploadWithoutFileRedirectsWithError`.
- **Criterio 6** (post_max_size): Task 2 Step 3 (rama de detección) — verificación manual (Task 3 Step 2) por dificultad de simular en integración.
- **Criterio 7** (no afecta otros módulos): Task 4 Step 3.
- **Criterio 8** (RBAC `add`): Task 2 `testUploadForbiddenWithoutCreatePermission`.

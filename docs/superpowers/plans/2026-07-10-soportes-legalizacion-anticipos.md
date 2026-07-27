# Tabla general de soportes en la legalización de anticipos — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar al sub-pipeline de legalización de anticipos una tabla general de soportes (`advance_legalization_documents`) como el resto de módulos de flujo, y mover ahí el comprobante de consignación del faltante, eliminando la columna especial `shortage_receipt_path`.

**Architecture:** Se replica el canon de `refund_documents` (tabla + entity + table class + `{Modulo}DocumentService` + acciones de controller con `#[PipelineAction]` + `documents_section`/`document_row`/`spi-document-uploader.js`). El único documento especial que permanece es la relación de facturas (`advance_legalization_signatures`). El comprobante de consignación pasa a la tabla general; se conservan sus metadatos (`shortage_receipt_number`, `shortage_received_at`). Forward-only, sin backfill.

**Tech Stack:** CakePHP 5.3, PHP 8.4+, MySQL/MariaDB, Phinx migrations (`Migrations\BaseMigration`), PHPUnit + fixture-factories, Bootstrap/`.spi-` CSS.

**Spec:** `docs/superpowers/specs/2026-07-10-soportes-legalizacion-anticipos-design.md`

## Global Constraints

- Migraciones extienden `Migrations\BaseMigration` (NO `AbstractMigration`) y usan guard `hasTable()`.
- Columnas FK `integer` con `signed => true` (alinean con `advance_legalizations.id` y `users.id`, invariante MI-010).
- La FK a la legalización se llama `legalization_id` (consistente con `advance_legalization_signatures`).
- Los servicios obtienen tablas vía `TableRegistry::getTableLocator()->get('X')`, nunca `$this->X`.
- `document_type` existe nullable por paridad, pero SIN selector en UI (cajón único): `upload_doc_modal` con `showDocumentType => false`.
- Se conservan `shortage_receipt_number` y `shortage_received_at`; solo se elimina `shortage_receipt_path`.
- Anti-IDOR: `deleteDocument($documentId, $legalizationId)` filtra por `['id' => $documentId, 'legalization_id' => $legalizationId]`.
- Slugs persistidos INMUTABLES: pipeline `legalizations` (`PipelineStepConstants::PIPELINE_LEGALIZATIONS`), CRUD `advances`. No se tocan.
- Rutas custom van antes de `$builder->fallbacks()` en `config/routes.php`.
- Reúso de átomos: `document_row`, `upload_doc_modal`, `document_row_template`, `spi-document-uploader.js`. Prefijo CSS `.spi-`.
- Tests: correr con `vendor/bin/phpunit --filter <Clase>` (NO `composer test`; timeout 300s; credenciales en `config/.env`). Estilo: `composer cs-check` / `composer cs-fix`.
- Commits: conventional commits (`feat:`/`refactor:`/`test:`), sin línea de atribución.

---

## Task 1: Migración + modelo de la tabla de soportes

**Files:**
- Create: `config/Migrations/20260710120000_CreateAdvanceLegalizationDocuments.php`
- Create: `src/Model/Entity/AdvanceLegalizationDocument.php`
- Create: `src/Model/Table/AdvanceLegalizationDocumentsTable.php`
- Modify: `src/Model/Table/AdvanceLegalizationsTable.php:49-58` (añadir `hasMany`)
- Test: `tests/TestCase/Model/Table/AdvanceLegalizationDocumentsTableTest.php`

**Interfaces:**
- Produces: tabla `advance_legalization_documents`; ORM alias `AdvanceLegalizationDocuments` con `belongsTo('AdvanceLegalizations', ['foreignKey' => 'legalization_id'])` y `belongsTo('UploadedByUsers', ['className' => 'Users', 'foreignKey' => 'uploaded_by'])`; assoc `AdvanceLegalizations hasMany AdvanceLegalizationDocuments`.

- [ ] **Step 1: Escribir la migración**

Create `config/Migrations/20260710120000_CreateAdvanceLegalizationDocuments.php` (espejo exacto de `20260503215013_CreateRefundDocuments.php`, con `legalization_id` → `advance_legalizations`):

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateAdvanceLegalizationDocuments extends BaseMigration
{
    public function change(): void
    {
        if ($this->hasTable('advance_legalization_documents')) {
            return;
        }

        $table = $this->table('advance_legalization_documents');

        $table->addColumn('legalization_id', 'integer', [
            'null' => false,
            'signed' => true,
        ]);
        $table->addColumn('document_type', 'string', [
            'limit' => 100,
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('file_path', 'string', [
            'limit' => 255,
            'null' => false,
        ]);
        $table->addColumn('file_name', 'string', [
            'limit' => 255,
            'null' => false,
        ]);
        $table->addColumn('file_size', 'integer', [
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('mime_type', 'string', [
            'limit' => 100,
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('uploaded_by', 'integer', [
            'null' => true,
            'default' => null,
            'signed' => true,
        ]);
        $table->addColumn('created', 'datetime', [
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('modified', 'datetime', [
            'null' => true,
            'default' => null,
        ]);

        $table->addIndex(['legalization_id']);

        $table->addForeignKey('legalization_id', 'advance_legalizations', 'id', [
            'delete' => 'CASCADE',
            'update' => 'CASCADE',
        ]);
        $table->addForeignKey('uploaded_by', 'users', 'id', [
            'delete' => 'SET_NULL',
            'update' => 'CASCADE',
        ]);

        $table->create();
    }
}
```

- [ ] **Step 2: Correr la migración**

Run: `php bin/cake migrations migrate`
Expected: `== CreateAdvanceLegalizationDocuments: migrated` y sin errores.

- [ ] **Step 3: Escribir la entidad**

Create `src/Model/Entity/AdvanceLegalizationDocument.php` (espejo de `RefundDocument`):

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class AdvanceLegalizationDocument extends Entity
{
    protected array $_accessible = [
        'legalization_id' => true,
        'document_type' => true,
        'file_path' => true,
        'file_name' => true,
        'file_size' => true,
        'mime_type' => true,
        'uploaded_by' => true,
        'created' => true,
        'modified' => true,
        'advance_legalization' => true,
        'uploaded_by_user' => true,
    ];
}
```

- [ ] **Step 4: Escribir la Table class**

Create `src/Model/Table/AdvanceLegalizationDocumentsTable.php` (espejo de `RefundDocumentsTable`):

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class AdvanceLegalizationDocumentsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('advance_legalization_documents');
        $this->setDisplayField('file_name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('AdvanceLegalizations', [
            'foreignKey' => 'legalization_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('UploadedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'uploaded_by',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('legalization_id')
            ->requirePresence('legalization_id', 'create')
            ->notEmptyString('legalization_id');

        $validator
            ->scalar('file_path')
            ->maxLength('file_path', 255)
            ->requirePresence('file_path', 'create')
            ->notEmptyString('file_path');

        $validator
            ->scalar('file_name')
            ->maxLength('file_name', 255)
            ->requirePresence('file_name', 'create')
            ->notEmptyString('file_name');

        $validator
            ->scalar('document_type')
            ->maxLength('document_type', 100)
            ->allowEmptyString('document_type');

        $validator
            ->scalar('mime_type')
            ->maxLength('mime_type', 100)
            ->allowEmptyString('mime_type');

        $validator
            ->integer('file_size')
            ->allowEmptyString('file_size');

        $validator
            ->integer('uploaded_by')
            ->allowEmptyString('uploaded_by');

        return $validator;
    }
}
```

- [ ] **Step 5: Añadir la asociación `hasMany` en `AdvanceLegalizationsTable`**

En `src/Model/Table/AdvanceLegalizationsTable.php`, tras el `hasMany('AdvanceLegalizationSignatures', …)` (línea 49-53), añadir:

```php
        $this->hasMany('AdvanceLegalizationDocuments', [
            'foreignKey' => 'legalization_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
```

- [ ] **Step 6: Escribir el test de la Table (falla primero)**

Create `tests/TestCase/Model/Table/AdvanceLegalizationDocumentsTableTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

final class AdvanceLegalizationDocumentsTableTest extends TestCase
{
    public function testSavesAndContainsAssociations(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationDocuments');
        $doc = $table->newEntity([
            'legalization_id' => $leg->id,
            'file_path' => 'uploads/advances/' . $leg->id . '/leg_test.pdf',
            'file_name' => 'soporte.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'uploaded_by' => $user->id,
        ]);
        $this->assertNotFalse($table->save($doc), 'La fila de soporte debería guardarse.');

        $reloaded = $table->get($doc->id, contain: ['AdvanceLegalizations', 'UploadedByUsers']);
        $this->assertSame($leg->id, $reloaded->legalization_id);
        $this->assertSame($leg->id, $reloaded->advance_legalization->id);
        $this->assertSame($user->id, $reloaded->uploaded_by_user->id);
        $this->assertNull($reloaded->document_type);
    }
}
```

- [ ] **Step 7: Correr el test**

Run: `vendor/bin/phpunit --filter AdvanceLegalizationDocumentsTableTest`
Expected: PASS (1 test).

- [ ] **Step 8: cs-check y commit**

```bash
composer cs-check
git add config/Migrations/20260710120000_CreateAdvanceLegalizationDocuments.php src/Model/Entity/AdvanceLegalizationDocument.php src/Model/Table/AdvanceLegalizationDocumentsTable.php src/Model/Table/AdvanceLegalizationsTable.php tests/TestCase/Model/Table/AdvanceLegalizationDocumentsTableTest.php
git commit -m "feat: tabla advance_legalization_documents (soportes de legalizacion)"
```

---

## Task 2: Servicio de documentos + limpieza del comprobante de faltante

**Files:**
- Modify: `src/Service/AdvanceLegalizationDocumentService.php` (añadir `uploadDocument`/`deleteDocument`, eliminar `attachShortageReceipt`)
- Modify: `src/Service/AdvanceLegalizationService.php:600-606` (quitar bloque `receipt_file`) y su docblock `:566-573`
- Modify: `src/Controller/AdvancesController.php:997` (quitar `$data['receipt_file'] = …`)
- Test: `tests/TestCase/Service/AdvanceLegalizationDocumentServiceTest.php`

**Interfaces:**
- Consumes: `DocumentUploadTrait::uploadAndSave($file, $tableName, $subDir, $prefix, $entityFields)` y `deleteDocumentRecord($tableName, $documentId)`.
- Produces:
  - `AdvanceLegalizationDocumentService::uploadDocument(int $legalizationId, UploadedFile $file, ?int $uploadedBy): object|string` — entity `AdvanceLegalizationDocument` en éxito, string de error en fallo.
  - `AdvanceLegalizationDocumentService::deleteDocument(int $documentId, int $legalizationId): bool` — true si borró.

- [ ] **Step 1: Escribir el test del servicio (falla primero)**

Create `tests/TestCase/Service/AdvanceLegalizationDocumentServiceTest.php` (patrón de `EmployeeDocumentServiceTest` + factories de `AdvanceLegalizationShortageTest`):

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Model\Entity\AdvanceLegalizationDocument;
use App\Service\AdvanceLegalizationDocumentService;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\UploadedFile;

final class AdvanceLegalizationDocumentServiceTest extends TestCase
{
    /** @var array<int,string> */
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

    private function service(): AdvanceLegalizationDocumentService
    {
        return new AdvanceLegalizationDocumentService();
    }

    private function makePdfUpload(string $name = 'soporte.pdf'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'legdoc');
        file_put_contents($tmp, "%PDF-1.4\n%minimal\n");

        return new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, $name, 'application/pdf');
    }

    private function seedLeg(): int
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        return (int)$leg->id;
    }

    public function testUploadDocumentCreatesRow(): void
    {
        $legId = $this->seedLeg();
        $user = UserFactory::new()->save();

        $result = $this->service()->uploadDocument($legId, $this->makePdfUpload(), (int)$user->id);

        $this->assertInstanceOf(AdvanceLegalizationDocument::class, $result);
        $this->createdPaths[] = WWW_ROOT . str_replace('/', DS, $result->file_path);

        $this->assertSame($legId, $result->legalization_id);
        $this->assertSame((int)$user->id, $result->uploaded_by);
        $this->assertNull($result->document_type);
        $this->assertSame('soporte.pdf', $result->file_name);
    }

    public function testDeleteDocumentRemovesRow(): void
    {
        $legId = $this->seedLeg();
        $doc = $this->service()->uploadDocument($legId, $this->makePdfUpload(), null);
        $this->createdPaths[] = WWW_ROOT . str_replace('/', DS, $doc->file_path);

        $deleted = $this->service()->deleteDocument((int)$doc->id, $legId);

        $this->assertTrue($deleted);
        $this->assertFalse(
            TableRegistry::getTableLocator()->get('AdvanceLegalizationDocuments')->exists(['id' => $doc->id]),
        );
    }

    public function testDeleteDocumentIsAntiIdor(): void
    {
        $legId = $this->seedLeg();
        $otherLegId = $this->seedLeg();
        $doc = $this->service()->uploadDocument($legId, $this->makePdfUpload(), null);
        $this->createdPaths[] = WWW_ROOT . str_replace('/', DS, $doc->file_path);

        // Intentar borrar el doc de legId pasando otherLegId → no debe borrar.
        $deleted = $this->service()->deleteDocument((int)$doc->id, $otherLegId);

        $this->assertFalse($deleted);
        $this->assertTrue(
            TableRegistry::getTableLocator()->get('AdvanceLegalizationDocuments')->exists(['id' => $doc->id]),
        );
    }
}
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `vendor/bin/phpunit --filter AdvanceLegalizationDocumentServiceTest`
Expected: FAIL (`Call to undefined method …::uploadDocument()`).

- [ ] **Step 3: Implementar `uploadDocument`/`deleteDocument` y eliminar `attachShortageReceipt`**

En `src/Service/AdvanceLegalizationDocumentService.php`:

1. Eliminar íntegro el método `attachShortageReceipt()` (`:99-117`).
2. Añadir, tras `attachRelationDocument()`, los dos métodos canónicos:

```php
    /**
     * Sube un soporte general de la legalización a la tabla advance_legalization_documents.
     *
     * @return \App\Model\Entity\AdvanceLegalizationDocument|string Entity en éxito, mensaje en error.
     */
    public function uploadDocument(int $legalizationId, UploadedFile $file, ?int $uploadedBy): object|string
    {
        return $this->uploadAndSave($file, 'AdvanceLegalizationDocuments', 'advances/' . $legalizationId, 'leg_', [
            'legalization_id' => $legalizationId,
            'uploaded_by' => $uploadedBy,
        ]);
    }

    /**
     * Elimina un soporte verificando que pertenezca a la legalización indicada
     * (anti-IDOR: nunca se borra por documentId crudo).
     */
    public function deleteDocument(int $documentId, int $legalizationId): bool
    {
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationDocuments');
        if (!$table->exists(['id' => $documentId, 'legalization_id' => $legalizationId])) {
            return false;
        }

        return $this->deleteDocumentRecord('AdvanceLegalizationDocuments', $documentId);
    }
```

(El `use App\Constants\AdvanceConstants;` sigue usándose en `attachRelationDocument` — no tocar los imports salvo que queden huérfanos.)

- [ ] **Step 4: Quitar el manejo de `receipt_file` de `confirmShortageReceipt`**

En `src/Service/AdvanceLegalizationService.php`, eliminar el bloque (`:600-606`):

```php
        if (!empty($data['receipt_file']) && $data['receipt_file'] instanceof UploadedFile) {
            $upload = $this->documentService->attachShortageReceipt($leg, $data['receipt_file']);
            if (!$upload->success) {
                return $upload;
            }
            $leg->shortage_receipt_path = $upload->data;
        }
```

En el docblock del método (`:566-573`), quitar la línea del `@param` que menciona `receipt_file` (`*     receipt_file?: \Laminas\Diactoros\UploadedFile|null,` y la frase `receipt_file opcional.`). Si tras el borrado el `use Laminas\Diactoros\UploadedFile;` queda sin usar en el archivo, eliminarlo (verificar con grep antes de quitarlo).

**Nota (dependencia `documentService`):** tras quitar el bloque, `$this->documentService` deja de usarse dentro de `AdvanceLegalizationService`. **No** eliminar el parámetro del constructor (`private … AdvanceLegalizationDocumentService $documentService`): lo pasan explícitamente varios tests (`AdvanceLegalizationShortageTest::buildService`, `AdvanceLegalizationSurplusTest`, `AdvanceRefundPaymentTest`) vía `new AdvanceLegalizationService(new EventManager(), new AdvanceLegalizationHistoryService(), new AdvanceLegalizationDocumentService())`. Quitarlo rompería esas construcciones sin beneficio; el type-hint mantiene el `use` vivo. Se conserva por estabilidad de la firma.

- [ ] **Step 5: Quitar `receipt_file` del controller `confirmShortage`**

En `src/Controller/AdvancesController.php:997`, eliminar la línea:

```php
        $data['receipt_file'] = $this->request->getUploadedFile('receipt_file');
```

- [ ] **Step 6: Correr los tests del servicio y de shortage**

Run: `vendor/bin/phpunit --filter AdvanceLegalizationDocumentServiceTest`
Expected: PASS (3 tests).

Run: `vendor/bin/phpunit --filter AdvanceLegalizationShortageTest`
Expected: PASS (los tests de `confirmShortageReceipt` no usan `receipt_file`, siguen verdes).

- [ ] **Step 7: cs-check y commit**

```bash
composer cs-check
git add src/Service/AdvanceLegalizationDocumentService.php src/Service/AdvanceLegalizationService.php src/Controller/AdvancesController.php tests/TestCase/Service/AdvanceLegalizationDocumentServiceTest.php
git commit -m "feat: uploadDocument/deleteDocument en AdvanceLegalizationDocumentService y baja del receipt_file especial"
```

---

## Task 3: Predicados de estado y de rol (`canManageDocuments`)

**Files:**
- Modify: `src/Model/Entity/AdvanceLegalization.php` (`$_accessible` + nuevo predicate)
- Modify: `src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php` (nuevo método)
- Test: `tests/TestCase/Model/Entity/AdvanceLegalizationDocumentsPredicateTest.php`

**Interfaces:**
- Produces:
  - `AdvanceLegalization::canManageDocuments(): bool` — true salvo estado `legalizada`.
  - `AdvanceLegalizationActionPolicy::canManageDocuments(AdvanceLegalization $leg, int $roleId): bool`.

- [ ] **Step 1: Escribir el test del predicate de entidad (falla primero)**

Create `tests/TestCase/Model/Entity/AdvanceLegalizationDocumentsPredicateTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use Cake\TestSuite\TestCase;

final class AdvanceLegalizationDocumentsPredicateTest extends TestCase
{
    public function testCanManageDocumentsTrueInOperableState(): void
    {
        $leg = new AdvanceLegalization();
        $leg->status = AdvanceConstants::STATUS_CONTABILIDAD;
        $this->assertTrue($leg->canManageDocuments());
    }

    public function testCanManageDocumentsFalseWhenLegalizada(): void
    {
        $leg = new AdvanceLegalization();
        $leg->status = AdvanceConstants::STATUS_LEGALIZADA;
        $this->assertFalse($leg->canManageDocuments());
    }
}
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `vendor/bin/phpunit --filter AdvanceLegalizationDocumentsPredicateTest`
Expected: FAIL (`Call to undefined method …::canManageDocuments()`).

- [ ] **Step 3: Ajustar la entidad**

En `src/Model/Entity/AdvanceLegalization.php`:

1. En `$_accessible`, eliminar la línea `'shortage_receipt_path' => false,` (`:29`).
2. En `$_accessible`, añadir junto a `'advance_legalization_signatures' => true,` la línea:

```php
        'advance_legalization_documents' => true,
```

3. Añadir el predicate, junto a `canConfirmShortage()` (tras `:144`):

```php
    /** @return bool true cuando el estado permite gestionar (subir/eliminar) soportes generales. */
    public function canManageDocuments(): bool
    {
        return !$this->isLegalized();
    }
```

- [ ] **Step 4: Añadir el método al policy**

En `src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php`, tras `canConfirmShortage()` (`:85`):

```php
    public function canManageDocuments(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canManageDocuments();
    }
```

- [ ] **Step 5: Correr el test**

Run: `vendor/bin/phpunit --filter AdvanceLegalizationDocumentsPredicateTest`
Expected: PASS (2 tests).

- [ ] **Step 6: cs-check y commit**

```bash
composer cs-check
git add src/Model/Entity/AdvanceLegalization.php src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php tests/TestCase/Model/Entity/AdvanceLegalizationDocumentsPredicateTest.php
git commit -m "feat: predicate canManageDocuments en entidad y policy de legalizacion"
```

---

## Task 4: Acciones de controller, rutas y contain

**Files:**
- Modify: `src/Controller/AdvancesController.php` (2 acciones nuevas; contain en `view()` `:352-362` y `legalization()` `:395-404`)
- Modify: `config/routes.php` (2 rutas, tras `:559`)
- Test: `tests/TestCase/Controller/AdvancesLegalizationDocumentTest.php`

**Interfaces:**
- Consumes: `AdvanceLegalizationDocumentService::uploadDocument/deleteDocument` (Task 2); `AdvanceLegalizationActionPolicy::canManageDocuments` (Task 3); `DocumentJsonPayloadTrait::_buildDocumentPayload`; helpers `_loadLegalization`, `_getCurrentUser`, `_isJsonRequest`, `_jsonResponse`, `_denyAction`, `_redirectMissing`.
- Produces: rutas `POST /advances/upload-legalization-document/{id}` y `POST /advances/delete-legalization-document/{id}/{documentId}` (donde `{id}` es `advance_invoice_id`).

**Nota:** `AdvancesController` ya inyecta `AdvanceLegalizationDocumentService`. Verificar la propiedad; si no existe, añadir `private AdvanceLegalizationDocumentService $documentService;` y en `initialize()` `$this->documentService = $this->getContainer()->get(AdvanceLegalizationDocumentService::class);` (el `use` ya está importado, `:15`).

- [ ] **Step 1: Escribir el test de controller (falla primero)**

Create `tests/TestCase/Controller/AdvancesLegalizationDocumentTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\User;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\ProviderFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\UploadedFile;

final class AdvancesLegalizationDocumentTest extends TestCase
{
    use IntegrationTestTrait;

    /** @var array<int,string> */
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

    private function _seedOperator(string $step): User
    {
        $role = RoleFactory::new()->save();

        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'advances',
            'can_view' => true,
            'can_edit' => true,
        ]));

        $pipelinePermissions = TableRegistry::getTableLocator()->get('PipelinePermissions');
        $pipelinePermissions->saveOrFail($pipelinePermissions->newEntity([
            'role_id' => $role->id,
            'pipeline' => PipelineStepConstants::PIPELINE_LEGALIZATIONS,
            'step' => $step,
            'can_operate' => true,
        ]));

        return UserFactory::new(['role_id' => $role->id])->save();
    }

    private function makePdfUpload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'legupload');
        file_put_contents($tmp, "%PDF-1.4\n%minimal\n");

        return new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, 'soporte.pdf', 'application/pdf');
    }

    private function seedLeg(string $status): array
    {
        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus($status)->save();

        return [(int)$anticipo->id, (int)$leg->id];
    }

    public function testUploadCreatesDocumentRow(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD);
        [$anticipoId, $legId] = $this->seedLeg(AdvanceConstants::STATUS_CONTABILIDAD);

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/upload-legalization-document/' . $anticipoId, [
            'file' => $this->makePdfUpload(),
        ]);

        $rows = TableRegistry::getTableLocator()->get('AdvanceLegalizationDocuments')
            ->find()->where(['legalization_id' => $legId])->all()->toArray();
        $this->assertCount(1, $rows);
        $this->assertSame((int)$user->id, $rows[0]->uploaded_by);
        $this->assertNull($rows[0]->document_type);
        $this->createdPaths[] = WWW_ROOT . str_replace('/', DS, $rows[0]->file_path);
    }

    public function testUploadForbiddenWhenLegalizada(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD);
        [$anticipoId, $legId] = $this->seedLeg(AdvanceConstants::STATUS_LEGALIZADA);

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/upload-legalization-document/' . $anticipoId, [
            'file' => $this->makePdfUpload(),
        ]);

        $this->assertCount(
            0,
            TableRegistry::getTableLocator()->get('AdvanceLegalizationDocuments')
                ->find()->where(['legalization_id' => $legId])->all()->toArray(),
        );
    }

    public function testDeleteIsAntiIdor(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD);
        [$anticipoId, $legId] = $this->seedLeg(AdvanceConstants::STATUS_CONTABILIDAD);
        [$otherAnticipoId] = $this->seedLeg(AdvanceConstants::STATUS_CONTABILIDAD);

        $docs = TableRegistry::getTableLocator()->get('AdvanceLegalizationDocuments');
        $doc = $docs->saveOrFail($docs->newEntity([
            'legalization_id' => $legId,
            'file_path' => 'uploads/advances/' . $legId . '/leg_fake.pdf',
            'file_name' => 'fake.pdf',
            'mime_type' => 'application/pdf',
        ]));

        // Borrar el doc de legId pasando el anticipo de OTRA legalización → no debe borrar.
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/delete-legalization-document/' . $otherAnticipoId . '/' . $doc->id);

        $this->assertTrue($docs->exists(['id' => $doc->id]), 'El doc no debía borrarse (anti-IDOR).');
    }
}
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `vendor/bin/phpunit --filter AdvancesLegalizationDocumentTest`
Expected: FAIL (rutas inexistentes → 404 / missing route).

- [ ] **Step 3: Añadir el contain de documentos en `view()` y `legalization()`**

En `src/Controller/AdvancesController.php`, en `view()` (`:361`) y en `legalization()` (`:403`), reemplazar la clave del contain de `AdvanceLegalization` por:

```php
            'AdvanceLegalization' => [
                'AdvanceLegalizationSignatures' => ['SignedByUsers'],
                'AdvanceLegalizationDocuments' => ['UploadedByUsers'],
            ],
```

- [ ] **Step 4: Añadir las dos acciones**

En `src/Controller/AdvancesController.php`, tras `uploadRelationDocument()` (`:703`), añadir (espejo de `RefundsController::uploadDocument`/`deleteDocument`, gate con `canManageDocuments`):

```php
    /**
     * Sube un soporte general de la legalización (POST multipart, name="file").
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function uploadLegalizationDocument(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canManageDocuments($leg, (int)$this->_getCurrentUser()->role_id)) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse([
                    'success' => false,
                    'error' => 'No tienes permiso para esta acción en el estado actual.',
                ], 403);
            }

            return $this->_denyAction((int)$id);
        }

        $file = $this->request->getUploadedFile('file');
        if (!$file) {
            $msg = 'No se recibió ningún archivo válido.';
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => $msg], 400);
            }
            $this->Flash->error($msg);

            return $this->redirect(['action' => 'legalization', $id]);
        }

        $result = $this->documentService->uploadDocument(
            (int)$leg->id,
            $file,
            (int)$this->_getCurrentUser()->id,
        );

        if (!is_string($result)) {
            $this->historyService->recordFieldChange(
                (int)$leg->id,
                'document',
                null,
                $result->file_name,
                (int)$this->_getCurrentUser()->id,
            );
        }

        if ($this->_isJsonRequest()) {
            if (is_string($result)) {
                return $this->_jsonResponse(['success' => false, 'error' => $result], 400);
            }

            $canDelete = $leg->canManageDocuments();
            $deleteUrl = $canDelete
                ? Router::url(['action' => 'deleteLegalizationDocument', $leg->advance_invoice_id, $result->id])
                : null;

            return $this->_jsonResponse([
                'success' => true,
                'document' => $this->_buildDocumentPayload($result, $canDelete, $deleteUrl),
            ]);
        }

        if (is_string($result)) {
            $this->Flash->error($result);
        } else {
            $this->Flash->success('El soporte ha sido subido.');
        }

        return $this->redirect(['action' => 'legalization', $id]);
    }

    /**
     * Elimina un soporte general de la legalización (POST/DELETE).
     * Anti-IDOR: el service filtra por id + legalization_id.
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function deleteLegalizationDocument(?int $id = null, ?int $documentId = null): Response
    {
        $this->request->allowMethod(['post', 'delete']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canManageDocuments($leg, (int)$this->_getCurrentUser()->role_id)) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse([
                    'success' => false,
                    'error' => 'No tienes permiso para esta acción en el estado actual.',
                ], 403);
            }

            return $this->_denyAction((int)$id);
        }

        $documentsTable = TableRegistry::getTableLocator()->get('AdvanceLegalizationDocuments');
        $document = $documentsTable->find()
            ->where(['id' => $documentId, 'legalization_id' => $leg->id])
            ->first();
        $fileName = $document?->file_name;

        $deleted = $this->documentService->deleteDocument((int)$documentId, (int)$leg->id);

        if ($deleted) {
            $this->historyService->recordFieldChange(
                (int)$leg->id,
                'document',
                $fileName,
                null,
                (int)$this->_getCurrentUser()->id,
            );
        }

        if ($this->_isJsonRequest()) {
            if ($deleted) {
                return $this->_jsonResponse(['success' => true]);
            }

            return $this->_jsonResponse(['success' => false, 'error' => 'No se pudo eliminar el soporte.'], 404);
        }

        if ($deleted) {
            $this->Flash->success('El soporte ha sido eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el soporte.');
        }

        return $this->redirect(['action' => 'legalization', $id]);
    }
```

Verificar que `Cake\Routing\Router` está importado (lo usa `confirmShortage` con `Router::url`); si no, añadir `use Cake\Routing\Router;`.

- [ ] **Step 5: Añadir las rutas**

En `config/routes.php`, tras el bloque de `upload-relation-document` (`:555-559`), añadir:

```php
        $builder->connect(
            '/advances/upload-legalization-document/{id}',
            ['controller' => 'Advances', 'action' => 'uploadLegalizationDocument'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/delete-legalization-document/{id}/{documentId}',
            ['controller' => 'Advances', 'action' => 'deleteLegalizationDocument'],
            ['id' => '\d+', 'documentId' => '\d+', 'pass' => ['id', 'documentId']],
        );
```

- [ ] **Step 6: Correr el test**

Run: `vendor/bin/phpunit --filter AdvancesLegalizationDocumentTest`
Expected: PASS (3 tests).

- [ ] **Step 7: cs-check y commit**

```bash
composer cs-check
git add src/Controller/AdvancesController.php config/routes.php tests/TestCase/Controller/AdvancesLegalizationDocumentTest.php
git commit -m "feat: endpoints upload/delete de soportes de legalizacion + contain"
```

---

## Task 5: Capa de vista — card de Soportes con el cajón general

**Files:**
- Modify: `src/ViewModel/Support/LegalizationSummary.php` (exponer `documents` + `totalDocs`)
- Modify: `src/ViewModel/AdvanceLegalizationViewModel.php` (param `canManageDocuments` + claves en `build()`)
- Modify: `src/Controller/AdvancesController.php:470-500` (`_buildLegalizationViewModel` pasa `canManageDocuments`)
- Modify: `templates/element/advance_legalization/_soportes.php` (defaults, quitar bloque comprobante, añadir bloque general)
- Modify: `templates/Advances/legalization.php` (quitar `receipt_file`; actualizar llamada a `_soportes`; añadir modal/template/script)
- Modify: `templates/Advances/view.php:223-228` (actualizar llamada a `_soportes`)
- Test: `tests/TestCase/Controller/AdvancesLegalizationRenderTest.php` (añadir casos)

**Interfaces:**
- Consumes: `$leg->advance_legalization_documents` (contain de Task 4); `AdvanceLegalizationActionPolicy::canManageDocuments` (Task 3).
- Produces: `LegalizationSummary->documents` (`list<AdvanceLegalizationDocument>`), `LegalizationSummary->totalDocs` (`int`); claves de `build()`: `documents`, `totalDocs`, `canManageDocuments`.

- [ ] **Step 1: Escribir los tests de render (fallan primero)**

En `tests/TestCase/Controller/AdvancesLegalizationRenderTest.php`, añadir tres métodos. Los `use` necesarios (`AdvanceConstants`, `InvoiceConstants`, `AdvanceLegalizationFactory`, `InvoiceFactory`, `ProviderFactory`, `TableRegistry`, `UserFactory`, `RoleFactory`, `PipelineStepConstants`, `IntegrationTestTrait`) **ya están** importados en el archivo — no hace falta añadir imports:

```php
    /**
     * En un paso operable, la card Soportes muestra el botón Subir y el modal de
     * subida; nunca el bloque especial "Comprobante de consignación".
     */
    public function testSoportesCardShowsUploadWhenOperable(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD);
        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Soportes');
        $this->assertResponseContains('id="uploadLegDocModal"');
        $this->assertResponseContains('spi-document-uploader');
        // Captura el bug A1: el uploader necesita init(), no solo el <script src>.
        $this->assertResponseContains('SpiDocumentUploader.init');
        $this->assertResponseNotContains('Comprobante de consignación');
    }

    /**
     * En estado terminal (legalizada) la card Soportes es read-only: sin modal de
     * subida ni botón.
     */
    public function testSoportesCardReadOnlyWhenLegalizada(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD);
        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_LEGALIZADA)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Soportes');
        $this->assertResponseNotContains('id="uploadLegDocModal"');
    }

    /**
     * El hub de consulta (view) muestra los soportes de la legalización en
     * read-only: aparece el archivo, pero no el modal de subida.
     */
    public function testHubViewShowsSupportsReadOnly(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD);
        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $docs = TableRegistry::getTableLocator()->get('AdvanceLegalizationDocuments');
        $docs->saveOrFail($docs->newEntity([
            'legalization_id' => $leg->id,
            'file_path' => 'uploads/advances/' . $leg->id . '/leg_hub.pdf',
            'file_name' => 'soporte-hub.pdf',
            'mime_type' => 'application/pdf',
        ]));

        $this->session(['Auth' => $user]);
        $this->get('/advances/view/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Soportes');
        $this->assertResponseContains('soporte-hub.pdf');
        $this->assertResponseNotContains('id="uploadLegDocModal"');
    }
```

- [ ] **Step 2: Correr para verificar que fallan**

Run: `vendor/bin/phpunit --filter AdvancesLegalizationRenderTest`
Expected: FAIL en los casos nuevos (`uploadLegDocModal`/`soporte-hub.pdf` ausentes hasta implementar la vista).

- [ ] **Step 3: Exponer `documents`/`totalDocs` en `LegalizationSummary`**

En `src/ViewModel/Support/LegalizationSummary.php`:

1. Declarar las propiedades (tras `$signatureHistory`, `:39`):

```php
    /**
     * @var list<\App\Model\Entity\AdvanceLegalizationDocument>
     */
    public array $documents;
    public int $totalDocs;
```

2. En el constructor, tras asignar `$this->signatureHistory` (`:87`), añadir:

```php
        $this->documents = $leg->advance_legalization_documents ?? [];
        $this->totalDocs = count($this->documents);
```

- [ ] **Step 4: Añadir `canManageDocuments` al `AdvanceLegalizationViewModel`**

En `src/ViewModel/AdvanceLegalizationViewModel.php`:

1. Añadir el parámetro al constructor, tras `public bool $canConfirmShortage = false,` (`:105`):

```php
        public bool $canManageDocuments = false,
```

2. En `build()`, dentro del array de retorno (tras `'canConfirmShortage' => $this->canConfirmShortage,`, `:172`), añadir:

```php
            'canManageDocuments' => $this->canManageDocuments,
            'documents' => $summary->documents,
            'totalDocs' => $summary->totalDocs,
```

- [ ] **Step 5: Pasar `canManageDocuments` desde `_buildLegalizationViewModel`**

En `src/Controller/AdvancesController.php`, en `_buildLegalizationViewModel` (`:499`), tras `canConfirmShortage: …`, añadir:

```php
            canManageDocuments: $this->actionPolicy->canManageDocuments($leg, $roleId),
```

- [ ] **Step 6: Reescribir `_soportes.php`**

En `templates/element/advance_legalization/_soportes.php`:

1. En el bloque de defaults (tras `$editable = $editable ?? true;`, `:16`), añadir:

```php
$documents = $documents ?? [];
$totalDocs = (int)($totalDocs ?? 0);
$canManageDocuments = $canManageDocuments ?? false;
```

2. Eliminar íntegro el bloque especial «Comprobante de consignación» (`:109-136`, desde el comentario `<!-- Documento especial: Comprobante de consignación (caso faltante) -->` hasta su `<?php endif; ?>`).

3. Eliminar íntegro el empty-state global (`:174-181`, desde `<?php if (!$relationDocument && empty($signatureHistory) && !$leg->shortage_receipt_path): ?>` hasta su `<?php endif; ?>`). Queda cubierto por el empty-state del bloque general (paso 4).

4. Insertar el bloque general «Soportes» **entre** el bloque de «Relación de facturas» y el de «Historial de firmas» (es decir, justo antes del comentario `<!-- Documento especial: Historial de firmas rechazadas -->`, `:138`):

```php
    <!-- Soportes generales -->
    <div class="d-flex align-items-center justify-content-between"
         style="padding:.3rem .5rem;background:var(--bg-subtle);margin-top:.5rem;">
        <span class="pill pill-secondary-soft">Soportes</span>
        <div class="d-flex align-items-center gap-2">
            <span<?= $canManageDocuments ? ' id="docs-folder-count"' : '' ?> class="spi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
            <?php if ($canManageDocuments): ?>
            <button type="button" class="btn btn-default btn-sm"
                    data-bs-toggle="modal" data-bs-target="#uploadLegDocModal">
                <i class="bi bi-upload" aria-hidden="true"></i>Subir
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($canManageDocuments): ?>
    <div id="docs-empty-state" class="dropzone" data-bs-toggle="modal" data-bs-target="#uploadLegDocModal"
         style="cursor:pointer;margin:6px 0;<?= $totalDocs > 0 ? 'display:none;' : '' ?>">
        <i class="bi bi-paperclip" aria-hidden="true"></i>
        <div>Arrastra archivos o <a class="dz-link">examina</a></div>
        <div class="dz-hint">PDF, imágenes, Word o Excel · máximo 10 MB</div>
    </div>
    <?php elseif ($totalDocs === 0): ?>
    <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);margin:6px 0;">
        <span class="spi-body-faint" style="font-size:var(--fs-body-sm);">Sin soportes adjuntos</span>
    </div>
    <?php endif; ?>
    <div<?= $canManageDocuments ? ' id="docs-list"' : '' ?>>
        <?php foreach ($documents as $doc): ?>
        <?= $this->element('document_row', [
            'doc' => $doc,
            'canDelete' => $canManageDocuments,
            'deleteUrl' => $canManageDocuments
                ? $this->Url->build(['action' => 'deleteLegalizationDocument', $leg->advance_invoice_id, $doc->id])
                : '',
            'showBadge' => false,
        ]) ?>
        <?php endforeach; ?>
    </div>
```

**IDs de contrato condicionados (evita colisión en el hub).** Los IDs `docs-folder-count`, `docs-empty-state` y `docs-list` se emiten **solo cuando `$canManageDocuments`**. Motivo: `Advances/view.php:147` ya renderiza un `documents_section` para los documentos del Anticipo con esos MISMOS IDs; como `view.php` pasa `canManageDocuments => false`, el cajón de legalización queda sin esos IDs y no hay IDs duplicados en el hub. En `legalization.php` no hay `documents_section`, así que su único set de IDs es el del cajón general (que es lo que el uploader JS engancha).

- [ ] **Step 7: Actualizar `legalization.php`**

En `templates/Advances/legalization.php`:

1. En el form «Confirmar consignación», eliminar el `<div class="col-md-5">` con el input de archivo (`:344-347`):

```php
                <div class="col-md-5">
                    <label class="input-label">Soporte (PDF / imagen)</label>
                    <input type="file" name="receipt_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                </div>
```

Y, en el `<form id="confirm-shortage-form" …>` (`:332-334`), quitar `enctype="multipart/form-data"` (ya no sube archivo). El `data-shortage-url` y el resto del form no se tocan.

2. Actualizar la llamada al element `_soportes` (`:453-458`) para pasar los tres params nuevos:

```php
    <?= $this->element('advance_legalization/_soportes', [
        'leg' => $leg,
        'relationDocument' => $relationDocument,
        'signatureHistory' => $signatureHistory,
        'editable' => $canUploadRelationDocument,
        'documents' => $documents,
        'totalDocs' => $totalDocs,
        'canManageDocuments' => $canManageDocuments,
    ]) ?>
```

3. Añadir las tres claves nuevas a la lista de desestructuración del `$viewModel->build()` de la cabecera (tras `'canConfirmShortage' => $canConfirmShortage,`, `:54`):

```php
    'canManageDocuments' => $canManageDocuments,
    'documents' => $documents,
    'totalDocs' => $totalDocs,
```

4. Añadir el modal, el template de fila, el `<script src>` del uploader **y su invocación `init()`**, condicionados a `canManageDocuments`, junto a los otros modales del final de la vista (p. ej. tras el `regress_status_modal`, `:520`). **Sin el bloque `init()` el uploader queda muerto**: `Html->script('spi-document-uploader')` solo define `window.SpiDocumentUploader`; hay que llamar `init()` con los selectores (espejo de `templates/Refunds/edit.php:516-529`). `init()` hace `return` temprano si falta `formSelector`/`rowTemplateSelector`. El contenedor `_soportes.php` es `.spi-card` sin `.card`, así que el contador se resuelve por el fallback `counterSelector: '.spi-folder-count'` (único en esta vista):

```php
<?php if ($canManageDocuments): ?>
<?= $this->element('upload_doc_modal', [
    'modalId'   => 'uploadLegDocModal',
    'uploadUrl' => $this->Url->build(['action' => 'uploadLegalizationDocument', $leg->advance_invoice_id]),
    'showDocumentType' => false,
]) ?>
<?= $this->element('document_row_template', ['showBadge' => false]) ?>
<?= $this->Html->script('spi-document-uploader', ['block' => true]) ?>
<?php $this->append('script') ?>
<script>
(function(){
    SpiDocumentUploader.init({
        formSelector:        '#upload-doc-form',
        listSelector:        '#docs-list',
        emptySelector:       '#docs-empty-state',
        counterSelector:     '.spi-folder-count',
        rowTemplateSelector: '#doc-row-template',
        modalSelector:       '#uploadLegDocModal',
        csrfToken:           <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
    });
})();
</script>
<?php $this->end() ?>
<?php endif; ?>
```

Este bloque va **antes** del `<?php $this->append('script') ?>` existente (`:522`), de modo que en el bloque `script` el `<script src>` (de `Html->script`) queda antes del `init()` y ambos antes del IIFE ya presente. Como `document_row_template` renderiza el `<template id="doc-row-template">`, `rowTemplateSelector` es `#doc-row-template`.

- [ ] **Step 8: Actualizar `view.php` (hub read-only)**

En `templates/Advances/view.php`, actualizar la llamada al element `_soportes` (`:223-228`):

```php
        <?= $this->element('advance_legalization/_soportes', [
            'leg' => $leg,
            'relationDocument' => $sum->relationDocument,
            'signatureHistory' => $sum->signatureHistory,
            'editable' => false,
            'documents' => $sum->documents,
            'totalDocs' => $sum->totalDocs,
            'canManageDocuments' => false,
        ]) ?>
```

- [ ] **Step 9: Correr los tests de render**

Run: `vendor/bin/phpunit --filter AdvancesLegalizationRenderTest`
Expected: PASS (todos, incluidos los dos nuevos).

- [ ] **Step 10: cs-check y commit**

```bash
composer cs-check
git add src/ViewModel/Support/LegalizationSummary.php src/ViewModel/AdvanceLegalizationViewModel.php src/Controller/AdvancesController.php templates/element/advance_legalization/_soportes.php templates/Advances/legalization.php templates/Advances/view.php tests/TestCase/Controller/AdvancesLegalizationRenderTest.php
git commit -m "feat: card de soportes generales en la vista de legalizacion (operativo y hub)"
```

---

## Task 6: Migración B — drop de `shortage_receipt_path`

**Files:**
- Create: `config/Migrations/20260710120100_DropShortageReceiptPathFromAdvanceLegalizations.php`
- Modify: `src/Model/Table/AdvanceLegalizationsTable.php:112-115` (quitar validación)

**Interfaces:**
- Consumes: nada más referencia `shortage_receipt_path` (verificado: service en Task 2, entity en Task 3, templates en Task 5).

- [ ] **Step 1: Verificar que no quedan referencias vivas**

Run (grep): buscar `shortage_receipt_path` en `src/` y `templates/`.
Expected: solo aparece en `config/Migrations/20260429143219_CreateAdvanceLegalizations.php` (la columna original) y en la validación de `AdvanceLegalizationsTable.php:112-115` (que se quita en el Step 3). Si aparece en cualquier otro `src/`/`templates/`, detenerse y limpiarlo antes de dropear.

- [ ] **Step 2: Escribir la migración**

Create `config/Migrations/20260710120100_DropShortageReceiptPathFromAdvanceLegalizations.php`:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class DropShortageReceiptPathFromAdvanceLegalizations extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('advance_legalizations');
        if ($table->hasColumn('shortage_receipt_path')) {
            $table->removeColumn('shortage_receipt_path')->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('advance_legalizations');
        if (!$table->hasColumn('shortage_receipt_path')) {
            $table->addColumn('shortage_receipt_path', 'string', [
                'limit' => 500,
                'null' => true,
                'default' => null,
            ])->update();
        }
    }
}
```

- [ ] **Step 3: Quitar la validación de la Table**

En `src/Model/Table/AdvanceLegalizationsTable.php`, eliminar el bloque (`:112-115`):

```php
        $validator
            ->scalar('shortage_receipt_path')
            ->maxLength('shortage_receipt_path', 500)
            ->allowEmptyString('shortage_receipt_path');
```

(Las reglas de `shortage_receipt_number` `:107-110` y `shortage_received_at` `:103-105` se conservan.)

- [ ] **Step 4: Correr la migración**

Run: `php bin/cake migrations migrate`
Expected: `== DropShortageReceiptPathFromAdvanceLegalizations: migrated`.

- [ ] **Step 5: Correr la batería de regresión del módulo**

Run: `vendor/bin/phpunit --filter AdvanceLegalization`
Expected: PASS (shortage, surplus, transitions, render, document, table, predicate). Ninguno depende ya de `shortage_receipt_path`.

- [ ] **Step 6: cs-check y commit**

```bash
composer cs-check
git add config/Migrations/20260710120100_DropShortageReceiptPathFromAdvanceLegalizations.php src/Model/Table/AdvanceLegalizationsTable.php
git commit -m "refactor: drop de la columna especial shortage_receipt_path"
```

---

## Cierre

- [ ] **Verificación final de la suite**

Run: `vendor/bin/phpunit`
Expected: verde salvo notices preexistentes de la baseline (ver memoria `correr-suite-tests-sgi`). Ningún fallo nuevo atribuible a este trabajo.

Run: `composer cs-check`
Expected: sin violaciones en los archivos tocados.

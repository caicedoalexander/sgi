# Soportes Documentales en Reintegros — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Añadir gestión de soportes documentales (subida/eliminación AJAX) al módulo Reintegros, replicando el patrón de PettyCashRecords.

**Architecture:** Tabla `refund_documents` espejo de `petty_cash_documents`; servicio `RefundDocumentService` que reutiliza `DocumentUploadTrait`; acciones `uploadDocument`/`deleteDocument` en `RefundsController` con respuestas JSON via `DocumentJsonPayloadTrait`; UI con card "Soportes" sobre Observaciones en `edit.php` enchufada al helper unificado `SgiDocumentUploader`.

**Tech Stack:** CakePHP 5.3, PHP 8.2+, MySQL/MariaDB, Bootstrap 5, `webroot/js/sgi-document-uploader.js` (helper AJAX existente).

**Spec:** `docs/superpowers/specs/2026-05-03-refund-documents-design.md`

**Política de tests:** Este proyecto NO usa tests automatizados. La validación es manual (ver Task 9).

---

## File Structure

| Archivo | Acción | Responsabilidad |
|---|---|---|
| `config/Migrations/<timestamp>_CreateRefundDocuments.php` | Crear | Esquema de tabla `refund_documents` |
| `src/Model/Entity/RefundDocument.php` | Crear | Entity (campos accesibles) |
| `src/Model/Table/RefundDocumentsTable.php` | Crear | ORM (asociaciones, validación, timestamp) |
| `src/Model/Table/RefundsTable.php` | Modificar | Añadir `hasMany RefundDocuments` |
| `src/Service/RefundDocumentService.php` | Crear | Subida/eliminación de soportes (usa `DocumentUploadTrait`) |
| `src/Application.php` | Modificar | Registrar `RefundDocumentService` en el contenedor DI |
| `src/Controller/RefundsController.php` | Modificar | Acciones `uploadDocument`/`deleteDocument`, contain en `edit`/`view`, trait JSON |
| `templates/Refunds/edit.php` | Modificar | Insertar card "Soportes" + modal + script init |
| `templates/Refunds/view.php` | Modificar | Listado read-only de soportes |

---

## Task 1: Migración `CreateRefundDocuments`

**Files:**
- Create: `config/Migrations/<timestamp>_CreateRefundDocuments.php`

- [ ] **Step 1: Generar el esqueleto de la migración**

```bash
php bin/cake migrations create CreateRefundDocuments
```

- [ ] **Step 2: Reemplazar el contenido del archivo generado**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateRefundDocuments extends BaseMigration
{
    public function change(): void
    {
        if ($this->hasTable('refund_documents')) {
            return;
        }

        $table = $this->table('refund_documents');

        $table->addColumn('refund_id', 'integer', [
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

        $table->addIndex(['refund_id']);

        $table->addForeignKey('refund_id', 'refunds', 'id', [
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

- [ ] **Step 3: Ejecutar la migración**

```bash
php bin/cake migrations migrate
```

Esperado: salida con `== CreateRefundDocuments: migrated`. Verificar la tabla:

```bash
php bin/cake migrations status | grep refund_documents
```

- [ ] **Step 4: Commit**

```bash
git add config/Migrations/*CreateRefundDocuments.php
git commit -m "feat(refunds): migración refund_documents para soportes"
```

---

## Task 2: Entity `RefundDocument`

**Files:**
- Create: `src/Model/Entity/RefundDocument.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class RefundDocument extends Entity
{
    protected array $_accessible = [
        'refund_id' => true,
        'document_type' => true,
        'file_path' => true,
        'file_name' => true,
        'file_size' => true,
        'mime_type' => true,
        'uploaded_by' => true,
        'created' => true,
        'modified' => true,
        'refund' => true,
        'uploaded_by_user' => true,
    ];
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Model/Entity/RefundDocument.php
git commit -m "feat(refunds): entity RefundDocument"
```

---

## Task 3: Table `RefundDocumentsTable`

**Files:**
- Create: `src/Model/Table/RefundDocumentsTable.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class RefundDocumentsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('refund_documents');
        $this->setDisplayField('file_name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Refunds', [
            'foreignKey' => 'refund_id',
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
            ->integer('refund_id')
            ->requirePresence('refund_id', 'create')
            ->notEmptyString('refund_id');

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

- [ ] **Step 2: Commit**

```bash
git add src/Model/Table/RefundDocumentsTable.php
git commit -m "feat(refunds): table RefundDocumentsTable"
```

---

## Task 4: Asociación `hasMany` en `RefundsTable`

**Files:**
- Modify: `src/Model/Table/RefundsTable.php` (después del bloque `hasMany('RefundObservations', ...)`)

- [ ] **Step 1: Localizar el bloque de asociaciones**

```bash
grep -n "RefundObservations" src/Model/Table/RefundsTable.php
```

Esperado: una coincidencia tipo `$this->hasMany('RefundObservations', [...]);`.

- [ ] **Step 2: Añadir la asociación inmediatamente después de `RefundObservations`**

Insertar el siguiente bloque justo debajo del cierre de `hasMany('RefundObservations', ...)`:

```php
        $this->hasMany('RefundDocuments', [
            'foreignKey' => 'refund_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
```

- [ ] **Step 3: Verificar sintaxis**

```bash
php -l src/Model/Table/RefundsTable.php
```

Esperado: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add src/Model/Table/RefundsTable.php
git commit -m "feat(refunds): asociar hasMany RefundDocuments"
```

---

## Task 5: `RefundDocumentService`

**Files:**
- Create: `src/Service/RefundDocumentService.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Trait\DocumentUploadTrait;
use Laminas\Diactoros\UploadedFile;

class RefundDocumentService
{
    use DocumentUploadTrait;

    /**
     * Sube un soporte para un reintegro.
     *
     * @return object|string Entity en éxito, mensaje string en error.
     */
    public function uploadDocument(
        int $refundId,
        UploadedFile $file,
        ?int $uploadedBy,
        ?string $documentType = null,
    ): object|string {
        return $this->uploadAndSave($file, 'RefundDocuments', 'refunds/' . $refundId, 'rf_', [
            'refund_id' => $refundId,
            'document_type' => $documentType,
            'uploaded_by' => $uploadedBy,
        ]);
    }

    public function deleteDocument(int $documentId): bool
    {
        return $this->deleteDocumentRecord('RefundDocuments', $documentId);
    }
}
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l src/Service/RefundDocumentService.php
```

- [ ] **Step 3: Commit**

```bash
git add src/Service/RefundDocumentService.php
git commit -m "feat(refunds): RefundDocumentService"
```

---

## Task 6: Registrar servicio en el contenedor DI

**Files:**
- Modify: `src/Application.php` (importes y bloque `=== Petty cash / payment scheduling / advances ===`)

- [ ] **Step 1: Añadir el import**

Buscar la línea `use App\Service\RefundService;` y añadir inmediatamente después:

```php
use App\Service\RefundDocumentService;
```

- [ ] **Step 2: Registrar en el contenedor**

Localizar el bloque:

```php
        $container->addShared(RefundService::class)
            ->addArguments([
                InvoiceHistoryService::class,
                PipelineAuthorizationService::class,
            ]);
```

Insertar justo encima:

```php
        $container->addShared(RefundDocumentService::class);
```

- [ ] **Step 3: Verificar sintaxis**

```bash
php -l src/Application.php
```

- [ ] **Step 4: Commit**

```bash
git add src/Application.php
git commit -m "feat(refunds): registrar RefundDocumentService en DI"
```

---

## Task 7: Controller — acciones y contain

**Files:**
- Modify: `src/Controller/RefundsController.php`

- [ ] **Step 1: Añadir imports y traits**

Localizar el bloque `use` superior y añadir:

```php
use App\Controller\Trait\DocumentJsonPayloadTrait;
use App\Service\RefundDocumentService;
use Cake\Routing\Router;
```

(Si alguno ya existe, omitirlo.)

Localizar la línea `use ObservationControllerTrait;` dentro de la clase y reemplazarla por:

```php
    use ObservationControllerTrait;
    use DocumentJsonPayloadTrait;
```

- [ ] **Step 2: Añadir propiedad y resolución desde el contenedor**

Localizar:

```php
    private RefundService $refundService;
    private PipelineAuthorizationService $pipelineAuth;
```

Añadir debajo:

```php
    private RefundDocumentService $documentService;
```

En `initialize()`, después de `$this->pipelineAuth = $container->get(PipelineAuthorizationService::class);`, añadir:

```php
        $this->documentService = $container->get(RefundDocumentService::class);
```

- [ ] **Step 3: Extender `contain` en `view()`**

Localizar el array de `contain` en `view($id = null)` y añadirle la entrada:

```php
            'RefundDocuments' => ['UploadedByUsers'],
```

(Insertar como un elemento más del array, manteniendo el resto intacto.)

- [ ] **Step 4: Extender `contain` en `edit()`**

Localizar el array de `contain` en `edit($id = null)` (alrededor de las líneas 213-225) y añadirle:

```php
            'RefundDocuments' => ['UploadedByUsers'],
```

- [ ] **Step 5: Añadir las acciones al final de la clase**

Insertar inmediatamente antes de la llave de cierre `}` de la clase:

```php
    public function uploadDocument($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->Refunds->get($id);

        if ($record->isPagado()) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse([
                    'success' => false,
                    'error' => 'No se puede subir soportes a un reintegro pagado.',
                ]);
            }
            $this->Flash->error('No se puede subir soportes a un reintegro pagado.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $file = $this->request->getUploadedFile('file');
        if (!$file) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse([
                    'success' => false,
                    'error' => 'No se recibió ningún archivo válido.',
                ]);
            }
            $this->Flash->error('No se recibió ningún archivo válido.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $identity = $this->Authentication->getIdentity();
        $result = $this->documentService->uploadDocument(
            (int)$id,
            $file,
            $identity ? (int)$identity->getIdentifier() : null,
            $this->request->getData('document_type'),
        );

        if ($this->_isJsonRequest()) {
            if (is_string($result)) {
                return $this->_jsonResponse(['success' => false, 'error' => $result]);
            }

            $canDelete = !$record->isPagado();
            $deleteUrl = $canDelete
                ? Router::url(['action' => 'deleteDocument', $id, $result->id])
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

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function deleteDocument($refundId = null, $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $record = $this->Refunds->get($refundId);

        if ($record->isPagado()) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse([
                    'success' => false,
                    'error' => 'No se puede eliminar un soporte de un reintegro pagado.',
                ]);
            }
            $this->Flash->error('No se puede eliminar un soporte de un reintegro pagado.');

            return $this->redirect(['action' => 'edit', $refundId]);
        }

        $deleted = $this->documentService->deleteDocument((int)$documentId);

        if ($this->_isJsonRequest()) {
            return $this->_jsonResponse(
                $deleted
                    ? ['success' => true]
                    : ['success' => false, 'error' => 'No se pudo eliminar el soporte.'],
            );
        }

        if ($deleted) {
            $this->Flash->success('El soporte ha sido eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el soporte.');
        }

        return $this->redirect(['action' => 'edit', $refundId]);
    }
```

- [ ] **Step 6: Verificar sintaxis y estilo**

```bash
php -l src/Controller/RefundsController.php
composer cs-check -- src/Controller/RefundsController.php
```

Si `cs-check` reporta issues, ejecutar:

```bash
composer cs-fix -- src/Controller/RefundsController.php
```

- [ ] **Step 7: Commit**

```bash
git add src/Controller/RefundsController.php
git commit -m "feat(refunds): acciones uploadDocument/deleteDocument con JSON branch"
```

---

## Task 8: Templates — `edit.php` y `view.php`

**Files:**
- Modify: `templates/Refunds/edit.php` (insertar card antes del card de Observaciones, línea ~537)
- Modify: `templates/Refunds/view.php` (insertar listado read-only de soportes)

- [ ] **Step 1: Insertar card "Soportes" en `edit.php`**

Localizar el comentario `<!-- Observaciones: chat -->` (cerca de la línea 537). Insertar **inmediatamente antes** (entre `<div class="sgi-invoice-sidebar">` y el comentario de observaciones) este bloque:

```php
<?php $docs = $record->refund_documents ?? []; ?>
<div class="card card-primary mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip" style="font-size:.85rem;"></i>
            <span style="font-size:.85rem;font-weight:600;">Soportes</span>
            <span class="sgi-folder-count"><?= count($docs) ?> doc<?= count($docs) !== 1 ? 's' : '' ?></span>
        </span>
        <?php if (!$record->isPagado()): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadRefundDocModal">
            <i class="bi bi-upload me-1"></i>Subir
        </button>
        <?php endif; ?>
    </div>

    <div id="docs-empty-state" style="padding:2rem 1rem;text-align:center;color:#c8c8c8;<?= !empty($docs) ? 'display:none;' : '' ?>">
        <i class="bi bi-file-earmark-x d-block mb-2" style="font-size:1.5rem;"></i>
        <span style="font-size:.8rem;">Sin soportes adjuntos</span>
    </div>
    <div id="docs-list" style="max-height:420px;overflow-y:auto;">
        <?php foreach ($docs as $doc): ?>
            <?= $this->element('document_row', [
                'doc'       => $doc,
                'canDelete' => !$record->isPagado(),
                'deleteUrl' => $this->Url->build(['action' => 'deleteDocument', $record->id, $doc->id]),
                'showBadge' => false,
            ]) ?>
        <?php endforeach; ?>
    </div>
</div>
```

- [ ] **Step 2: Insertar el modal y el script init en `edit.php`**

Localizar `</div><!-- /columna derecha -->` (cerca de la línea 573). Insertar **inmediatamente después** el siguiente bloque:

```php
<?php if (!$record->isPagado()): ?>
<!-- Modal: Subir Soporte -->
<div class="modal fade" id="uploadRefundDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="upload-doc-form"
                  data-url="<?= $this->Url->build(['action' => 'uploadDocument', $record->id]) ?>"
                  enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Subir Soporte</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tipo de Documento (opcional)</label>
                        <input type="text" name="document_type" class="form-control" placeholder="Ej. Soporte causación, Comprobante...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Archivo</label>
                        <input type="file" name="file" class="form-control" required accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx">
                        <div class="form-text">Máximo 20 MB — PDF, imágenes, Word o Excel.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Subir</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?= $this->element('document_row_template', ['showBadge' => false]) ?>
<?= $this->Html->script('sgi-document-uploader', ['block' => true]) ?>

<?php $this->append('script') ?>
<script>
(function(){
    SgiDocumentUploader.init({
        formSelector:        '#upload-doc-form',
        listSelector:        '#docs-list',
        emptySelector:       '#docs-empty-state',
        counterSelector:     '.card.card-primary .card-header .sgi-folder-count',
        rowTemplateSelector: '#doc-row-template',
        modalSelector:       '#uploadRefundDocModal',
        csrfToken:           <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
    });
})();
</script>
<?php $this->end() ?>
```

**Nota:** Si en `edit.php` ya existe un bloque `<?php $this->append('script') ?> ... <?php $this->end() ?>` previo (por ejemplo para observaciones), **no** duplicar la apertura del bloque — fusionar el contenido del `<script>` dentro del bloque existente. Ejecutar:

```bash
grep -n "this->append('script')\|this->end()" templates/Refunds/edit.php
```

para confirmar la presencia previa del bloque y ajustar.

- [ ] **Step 3: Añadir listado read-only en `view.php`**

Localizar una posición razonable en `templates/Refunds/view.php` (típicamente en la columna derecha o al final del bloque principal — buscar una sección análoga a Observaciones o, si no existe, añadir al final del template antes del cierre del contenedor principal).

```bash
grep -n "Observaciones\|sgi-obs\|sgi-invoice-sidebar" templates/Refunds/view.php
```

Insertar el siguiente bloque justo antes del card de Observaciones (o al final si no hay sidebar):

```php
<?php $docs = $record->refund_documents ?? []; ?>
<div class="card card-primary mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip" style="font-size:.85rem;"></i>
            <span style="font-size:.85rem;font-weight:600;">Soportes</span>
            <span class="sgi-folder-count"><?= count($docs) ?> doc<?= count($docs) !== 1 ? 's' : '' ?></span>
        </span>
    </div>
    <?php if (empty($docs)): ?>
        <div style="padding:2rem 1rem;text-align:center;color:#c8c8c8;">
            <i class="bi bi-file-earmark-x d-block mb-2" style="font-size:1.5rem;"></i>
            <span style="font-size:.8rem;">Sin soportes adjuntos</span>
        </div>
    <?php else: ?>
        <div style="max-height:420px;overflow-y:auto;">
            <?php foreach ($docs as $doc): ?>
                <?= $this->element('document_row', [
                    'doc'       => $doc,
                    'canDelete' => false,
                    'deleteUrl' => null,
                    'showBadge' => false,
                ]) ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
```

- [ ] **Step 4: Verificar sintaxis PHP de los templates**

```bash
php -l templates/Refunds/edit.php
php -l templates/Refunds/view.php
```

- [ ] **Step 5: Commit**

```bash
git add templates/Refunds/edit.php templates/Refunds/view.php
git commit -m "feat(refunds): card de soportes con modal AJAX en edit y vista read-only"
```

---

## Task 9: Validación manual

**Pre-requisitos:** servidor levantado por el usuario (`php bin/cake server`), sesión iniciada con un rol con permisos sobre Reintegros.

- [ ] **Step 1: Subida básica**

1. Abrir un reintegro en estado `agrupacion`.
2. Click "Subir" → seleccionar un PDF → enviar.
3. Verificar: aparece la fila sin recargar; contador del card incrementa; modal se cierra.
4. Refrescar la página → la fila persiste.
5. Verificar archivo físico en `webroot/uploads/refunds/<refund_id>/`.

- [ ] **Step 2: Múltiples tipos de archivo**

Subir un `.png` y un `.xlsx`. Verificar que ambos aparecen con el ícono correcto (imagen / Excel).

- [ ] **Step 3: Validaciones de archivo**

1. Intentar subir un `.zip` → mensaje de error "Tipo de archivo no permitido."
2. Intentar subir un archivo > 20 MB → mensaje "El archivo excede el tamaño máximo de 20 MB."

- [ ] **Step 4: Eliminación**

1. Click en eliminar sobre una fila → confirmar.
2. Verificar que la fila desaparece sin recargar y el contador decrementa.
3. Verificar que el archivo físico fue removido del disco.

- [ ] **Step 5: Guard de estado pagado**

1. Avanzar el reintegro hasta `pagado` (a través del pipeline normal).
2. Recargar `edit`. Verificar:
   - Botón "Subir" oculto.
   - Botones de eliminar ausentes en filas existentes.
3. (Opcional, defensa servidor) Forzar un POST directo:

```bash
curl -X POST -H "X-Requested-With: XMLHttpRequest" \
  -F "file=@/tmp/test.pdf" \
  http://localhost:8765/refunds/upload-document/<id_pagado>
```

Esperado: respuesta JSON `{"success":false,"error":"No se puede subir soportes a un reintegro pagado."}` (o redirect con flash si la sesión bloquea CSRF; el guard del controller corre igualmente).

- [ ] **Step 6: View read-only**

Abrir el reintegro en `view`. Verificar que la lista de soportes se muestra sin botones de subir/eliminar.

- [ ] **Step 7: Cascade al eliminar reintegro**

1. Crear un reintegro nuevo en `agrupacion`, subir un soporte.
2. Eliminar el reintegro.
3. Verificar en BD: `SELECT * FROM refund_documents WHERE refund_id = <id_eliminado>;` → 0 filas.
4. Verificar que el archivo físico se borró (vía `cascadeCallbacks` y `dependent`).

- [ ] **Step 8: Commit final si quedan ajustes menores**

Si en la validación se detecta algún ajuste menor (texto, espaciado), aplicarlo y commitear:

```bash
git add -A
git commit -m "fix(refunds): ajustes menores tras validación manual de soportes"
```

---

## Self-Review

**Spec coverage:**
- §1 Migración → Task 1 ✓
- §2 Entity → Task 2 ✓
- §3 Table → Task 3 ✓
- §4 Asociación hasMany → Task 4 ✓
- §5 Servicio → Task 5 ✓
- §6 DI → Task 6 ✓
- §7 Controller (trait + acciones + contain) → Task 7 ✓
- §8 Template edit → Task 8 (Steps 1-2) ✓
- §9 Template view → Task 8 (Step 3) ✓
- §10 Permisos / routing → cubierto por RBAC existente, sin cambios (validado en Task 9 Step 5) ✓
- Validación manual → Task 9 ✓

**Placeholders:** ninguno detectado.

**Type/name consistency:**
- Tabla: `refund_documents` (consistente).
- FK: `refund_id` (consistente entre migración, entity, table, service, asociación).
- Modal id: `#uploadRefundDocModal` (consistente entre el botón disparador y el `modalSelector` del init JS).
- Service alias en table locator: `RefundDocuments` (consistente entre asociación, service y `_buildDocumentPayload`).

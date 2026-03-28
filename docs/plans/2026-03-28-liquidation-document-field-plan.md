# Campo Dedicado de Documento de Liquidación — Plan de Implementación

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Agregar un campo dedicado para el documento de liquidación en el cuadro de soportes (upload inicial + update en estados posteriores) y mover la firma del empleado de `revision_firmas` a `gdp`.

**Architecture:** Se agrega columna `document_type` a `novelty_documents` para diferenciar el documento de liquidación de los soportes genéricos. Se extiende `NoveltyDocumentService` con 3 métodos nuevos. Se agregan 2 actions al controlador. Se modifica la validación de firmas en `NoveltyPipelineService` para mover la firma del trabajador a GDP.

**Tech Stack:** CakePHP 5.3, PHP 8.2, MySQL/MariaDB, Bootstrap 5

---

### Task 1: Migración — Agregar columna `document_type` a `novelty_documents`

**Files:**
- Create: `config/Migrations/20260328000001_AddDocumentTypeToNoveltyDocuments.php`

**Step 1: Crear la migración**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddDocumentTypeToNoveltyDocuments extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('novelty_documents');
        $table->addColumn('document_type', 'string', [
            'limit' => 20,
            'default' => 'support',
            'null' => false,
            'after' => 'liquidation_doc_id',
        ]);
        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('novelty_documents');
        $table->removeColumn('document_type');
        $table->update();
    }
}
```

**Step 2: Ejecutar la migración**

Run: `php bin/cake migrations migrate`
Expected: Migración exitosa, columna `document_type` agregada con default `'support'`.

**Step 3: Commit**

```bash
git add config/Migrations/20260328000001_AddDocumentTypeToNoveltyDocuments.php
git commit -m "feat: add document_type column to novelty_documents table"
```

---

### Task 2: Constante de tipo de documento

**Files:**
- Modify: `src/Constants/NoveltyConstants.php`

**Step 1: Agregar constantes de document_type**

Después de la línea de `PAYMENT_LABELS` (línea ~132), agregar:

```php
// Document types (for novelty_documents)
public const DOC_TYPE_SUPPORT = 'support';
public const DOC_TYPE_LIQUIDATION = 'liquidation_document';
```

**Step 2: Commit**

```bash
git add src/Constants/NoveltyConstants.php
git commit -m "feat: add document type constants for liquidation document"
```

---

### Task 3: Métodos de servicio en `NoveltyDocumentService`

**Files:**
- Modify: `src/Service/NoveltyDocumentService.php`

**Step 1: Agregar método `getLiquidationDocument`**

Agregar al final de la clase (antes del `}` final):

```php
/**
 * Get the liquidation document for a group.
 *
 * @param int $liquidationDocId Liquidation document ID.
 * @return object|null
 */
public function getLiquidationDocument(int $liquidationDocId): ?object
{
    $documentsTable = TableRegistry::getTableLocator()->get('NoveltyDocuments');

    return $documentsTable->find()
        ->where([
            'liquidation_doc_id' => $liquidationDocId,
            'document_type' => \App\Constants\NoveltyConstants::DOC_TYPE_LIQUIDATION,
        ])
        ->contain(['UploadedByUsers'])
        ->first();
}
```

**Step 2: Agregar método `uploadLiquidationDocument`**

```php
/**
 * Upload the liquidation document (first time).
 *
 * @param int $liquidationDocId Liquidation document ID.
 * @param \Laminas\Diactoros\UploadedFile $file Uploaded file.
 * @param int|null $uploadedBy User ID.
 * @return object|string
 */
public function uploadLiquidationDocument(
    int $liquidationDocId,
    UploadedFile $file,
    ?int $uploadedBy,
): object|string {
    // Check if one already exists
    $existing = $this->getLiquidationDocument($liquidationDocId);
    if ($existing) {
        return 'Ya existe un documento de liquidación. Use la opción de actualizar.';
    }

    return $this->upload($file, 'liquidation', $uploadedBy, 'novelty_liquidations/' . $liquidationDocId, [
        'liquidation_doc_id' => $liquidationDocId,
        'document_type' => \App\Constants\NoveltyConstants::DOC_TYPE_LIQUIDATION,
    ]);
}
```

**Step 3: Agregar método `updateLiquidationDocument`**

```php
/**
 * Update (replace) the liquidation document.
 *
 * @param int $liquidationDocId Liquidation document ID.
 * @param \Laminas\Diactoros\UploadedFile $file Uploaded file.
 * @param int|null $uploadedBy User ID.
 * @return object|string
 */
public function updateLiquidationDocument(
    int $liquidationDocId,
    UploadedFile $file,
    ?int $uploadedBy,
): object|string {
    $existing = $this->getLiquidationDocument($liquidationDocId);
    if (!$existing) {
        return 'No existe un documento de liquidación para actualizar.';
    }

    // Validate new file
    if ($file->getError() !== UPLOAD_ERR_OK) {
        return 'No se recibió ningún archivo válido.';
    }
    if ($file->getSize() > self::MAX_DOC_SIZE) {
        return 'El archivo excede el tamaño máximo de 10MB.';
    }
    $mimeType = $file->getClientMediaType();
    if (!in_array($mimeType, self::ALLOWED_DOC_MIMES)) {
        return 'Tipo de archivo no permitido. Use PDF, imágenes, Word o Excel.';
    }

    // Delete old physical file
    $oldPath = WWW_ROOT . $existing->file_path;
    if (file_exists($oldPath)) {
        unlink($oldPath);
    }

    // Save new file
    $uploadDir = WWW_ROOT . 'uploads' . DS . 'novelty_liquidations' . DS . $liquidationDocId;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $originalName = $file->getClientFilename();
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $uniqueName = uniqid('nov_') . '.' . $extension;
    $filePath = $uploadDir . DS . $uniqueName;
    $file->moveTo($filePath);

    // Update record
    $documentsTable = TableRegistry::getTableLocator()->get('NoveltyDocuments');
    $existing->file_path = 'uploads/novelty_liquidations/' . $liquidationDocId . '/' . $uniqueName;
    $existing->file_name = $originalName;
    $existing->file_size = $file->getSize();
    $existing->mime_type = $mimeType;
    $existing->uploaded_by = $uploadedBy;

    if (!$documentsTable->save($existing)) {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        return 'No se pudo actualizar el documento.';
    }

    return $existing;
}
```

**Step 4: Modificar `getGroupDocumentsByStatus` para excluir el documento de liquidación**

Cambiar el método `getGroupDocumentsByStatus` para que solo retorne soportes genéricos (excluir `document_type = 'liquidation_document'`):

```php
public function getGroupDocumentsByStatus(int $liquidationDocId): array
{
    $documentsTable = TableRegistry::getTableLocator()->get('NoveltyDocuments');
    $documents = $documentsTable->find()
        ->where([
            'liquidation_doc_id' => $liquidationDocId,
            'document_type !=' => \App\Constants\NoveltyConstants::DOC_TYPE_LIQUIDATION,
        ])
        ->contain(['UploadedByUsers'])
        ->order(['NoveltyDocuments.created' => 'DESC'])
        ->all();

    $grouped = [];
    foreach ($documents as $doc) {
        $grouped[$doc->pipeline_status][] = $doc;
    }

    return $grouped;
}
```

**Step 5: Commit**

```bash
git add src/Service/NoveltyDocumentService.php
git commit -m "feat: add liquidation document upload/update/get methods to NoveltyDocumentService"
```

---

### Task 4: Actions del controlador

**Files:**
- Modify: `src/Controller/NoveltyLiquidationDocsController.php`

**Step 1: Agregar action `uploadLiquidationDocument`**

Agregar después de `uploadDocument` (después de línea ~247):

```php
/**
 * Upload the dedicated liquidation document.
 *
 * @param string|null $id Document ID.
 * @return \Cake\Http\Response|null
 */
public function uploadLiquidationDocument(?string $id = null)
{
    $this->request->allowMethod(['post']);
    $doc = $this->NoveltyLiquidationDocs->get($id);
    $user = $this->Authentication->getIdentity()->getOriginalData();
    $file = $this->request->getUploadedFile('liquidation_file');

    if (!$file) {
        $this->Flash->error('No se seleccionó ningún archivo.');

        return $this->redirect(['action' => 'edit', $id]);
    }

    $result = $this->documentService->uploadLiquidationDocument($doc->id, $file, $user->id);

    if (is_string($result)) {
        $this->Flash->error($result);
    } else {
        $this->Flash->success('Documento de liquidación subido exitosamente.');
    }

    return $this->redirect(['action' => 'edit', $id]);
}
```

**Step 2: Agregar action `updateLiquidationDocument`**

```php
/**
 * Update (replace) the dedicated liquidation document.
 *
 * @param string|null $id Document ID.
 * @return \Cake\Http\Response|null
 */
public function updateLiquidationDocument(?string $id = null)
{
    $this->request->allowMethod(['post']);
    $doc = $this->NoveltyLiquidationDocs->get($id);
    $user = $this->Authentication->getIdentity()->getOriginalData();

    $allowedStatuses = [
        NoveltyConstants::STATUS_CONTABILIDAD,
        NoveltyConstants::STATUS_REVISION_FIRMAS,
        NoveltyConstants::STATUS_GDP,
    ];

    if (!in_array($doc->pipeline_status, $allowedStatuses)) {
        $this->Flash->error('No se puede actualizar el documento en este estado.');

        return $this->redirect(['action' => 'edit', $id]);
    }

    $file = $this->request->getUploadedFile('liquidation_file');

    if (!$file) {
        $this->Flash->error('No se seleccionó ningún archivo.');

        return $this->redirect(['action' => 'edit', $id]);
    }

    $result = $this->documentService->updateLiquidationDocument($doc->id, $file, $user->id);

    if (is_string($result)) {
        $this->Flash->error($result);
    } else {
        $this->Flash->success('Documento de liquidación actualizado exitosamente.');
    }

    return $this->redirect(['action' => 'edit', $id]);
}
```

**Step 3: Modificar `edit()` para pasar el documento de liquidación a la vista**

En el método `edit()`, agregar después de la línea que obtiene `$documentsByStatus`:

```php
$liquidationDocument = $this->documentService->getLiquidationDocument($doc->id);
```

Y agregar `'liquidationDocument'` al `compact()` del `$this->set()`.

**Step 4: Modificar `view()` para pasar el documento de liquidación a la vista**

Igual que en edit, agregar `$liquidationDocument` y pasarlo al `compact()`.

**Step 5: Commit**

```bash
git add src/Controller/NoveltyLiquidationDocsController.php
git commit -m "feat: add upload/update liquidation document actions to controller"
```

---

### Task 5: Rutas

**Files:**
- Modify: `config/routes.php`

**Step 1: Agregar rutas para upload y update de documento de liquidación**

Dentro de la sección "Novelty Liquidation Docs" (después de la ruta de `add-observation`, línea ~213), agregar:

```php
$builder->connect(
    '/novelty-liquidation-docs/upload-liquidation-document/{id}',
    ['controller' => 'NoveltyLiquidationDocs', 'action' => 'uploadLiquidationDocument'],
    ['id' => '\d+', 'pass' => ['id']]
);
$builder->connect(
    '/novelty-liquidation-docs/update-liquidation-document/{id}',
    ['controller' => 'NoveltyLiquidationDocs', 'action' => 'updateLiquidationDocument'],
    ['id' => '\d+', 'pass' => ['id']]
);
```

**Step 2: Commit**

```bash
git add config/routes.php
git commit -m "feat: add routes for liquidation document upload and update"
```

---

### Task 6: Template — Campo dedicado en cuadro de soportes

**Files:**
- Modify: `templates/NoveltyLiquidationDocs/edit.php`

**Step 1: Agregar sección del documento de liquidación**

Justo **antes** del panel de soportes (antes de la línea `<!-- Documents panel -->`, línea ~349), agregar la sección dedicada:

```php
<!-- Dedicated Liquidation Document -->
<?php
$canUploadLiqDoc = $currentStatus === NoveltyConstants::STATUS_CONTABILIDAD && !$liquidationDocument;
$canUpdateLiqDoc = $liquidationDocument && in_array($currentStatus, [
    NoveltyConstants::STATUS_CONTABILIDAD,
    NoveltyConstants::STATUS_REVISION_FIRMAS,
    NoveltyConstants::STATUS_GDP,
]);
?>
<div class="card card-primary mb-3" style="border-top:2px solid var(--primary-color);">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-file-earmark-text" style="font-size:.85rem;color:var(--primary-color);"></i>
            <span style="font-size:.85rem;font-weight:600;">Documento de Liquidación</span>
        </span>
    </div>

    <?php if ($liquidationDocument): ?>
    <div style="padding:.8rem .875rem;">
        <div style="display:flex;align-items:center;gap:.75rem;">
            <div style="width:34px;height:34px;flex-shrink:0;background:#f5f5f5;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
                <i class="bi <?= $docIcon($liquidationDocument->mime_type) ?>"
                   style="color:<?= $docIconColor($liquidationDocument->mime_type) ?>;font-size:1rem;"></i>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:.79rem;font-weight:600;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                     title="<?= h($liquidationDocument->file_name) ?>">
                    <?= h($liquidationDocument->file_name) ?>
                </div>
                <div style="display:flex;align-items:center;gap:.5rem;margin-top:.25rem;flex-wrap:wrap;">
                    <span style="font-size:.65rem;color:#bbb;">
                        <i class="bi bi-clock" style="font-size:.6rem;"></i>
                        <?= $liquidationDocument->created?->format('d/m/Y H:i') ?>
                    </span>
                    <?php if ($liquidationDocument->uploaded_by_user): ?>
                    <span style="font-size:.65rem;color:#bbb;">
                        <i class="bi bi-person" style="font-size:.6rem;"></i>
                        <?= h($liquidationDocument->uploaded_by_user->full_name ?? '') ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($liquidationDocument->file_size): ?>
                    <span style="font-size:.63rem;color:#ccc;"><?= $this->Number->toReadableSize($liquidationDocument->file_size) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex;gap:.25rem;flex-shrink:0;">
                <?= $this->Html->link(
                    '<i class="bi bi-download"></i>',
                    '/' . $liquidationDocument->file_path,
                    ['class' => 'btn btn-sm btn-outline-secondary', 'style' => 'padding:.25rem .45rem;font-size:.72rem;line-height:1;', 'escape' => false, 'target' => '_blank', 'title' => 'Descargar']
                ) ?>
            </div>
        </div>

        <?php if ($canUpdateLiqDoc): ?>
        <div style="margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--border-color);">
            <?= $this->Form->create(null, [
                'url' => ['action' => 'updateLiquidationDocument', $doc->id],
                'type' => 'file',
                'class' => 'd-flex gap-2 align-items-end',
            ]) ?>
            <div style="flex:1;">
                <input type="file" name="liquidation_file" class="form-control form-control-sm" required
                       accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx">
            </div>
            <button type="submit" class="btn btn-sm btn-primary flex-shrink-0">
                <i class="bi bi-arrow-repeat me-1"></i>Actualizar
            </button>
            <?= $this->Form->end() ?>
        </div>
        <?php endif; ?>
    </div>

    <?php elseif ($canUploadLiqDoc): ?>
    <div style="padding:.8rem .875rem;">
        <?= $this->Form->create(null, [
            'url' => ['action' => 'uploadLiquidationDocument', $doc->id],
            'type' => 'file',
            'class' => 'd-flex gap-2 align-items-end',
        ]) ?>
        <div style="flex:1;">
            <input type="file" name="liquidation_file" class="form-control form-control-sm" required
                   accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx">
            <div class="form-text" style="font-size:.7rem;">Máx 10 MB — PDF, imágenes, Word o Excel.</div>
        </div>
        <button type="submit" class="btn btn-sm btn-primary flex-shrink-0">
            <i class="bi bi-upload me-1"></i>Subir
        </button>
        <?= $this->Form->end() ?>
    </div>

    <?php else: ?>
    <div style="padding:1.5rem 1rem;text-align:center;color:#c8c8c8;">
        <i class="bi bi-file-earmark-x d-block mb-2" style="font-size:1.25rem;"></i>
        <span style="font-size:.78rem;">Sin documento de liquidación</span>
    </div>
    <?php endif; ?>
</div>
```

**Step 2: Commit**

```bash
git add templates/NoveltyLiquidationDocs/edit.php
git commit -m "feat: add dedicated liquidation document section in edit template"
```

---

### Task 7: Mover firma del empleado de `revision_firmas` a `gdp`

**Files:**
- Modify: `templates/NoveltyLiquidationDocs/edit.php`
- Modify: `src/Service/NoveltyPipelineService.php`

**Step 1: Modificar el template — Firma del trabajador**

En `templates/NoveltyLiquidationDocs/edit.php`, la sección de firmas (línea ~221) muestra un pad de firma cuando el estado es `revision_firmas`. Cambiar la lógica para que:

- Firmas de `contador` y `coordinador_admin`: pad activo solo en `revision_firmas` (sin cambio)
- Firma de `trabajador`: pad activo solo en `gdp`

Reemplazar la condición del pad de firma (línea ~246):

**Antes:**
```php
<?php if ($doc->pipeline_status === NoveltyConstants::STATUS_REVISION_FIRMAS): ?>
```

**Después:**
```php
<?php
$canSign = ($sig->signer_type === NoveltyConstants::SIGNER_TRABAJADOR)
    ? ($doc->pipeline_status === NoveltyConstants::STATUS_GDP)
    : ($doc->pipeline_status === NoveltyConstants::STATUS_REVISION_FIRMAS);
?>
<?php if ($canSign): ?>
```

También actualizar la condición de visibilidad de la sección de firmas (línea ~221) para incluir GDP:

**Antes:**
```php
<?php if ($doc->pipeline_status === NoveltyConstants::STATUS_REVISION_FIRMAS || !empty($doc->novelty_liquidation_signatures)): ?>
```

**Después:**
```php
<?php if (in_array($doc->pipeline_status, [NoveltyConstants::STATUS_REVISION_FIRMAS, NoveltyConstants::STATUS_GDP]) || !empty($doc->novelty_liquidation_signatures)): ?>
```

**Step 2: Modificar `validateGroupTransition` en `NoveltyPipelineService`**

En `src/Service/NoveltyPipelineService.php`, método `validateGroupTransition()` (línea ~320):

**En el case `STATUS_REVISION_FIRMAS`**: Cambiar la validación para excluir la firma del trabajador. Reemplazar el bloque completo:

```php
case NoveltyConstants::STATUS_REVISION_FIRMAS:
    $signaturesTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationSignatures');

    // Only validate non-worker signatures in revision_firmas
    $totalSlots = $signaturesTable->find()
        ->where([
            'liquidation_doc_id' => $liquidationDoc->id,
            'signer_type !=' => NoveltyConstants::SIGNER_TRABAJADOR,
        ])
        ->count();

    $signedCount = $signaturesTable->find()
        ->where([
            'liquidation_doc_id' => $liquidationDoc->id,
            'signer_type !=' => NoveltyConstants::SIGNER_TRABAJADOR,
            'signature_path IS NOT' => null,
        ])
        ->count();

    if ($signedCount < $totalSlots) {
        $errors[] = 'Todas las firmas requeridas (Contador y Coordinador) deben estar presentes para avanzar.';
    }
    break;
```

**En el case `STATUS_GDP`**: Agregar validación de firma del trabajador:

```php
case NoveltyConstants::STATUS_GDP:
    if ($liquidationDoc->passes_for_payment === null) {
        $errors[] = 'Debe indicar si "Pasa para Pago".';
    }

    // Validate worker signature
    $signaturesTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationSignatures');
    $workerSlot = $signaturesTable->find()
        ->where([
            'liquidation_doc_id' => $liquidationDoc->id,
            'signer_type' => NoveltyConstants::SIGNER_TRABAJADOR,
        ])
        ->first();

    if ($workerSlot && empty($workerSlot->signature_path)) {
        $errors[] = 'La firma del trabajador es requerida para avanzar.';
    }
    break;
```

**Step 3: Commit**

```bash
git add templates/NoveltyLiquidationDocs/edit.php src/Service/NoveltyPipelineService.php
git commit -m "feat: move employee signature from revision_firmas to gdp state"
```

---

### Task 8: Template `view.php` — Mostrar documento de liquidación

**Files:**
- Modify: `templates/NoveltyLiquidationDocs/view.php`

**Step 1: Agregar sección de documento de liquidación en vista read-only**

Agregar la misma card del documento de liquidación (solo visualización/descarga, sin upload/update) justo antes del panel de soportes genéricos en la vista `view.php`. Seguir el mismo patrón que en `edit.php` pero sin formularios de upload/update.

```php
<!-- Dedicated Liquidation Document (read-only) -->
<?php if ($liquidationDocument ?? null): ?>
<div class="card card-primary mb-3" style="border-top:2px solid var(--primary-color);">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-file-earmark-text" style="font-size:.85rem;color:var(--primary-color);"></i>
        <span style="font-size:.85rem;font-weight:600;">Documento de Liquidación</span>
    </div>
    <div style="padding:.8rem .875rem;">
        <div style="display:flex;align-items:center;gap:.75rem;">
            <div style="width:34px;height:34px;flex-shrink:0;background:#f5f5f5;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
                <i class="bi <?= $docIcon($liquidationDocument->mime_type) ?>"
                   style="color:<?= $docIconColor($liquidationDocument->mime_type) ?>;font-size:1rem;"></i>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:.79rem;font-weight:600;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                     title="<?= h($liquidationDocument->file_name) ?>">
                    <?= h($liquidationDocument->file_name) ?>
                </div>
                <div style="display:flex;align-items:center;gap:.5rem;margin-top:.25rem;flex-wrap:wrap;">
                    <span style="font-size:.65rem;color:#bbb;">
                        <i class="bi bi-clock" style="font-size:.6rem;"></i>
                        <?= $liquidationDocument->created?->format('d/m/Y H:i') ?>
                    </span>
                    <?php if ($liquidationDocument->uploaded_by_user): ?>
                    <span style="font-size:.65rem;color:#bbb;">
                        <i class="bi bi-person" style="font-size:.6rem;"></i>
                        <?= h($liquidationDocument->uploaded_by_user->full_name ?? '') ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <?= $this->Html->link(
                '<i class="bi bi-download"></i>',
                '/' . $liquidationDocument->file_path,
                ['class' => 'btn btn-sm btn-outline-secondary', 'style' => 'padding:.25rem .45rem;font-size:.72rem;line-height:1;', 'escape' => false, 'target' => '_blank', 'title' => 'Descargar']
            ) ?>
        </div>
    </div>
</div>
<?php endif; ?>
```

**Step 2: Commit**

```bash
git add templates/NoveltyLiquidationDocs/view.php
git commit -m "feat: show liquidation document in view template"
```

---

### Task 9: Verificación final

**Step 1: Verificar que el servidor no arroje errores**

Run: `php bin/cake server`

Probar manualmente:
1. Ir a un documento de liquidación en estado "Contabilidad"
2. Verificar que aparece la sección "Documento de Liquidación" vacía con botón de subir
3. Subir un documento
4. Verificar que aparece el documento con opción de actualizar
5. Avanzar a "Revisión y firmas" — verificar que se puede actualizar pero NO subir nuevo
6. Verificar que la firma del trabajador NO aparece como pad activo en revisión
7. Avanzar a "GDP" — verificar que la firma del trabajador SÍ aparece como pad activo
8. Verificar que el documento de liquidación se puede actualizar en GDP

**Step 2: Commit final si hay ajustes**

```bash
git add -A
git commit -m "fix: adjustments from manual verification"
```

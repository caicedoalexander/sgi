# Soportes (documentos) en módulo Reintegros

**Fecha:** 2026-05-03
**Estado:** Diseño aprobado, pendiente plan de implementación

## Contexto

El módulo Reintegros (`Refunds`) sigue el mismo pipeline que Caja Menor (`PettyCashRecords`): `agrupacion → contabilidad → tesoreria → aut_pago → pagado`. A diferencia de los demás módulos de flujo (Invoices, PettyCash, EmployeeNovelties, NoveltyLiquidationDocs, PaymentSchedulings), Refunds **no tiene** infraestructura de soportes documentales. Falta tabla, entity, table, servicio, vistas y wiring en el controller.

Este spec replica el patrón de PettyCashRecords —el módulo más cercano estructuralmente— y se apalanca en la infraestructura compartida ya disponible:

- `App\Service\Trait\DocumentUploadTrait` — validación, persistencia, eliminación física.
- `App\Controller\Trait\DocumentJsonPayloadTrait::_buildDocumentPayload()`.
- `templates/element/document_row.php` + `templates/element/document_row_template.php`.
- `webroot/js/sgi-document-uploader.js` (`SgiDocumentUploader.init({...})`) — handler AJAX unificado.

## Decisiones

- **Permisos:** mismo guard que PettyCash. Subir/eliminar permitido siempre que `status !== 'pagado'`. Acceso a las acciones cubierto por el RBAC del módulo Reintegros (`can_edit`/`can_delete`) vía `_enforcePermission()` en `AppController`.
- **Sin filtros por rol/estado adicionales:** cualquier rol con permiso de edición sobre el módulo puede gestionar soportes (no se replica el patrón de campos editables por etapa del pipeline).
- **Ubicación en UI:** card "Soportes" en la columna derecha de `templates/Refunds/edit.php`, **encima** del card de Observaciones (línea ~539). En `view.php` se muestra read-only.
- **Tipo de documento:** texto libre opcional (mismo enfoque que PettyCash, no catálogo).
- **Field name:** `file` (alineado con el contrato del helper unificado).

## Arquitectura

### 1. Migración — `CreateRefundDocuments`

Tabla `refund_documents`, esquema espejo de `petty_cash_documents`:

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | integer | — | PK |
| `refund_id` | integer signed | NO | FK `refunds.id` ON DELETE CASCADE / ON UPDATE CASCADE |
| `document_type` | string(100) | SÍ | Etiqueta libre |
| `file_path` | string(255) | NO | Relativo a `webroot/` |
| `file_name` | string(255) | NO | Nombre original |
| `file_size` | integer | SÍ | Bytes |
| `mime_type` | string(100) | SÍ | |
| `uploaded_by` | integer signed | SÍ | FK `users.id` ON DELETE SET_NULL / ON UPDATE CASCADE |
| `created` | datetime | SÍ | Timestamp behavior |
| `modified` | datetime | SÍ | Timestamp behavior |

Usa `Migrations\BaseMigration` y `$this->hasTable()` antes de crear.

### 2. Entity — `src/Model/Entity/RefundDocument.php`

`_accessible`: `refund_id`, `document_type`, `file_path`, `file_name`, `file_size`, `mime_type`, `uploaded_by`.

### 3. Table — `src/Model/Table/RefundDocumentsTable.php`

- `addBehavior('Timestamp')`.
- `belongsTo('Refunds')`.
- `belongsTo('UploadedByUsers', ['className' => 'Users', 'foreignKey' => 'uploaded_by'])`.
- Validación mínima (`file_path`, `file_name` requeridos).

### 4. Asociación en `RefundsTable`

```php
$this->hasMany('RefundDocuments', [
    'foreignKey' => 'refund_id',
    'dependent' => true,
    'cascadeCallbacks' => true,
]);
```

### 5. Servicio — `src/Service/RefundDocumentService.php`

```php
final class RefundDocumentService
{
    use DocumentUploadTrait;

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

### 6. DI en `src/Application.php`

- `use App\Service\RefundDocumentService;`
- `$container->addShared(RefundDocumentService::class);`
- Añadir `RefundDocumentService::class` al array de argumentos del `definition` de `RefundsController` (siguiendo el patrón de `PettyCashRecordsController`).

### 7. Controller — `RefundsController`

Cambios:

- `use App\Controller\Trait\DocumentJsonPayloadTrait;` y `use DocumentJsonPayloadTrait;` (junto al ya presente `ObservationControllerTrait`).
- Propiedad `private RefundDocumentService $documentService;` inicializada en `initialize()` desde el contenedor.
- En `edit()` y `view()` añadir al `contain()`:
  ```php
  'RefundDocuments' => ['UploadedByUsers']
  ```
- Nuevas acciones:

```php
public function uploadDocument($id = null)
{
    $this->request->allowMethod(['post']);
    $record = $this->Refunds->get($id);

    $file = $this->request->getUploadedFile('file');
    if (!$file) {
        if ($this->_isJsonRequest()) {
            return $this->_jsonResponse(['success' => false, 'error' => 'No se recibió ningún archivo válido.']);
        }
        $this->Flash->error('No se recibió ningún archivo válido.');
        return $this->redirect(['action' => 'edit', $id]);
    }

    if ($record->isPagado()) {
        if ($this->_isJsonRequest()) {
            return $this->_jsonResponse(['success' => false, 'error' => 'No se puede subir soportes a un reintegro pagado.']);
        }
        $this->Flash->error('No se puede subir soportes a un reintegro pagado.');
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

    is_string($result)
        ? $this->Flash->error($result)
        : $this->Flash->success('El soporte ha sido subido.');

    return $this->redirect(['action' => 'edit', $id]);
}

public function deleteDocument($refundId = null, $documentId = null)
{
    $this->request->allowMethod(['post', 'delete']);
    $record = $this->Refunds->get($refundId);

    if ($record->isPagado()) {
        if ($this->_isJsonRequest()) {
            return $this->_jsonResponse(['success' => false, 'error' => 'No se puede eliminar un soporte de un reintegro pagado.']);
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

    $deleted
        ? $this->Flash->success('El soporte ha sido eliminado.')
        : $this->Flash->error('No se pudo eliminar el soporte.');

    return $this->redirect(['action' => 'edit', $refundId]);
}
```

### 8. Template — `templates/Refunds/edit.php`

Insertar el card "Soportes" justo encima del card de Observaciones (línea ~539):

- Header: ícono `bi-folder2`, título "Soportes", contador `.sgi-folder-count`, botón "Subir" oculto si `$record->isPagado()`.
- Empty state `#docs-empty-state`.
- Lista `#docs-list` con `foreach ($record->refund_documents ?? [] as $doc)` renderizando `$this->element('document_row', [...])`.
- Modal `#uploadDocModal` con form `#upload-doc-form` (`enctype="multipart/form-data"`, `data-url="{action: uploadDocument}"`), campos `document_type` (texto opcional) y `file` (`accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx"`).
- Al final: `<?= $this->element('document_row_template', ['showBadge' => false]) ?>`.
- Bloque `<script>` que invoque `SgiDocumentUploader.init({ formSelector: '#upload-doc-form', listSelector: '#docs-list', emptySelector: '#docs-empty-state', rowTemplateSelector: '#doc-row-template', modalSelector: '#uploadDocModal', csrfToken: ... })`.

### 9. Template — `templates/Refunds/view.php`

Añadir un bloque read-only listando soportes (sin botón subir/eliminar). Reutilizar `document_row` con `canDelete = false`.

### 10. Permisos / Routing

- Las acciones `uploadDocument` y `deleteDocument` quedan cubiertas por el mapeo en `$controllerModuleMap` ya existente para `Refunds`. No se requiere migración de la tabla `permissions` (los actions caen bajo `can_edit` y `can_delete` del módulo Reintegros automáticamente vía `_enforcePermission()`).
- No se requieren rutas custom en `config/routes.php`; los fallbacks resuelven `/refunds/upload-document/{id}` y `/refunds/delete-document/{refundId}/{documentId}`.

## Estilo de UI

Idéntico a PettyCash. Borde superior 2px en el card, `--primary-color` como acento, sin sombras, `.sgi-folder-count` como pill del contador.

## Validación manual (post-merge)

1. `php bin/cake migrations migrate` — verificar que crea `refund_documents` con FKs correctas.
2. Login con un rol con permisos sobre Reintegros, abrir un refund en `agrupacion`.
3. Subir tres soportes (PDF, PNG, XLSX) — aparecen sin recargar; refrescar y verificar persistencia + archivos en `webroot/uploads/refunds/<id>/`.
4. Eliminar uno desde la lista — desaparece sin recargar; verificar archivo físico borrado en disco.
5. Avanzar el refund hasta `pagado` (vía pipeline) — recargar `edit`: botón "Subir" oculto, botones de eliminar ausentes en filas.
6. Probar `curl -X POST` directo al endpoint de upload sobre un refund pagado → respuesta JSON `{success:false, error:"..."}` (con `X-Requested-With: XMLHttpRequest`) o redirect con flash error.
7. Subir archivo no permitido (`.zip`) → mensaje "Tipo de archivo no permitido."
8. Subir archivo > 20 MB → mensaje "El archivo excede el tamaño máximo de 20 MB."
9. Verificar `view.php` muestra los soportes en read-only.
10. Eliminar el refund completo → los `refund_documents` asociados se eliminan en cascada (FK CASCADE) y los archivos físicos se purgan vía `dependent + cascadeCallbacks`.

## Fuera de alcance (YAGNI)

- Catálogo de tipos de documento.
- Preview inline de imágenes/PDFs.
- Versionado / historial de soportes.
- Reordenamiento manual.
- Notificaciones por correo al subir soportes.

# Tabla general de soportes en la legalización de anticipos

**Fecha:** 2026-07-10
**Módulo:** Anticipos — sub-pipeline de legalización (`legalizations` en `pipeline_permissions`, `advances` en `permissions`).
**Clasificación del módulo:** módulo de flujo (pipeline sobre `advance_legalizations`).
**Migración:** sí — tabla nueva `advance_legalization_documents` + drop de la columna `shortage_receipt_path`. Sin backfill (forward-only).
**Alcance:** la card «Soportes» de `Advances::legalization` y el form de confirmar consignación. No se tocan las facturas hijas, `LinkedInvoiceLegalizer`, ni el documento especial de relación de facturas / firmas (`advance_legalization_signatures`).

## Problema

La legalización de anticipos es **el único módulo de flujo sin tabla `*_documents`**. Invoice, PettyCash, Refund, Novelty, PaymentScheduling, Employee y Asset la tienen; la legalización no. Hoy solo permite adjuntar dos archivos, y ninguno por el canal canónico de soportes:

1. **Relación de facturas** — documento especial «actualizable» que se sube en `validacion` y se reemplaza en `revision_firmas`. Vive en `advance_legalization_signatures` (una tabla de **firmas** con ciclo `pending → signed/rejected`), no en una tabla de documentos. Es correcto que sea especial y **no se toca**.
2. **Comprobante de consignación** (caso faltante) — se guarda como columna suelta `advance_legalizations.shortage_receipt_path` (más `shortage_receipt_number` y `shortage_received_at`), movido con `validateAndMoveUpload()` **sin fila en ninguna tabla de documentos**. Es un archivo de un solo cupo: no se puede reemplazar ni borrar por UI, no queda en historial. Una excepción a medias.

Consecuencia operativa: la causación (paso Contabilidad) y cualquier otro soporte que el flujo genere no tienen dónde subirse. El usuario reportó que «el único documento que me deja subir es el especial».

## Decisiones acordadas

1. **Se crea `advance_legalization_documents`**, calcando el canon de `refund_documents` (Refund/PettyCash como referentes de coordinador/documentos).
2. **El comprobante de consignación pasa a la tabla general.** Se elimina la columna `shortage_receipt_path`, el método `AdvanceLegalizationDocumentService::attachShortageReceipt()` y el input de archivo del form de consignación. **Se conservan** `shortage_receipt_number` y `shortage_received_at` como datos de negocio del ledger.
3. **Cajón único sin clasificación.** La columna `document_type` existe (nullable) por paridad con el canon, pero no hay selector en UI (`upload_doc_modal` con `showDocumentType => false`) ni agrupación por tipo. El comprobante de consignación aparece como un soporte más.
4. **RBAC = canon.** El rol que opera el paso vigente sube/elimina soportes mientras la legalización no esté `legalizada` (terminal).
5. **Forward-only.** Al dropear `shortage_receipt_path` no se migran los comprobantes históricos (el usuario confirmó que no hay faltantes cerrados con comprobante a preservar en prod). El archivo físico previo queda huérfano en disco; ninguna fila lo referencia.
6. **Se extiende el service existente**, no se crea uno nuevo: `AdvanceLegalizationDocumentService` gana `uploadDocument()`/`deleteDocument()` y pierde `attachShortageReceipt()`. Un solo document service por dominio.
7. **El hub de consulta muestra los soportes read-only.** `templates/Advances/view.php` (que ya muestra relación de facturas e historial de firmas) también lista los soportes generales, sin subir ni borrar. Es el canon de la vista `view` de Refund/PettyCash (`documents_section` con `canUpload => false`).

## Diseño

### 1. Migración A — `CreateAdvanceLegalizationDocuments`

Espejo de `config/Migrations/20260503215013_CreateRefundDocuments.php`. Extiende `Migrations\BaseMigration`, usa `change()` con guard `hasTable('advance_legalization_documents')`.

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| `id` | PK | — | default Phinx |
| `legalization_id` | `integer` (`signed => true`) | no | FK → `advance_legalizations.id` |
| `document_type` | `string` (limit 100) | sí | default `null` — sin uso en UI |
| `file_path` | `string` (limit 255) | no | |
| `file_name` | `string` (limit 255) | no | |
| `file_size` | `integer` | sí | default `null` |
| `mime_type` | `string` (limit 100) | sí | default `null` |
| `uploaded_by` | `integer` (`signed => true`) | sí | FK → `users.id` |
| `created` | `datetime` | sí | |
| `modified` | `datetime` | sí | |

- Índice en `legalization_id`.
- FK `legalization_id` → `advance_legalizations` `id`, `delete => CASCADE`, `update => CASCADE`.
- FK `uploaded_by` → `users` `id`, `delete => SET_NULL`, `update => CASCADE`.

`signed => true` explícito en ambas FK integer, alineado con `advance_legalizations.id` y `users.id` (invariante MI-010, mismo patrón que la migración de `advance_legalizations`). El nombre de columna es `legalization_id` por consistencia con `advance_legalization_signatures.legalization_id`.

### 2. Migración B — `DropShortageReceiptPathFromAdvanceLegalizations`

`BaseMigration` con `up()`/`down()`:

- `up()`: si existe la columna, `->removeColumn('shortage_receipt_path')` sobre `advance_legalizations`.
- `down()`: re-añade `shortage_receipt_path` como `string` (limit 500, null, default null) — idéntica a la definición original en `20260429143219_CreateAdvanceLegalizations.php:22`.

Se conservan intactas `shortage_receipt_number` y `shortage_received_at`. Es una migración aparte de la A para que el rollback de la tabla nueva sea independiente del rollback del drop.

### 3. Entidad — `src/Model/Entity/AdvanceLegalizationDocument.php`

`Entity` estándar. `$_accessible` para `legalization_id`, `document_type`, `file_path`, `file_name`, `file_size`, `mime_type`, `uploaded_by` en `true` (los escribe el service vía `newEntity`, no hay superficie de mass-assignment desde el cliente: el controller pasa solo el `UploadedFile` y el `userId`). Espejo de `RefundDocument`.

### 4. Tabla — `src/Model/Table/AdvanceLegalizationDocumentsTable.php`

Espejo de `RefundDocumentsTable`:

- `setTable('advance_legalization_documents')`, `setDisplayField('file_name')`, `addBehavior('Timestamp')`.
- `belongsTo('AdvanceLegalizations', ['foreignKey' => 'legalization_id', 'joinType' => 'INNER'])`.
- `belongsTo('UploadedByUsers', ['className' => 'Users', 'foreignKey' => 'uploaded_by'])`.
- `validationDefault()` calca las reglas de `RefundDocumentsTable` (integer `legalization_id` requerido en create; `file_path`/`file_name` scalar/maxLength 255 requeridos en create; `document_type`/`mime_type` scalar maxLength opcional; `file_size`/`uploaded_by` integer opcional).

### 5. Asociación — `src/Model/Table/AdvanceLegalizationsTable.php`

Añadir `hasMany('AdvanceLegalizationDocuments', ['foreignKey' => 'legalization_id', 'dependent' => true, 'cascadeCallbacks' => true])`, junto a la asociación ya existente con `AdvanceLegalizationSignatures`.

Quitar de `validationDefault()` las tres líneas de `shortage_receipt_path` (`AdvanceLegalizationsTable.php:113-115`). Las reglas de `shortage_receipt_number` y `shortage_received_at`, si existen, se conservan.

### 6. Entidad — `src/Model/Entity/AdvanceLegalization.php`

- Quitar la clave `'shortage_receipt_path' => false` de `$_accessible` (`:29`). Las de `shortage_received_at` y `shortage_receipt_number` se conservan.
- Añadir la propiedad de asociación `'advance_legalization_documents' => true` en `$_accessible` (paridad con `'advance_legalization_signatures' => true`).
- Nuevo predicate de estado, junto a los demás `canXxx()`:

```php
/** @return bool true cuando el estado permite gestionar (subir/eliminar) soportes generales. */
public function canManageDocuments(): bool
{
    return !$this->isLegalized();
}
```

Es la única regla de estado nueva: los soportes se pueden gestionar en cualquier estado operable, y `legalizada` (terminal) los congela. La dimensión de rol la compone el policy.

### 7. Servicio de documentos — `src/Service/AdvanceLegalizationDocumentService.php`

Se **añaden** dos métodos canónicos (espejo de `RefundDocumentService`) y se **elimina** `attachShortageReceipt()`. `attachRelationDocument()` no se toca.

```php
public function uploadDocument(int $legalizationId, UploadedFile $file, ?int $uploadedBy): object|string
{
    return $this->uploadAndSave($file, 'AdvanceLegalizationDocuments', 'advances/' . $legalizationId, 'leg_', [
        'legalization_id' => $legalizationId,
        'uploaded_by' => $uploadedBy,
    ]);
}

public function deleteDocument(int $documentId, int $legalizationId): bool
{
    $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationDocuments');
    if (!$table->exists(['id' => $documentId, 'legalization_id' => $legalizationId])) {
        return false;
    }

    return $this->deleteDocumentRecord('AdvanceLegalizationDocuments', $documentId);
}
```

- No se pasa `document_type` (cajón único). La columna queda `null`.
- `deleteDocument()` exige `legalizationId` y filtra por `['id', 'legalization_id']` — mismo blindaje anti-IDOR que Refund (relevante dado el historial de IDOR en controllers de pago del proyecto).
- El subdirectorio de subida es `advances/{legalizationId}`, coherente con `attachRelationDocument()` (`advances/' . $leg->id`) y `attachShortageReceipt()` (que se elimina).

### 8. Servicio de pipeline — `src/Service/AdvanceLegalizationService.php`

En `confirmShortageReceipt()` (`:575-613`) se elimina el bloque que consumía el archivo:

```php
// SE ELIMINA:
if (!empty($data['receipt_file']) && $data['receipt_file'] instanceof UploadedFile) {
    $upload = $this->documentService->attachShortageReceipt($leg, $data['receipt_file']);
    if (!$upload->success) {
        return $upload;
    }
    $leg->shortage_receipt_path = $upload->data;
}
```

Se conserva todo lo demás: validación de `receipt_number` obligatorio, normalización de `received_at`, asignación de `shortage_receipt_number`/`shortage_received_at`, `legalized_at` y la transición a `legalizada` vía `_setStatus()`. Se actualiza el docblock del método (quitar la clave `receipt_file` del `@param`). Si tras quitar el bloque el `use ...UploadedFile` queda huérfano en el archivo, se elimina.

### 9. Policy — `src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php`

Nuevo método, en el mismo estilo que los demás:

```php
public function canManageDocuments(AdvanceLegalization $leg, int $roleId): bool
{
    return $this->_canOperate($roleId, $leg->status) && $leg->canManageDocuments();
}
```

Compone rol×paso (`AuthorizationFacade` sobre `PIPELINE_LEGALIZATIONS`) con el predicate de estado de la entidad. Es funcionalmente equivalente a `canOperateCurrentStep()`, pero se expone como método propio por paridad con el resto de acciones (cada acción mutante tiene su `canXxx($leg, $roleId)`).

### 10. Controller + rutas — `src/Controller/AdvancesController.php`

Dos acciones nuevas, espejo de `RefundsController::uploadDocument`/`deleteDocument`, reusando `DocumentJsonPayloadTrait` (ya importado) y el patrón de gate/JSON de `uploadRelationDocument`:

```php
#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
public function uploadLegalizationDocument(?int $id = null): Response

#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
public function deleteLegalizationDocument(?int $id = null, ?int $documentId = null): Response
```

- Ambas: `allowMethod(['post'])` (delete también acepta `delete`), `_loadLegalization((int)$id)` → `_redirectMissing()` si falta, y gate `actionPolicy->canManageDocuments($leg, roleId)` → respuesta JSON 403 o `_denyAction()` según `_isJsonRequest()`. El resultado (éxito/error) también ramifica por `_isJsonRequest()`: rama JSON para el uploader AJAX + rama redirect+Flash de respaldo, igual que `uploadRelationDocument`.
- `uploadLegalizationDocument`: lee `getUploadedFile('file')`; si falta → error. Llama `documentService->uploadDocument($leg->id, $file, $userId)`. En éxito **audita** con `historyService->recordFieldChange($leg->id, 'document', null, $doc->file_name, $userId)` — paridad con `RefundsController::uploadDocument:881-889` y `AdvancesController::uploadRelationDocument:678-686` — y construye el JSON con `_buildDocumentPayload($doc, $canDelete, $deleteUrl)` — sin `pipeline_status`, así que badge `null`. `$canDelete = $leg->canManageDocuments()`; `$deleteUrl = Router::url(['action' => 'deleteLegalizationDocument', $leg->advance_invoice_id, $doc->id])`.
- `deleteLegalizationDocument`: carga el documento filtrando por `['id' => $documentId, 'legalization_id' => $leg->id]` para capturar `file_name`; llama `documentService->deleteDocument($documentId, $leg->id)`; en éxito **audita** con `historyService->recordFieldChange($leg->id, 'document', $fileName, null, $userId)` — paridad con `RefundsController::deleteDocument`.
- El `$id` de la ruta es el `advance_invoice_id` (como el resto de acciones de legalización: `uploadRelationDocument`, `confirmShortage` reciben `$leg->advance_invoice_id`). `_loadLegalization()` resuelve la legalización desde ese id.

En `confirmShortage()` (`:996-997`) se elimina la línea:

```php
$data['receipt_file'] = $this->request->getUploadedFile('receipt_file');
```

`$data` sigue llevando `receipt_number` y `received_at` del `getData()`.

**Rutas** (`config/routes.php`, antes de `fallbacks()`): dos rutas nuevas siguiendo el estilo de las de Advances ya presentes (`uploadRelationDocument`, `confirmShortage`):

- `POST /advances/upload-legalization-document/{id}` → `Advances::uploadLegalizationDocument`.
- `POST /advances/delete-legalization-document/{id}/{documentId}` → `Advances::deleteLegalizationDocument`.

### 11. Vista

El element `templates/element/advance_legalization/_soportes.php` es **compartido por dos consumidores** (los elements de CakePHP no heredan las view-vars del controller; reciben solo su `$data` explícito):

- `templates/Advances/legalization.php:453` — vista operativa (`editable = true`).
- `templates/Advances/view.php:223` — hub de consulta (`editable = false`).

El bloque general se diseña para funcionar en ambos y el wiring se actualiza en los dos call-sites.

**`_soportes.php`.**
- Declara **defaults seguros** para los tres params nuevos: `$documentRows = $documentRows ?? []`, `$totalDocs = (int)($totalDocs ?? 0)`, `$canManageDocuments = $canManageDocuments ?? false`. Así ningún consumidor rompe por variable indefinida.
- Elimina el bloque especial «Comprobante de consignación» (`:109-136`) y ajusta el empty-state global (`:174`) a `!$relationDocument && empty($signatureHistory) && $totalDocs === 0`. Como el bloque general trae su propio `#docs-empty-state` (dropzone cuando `canManageDocuments`), el plan reconcilia cuál se muestra para no solapar dos vacíos: el empty-state global de la card solo cuando no hay soporte de **ningún** tipo; el `#docs-empty-state`/dropzone dentro de la sub-sección «Soportes».
- Conserva «Relación de facturas» e «Historial de firmas».
- Añade el bloque general «Soportes» en la misma card. Orden: **Relación de facturas** (especial) · **Soportes** (general) · **Historial de firmas** (especial, RO). Lista `$documentRows` con `element('document_row')`. El botón «Subir», la dropzone y el icono de borrar se emiten **solo cuando `$canManageDocuments`**; con `false` el bloque es puramente read-only (el hub de consulta).
- Respeta el contrato de `spi-document-uploader.js`: contenedor con los IDs `#docs-list`, `#docs-empty-state`, `#docs-folder-count`.

**Reúso vs. bespoke (excepción B explícita).** El `documents_section` canónico es una card completa; `_soportes.php` es una card bespoke que mezcla dos documentos especiales con el cajón general, y el canon visual (CLAUDE.md › «Canon visual… Excepciones (B)») ya sanciona markup bespoke para los soportes con firma de `Advances/legalization`. Por eso el bloque general **replica** la estructura interna de `documents_section` (dropzone/lista + IDs de contrato) en vez de invocar el element. Requisito duro: los IDs y el markup de fila quedan **idénticos** a `documents_section`/`document_row` para no derivar del contrato JS. Se reutiliza `document_row` tal cual.

**`templates/Advances/legalization.php`.**
- Actualiza la llamada al element `_soportes` (`:453`) para pasar `documentRows`, `totalDocs` y `canManageDocuments` desde el `AdvanceLegalizationViewModel`.
- Form «Confirmar consignación» (`:344-347`): elimina el `<div class="col-md-5">` con `<input type="file" name="receipt_file">`. Conserva N.º comprobante (obligatorio) y Fecha; ya no necesita `enctype="multipart/form-data"`.
- Añade, al final de la vista y solo cuando `canManageDocuments`: `element('upload_doc_modal', ['modalId' => 'uploadLegDocModal', 'uploadUrl' => Url(uploadLegalizationDocument, advance_invoice_id), 'showDocumentType' => false])`, `element('document_row_template', ['showBadge' => false])` y `Html->script('spi-document-uploader', ['block' => true])`.

**`templates/Advances/view.php` (hub de consulta, read-only).**
- Actualiza la llamada al element `_soportes` (`:223`) para pasar `documentRows` y `totalDocs` desde el summary de legalización de `AdvanceViewViewModel` (el `$sum` que hoy expone `relationDocument`/`signatureHistory`), y `canManageDocuments => false`.
- No emite modal, template ni script de subida.

**ViewModels.** `documentRows` y `totalDocs` se derivan **una sola vez** en `src/ViewModel/Support/LegalizationSummary.php` — el objeto que `AdvanceLegalizationViewModel` instancia y `AdvanceViewViewModel` expone como `legalizationSummary`, y que ya expone `relationDocument`/`signatureHistory`. Así ambas vistas comparten la misma derivación sin drift.

- `LegalizationSummary`: añade `documentRows` y `totalDocs`. `documentRows` mapea `$leg->advance_legalization_documents` a params de `element('document_row')` igual que `templates/Refunds/edit.php` (fileName, filePath, mimeType, tamaño, fecha, `deleteUrl` a `deleteLegalizationDocument`, `showBadge => false`). El summary **no** hornea el flag de permiso (depende de la vista).
- `canManageDocuments` es el único dato específico de la vista: `AdvanceLegalizationViewModel::build()` lo expone (del policy `canManageDocuments($leg, $roleId)`) para `legalization.php`; el hub `view.php` pasa `false`. `_soportes.php` lo recibe como param y gobierna con él si el bloque general emite los controles de subir/borrar.
- Ninguna clave es un mapa estado→pill, así que `AdvancePresentation` no cambia.

**Controller — `contain`.** Tanto `_buildLegalizationViewModel` (para `legalization()`) como `view()` añaden `AdvanceLegalizationDocuments => ['UploadedByUsers']` al `contain` de la legalización, junto a las firmas que ya cargan.

## Flujo de datos

```
Subida:
POST /advances/upload-legalization-document/{advance_invoice_id}   (multipart, name="file")
  → AdvancesController::uploadLegalizationDocument
      → _loadLegalization → actionPolicy->canManageDocuments (rol×paso + !legalizada)
      → documentService->uploadDocument(legId, file, userId)
          → uploadAndSave → fila en advance_legalization_documents + archivo en webroot/uploads/advances/{legId}
      → historyService->recordFieldChange(legId, 'document', null, file_name, userId)
      → JSON { success, document: _buildDocumentPayload(...) }   ← spi-document-uploader.js inserta la fila

Borrado:
POST /advances/delete-legalization-document/{advance_invoice_id}/{documentId}
  → actionPolicy->canManageDocuments
  → documentService->deleteDocument(documentId, legId)   ← anti-IDOR (id + legalization_id)
  → historyService->recordFieldChange(legId, 'document', fileName, null, userId)
  → JSON { success }

Confirmar consignación (sin archivo):
POST /advances/confirm-shortage/{advance_invoice_id}   (receipt_number, received_at)
  → AdvanceLegalizationService::confirmShortageReceipt
      → shortage_receipt_number, shortage_received_at, legalized_at → _setStatus(legalizada)
  El comprobante se sube por separado como soporte general.
```

## Manejo de errores

- Upload sin archivo / con archivo inválido → `ServiceResult`/string de error → JSON `{ success:false, error }`, mismo patrón que `uploadRelationDocument`.
- Delete de un documento que no pertenece a la legalización → `deleteDocument()` devuelve `false` (find filtrado no encuentra) → JSON de error. Nunca borra por `documentId` crudo.
- Gate de rol/estado fallido → JSON 403 (`_isJsonRequest`) o `_denyAction()` con flash.

## Fuera de alcance

- No se toca la relación de facturas ni `advance_legalization_signatures` (sigue siendo el único documento especial).
- No se migran comprobantes de consignación históricos (forward-only). El archivo físico previo referenciado por `shortage_receipt_path` queda huérfano; es aceptable y esperado.
- No se añade selector ni agrupación por `document_type`.
- No se propaga nada a las facturas hijas.
- El comprobante y demás soportes quedan en `webroot/uploads/` público, igual que hoy (`attachShortageReceipt` ya lo hacía) y que todos los soportes de los módulos de flujo. Si en algún momento se consideran datos sensibles, el lugar es el storage port pendiente (`EmployeeDocumentService`), fuera de alcance aquí.

## Invariantes que no se deben romper

- Slug CRUD `advances` ≠ slug pipeline `legalizations`. No se toca ninguno; las acciones nuevas van bajo `#[PipelineAction(pipeline: PIPELINE_LEGALIZATIONS)]`.
- `document_type` nullable presente en la tabla por paridad de canon, pero sin uso en UI (cajón único).
- `shortage_receipt_number` y `shortage_received_at` **se conservan**; solo `shortage_receipt_path` se elimina.
- `deleteDocument()` siempre exige `legalization_id` además del `documentId` (anti-IDOR).
- `FieldAccessPolicy`/patch por rol no aplica aquí (los soportes no son campos del header); el gate es el predicate de estado + `_canOperate` del policy, canon del módulo.

## Criterios de verificación

### Consumidores a actualizar (grep exhaustivo de `receipt_file` / `shortage_receipt_path`)

Todos en `src/` y `templates/`; **ningún test** los referencia:

| Archivo | Cambio |
|---|---|
| `templates/Advances/legalization.php:346` | Quitar `<input type="file" name="receipt_file">` |
| `src/Controller/AdvancesController.php:997` | Quitar `$data['receipt_file'] = …` |
| `templates/element/advance_legalization/_soportes.php:110,131,174` | Quitar bloque comprobante + ajustar empty-state |
| `src/Service/AdvanceLegalizationService.php:570-605` | Quitar bloque `receipt_file`/`attachShortageReceipt` + docblock |
| `src/Model/Table/AdvanceLegalizationsTable.php:113-115` | Quitar validación `shortage_receipt_path` |
| `src/Model/Entity/AdvanceLegalization.php:29` | Quitar clave de `$_accessible` |

`config/Migrations/20260429143219_CreateAdvanceLegalizations.php:22` (la columna original) no se toca; la Migración B la dropea.

### Wiring nuevo (call-sites del element + ViewModels)

Además de los consumidores de columna, el bloque general obliga a cablear:

| Archivo | Cambio |
|---|---|
| `templates/Advances/legalization.php:453` | Pasar `documentRows`/`totalDocs`/`canManageDocuments` al element `_soportes`; añadir modal/template/script de subida (cuando `canManageDocuments`) |
| `templates/Advances/view.php:223` | Pasar `documentRows`/`totalDocs` + `canManageDocuments => false` al element `_soportes` |
| `src/Controller/AdvancesController.php` | `view()` y `_buildLegalizationViewModel` añaden `AdvanceLegalizationDocuments => ['UploadedByUsers']` al `contain`; `_buildLegalizationViewModel` pasa `canManageDocuments` al VM; 2 acciones nuevas + `confirmShortage` sin `receipt_file` |
| `src/ViewModel/AdvanceLegalizationViewModel.php` | Exponer `documentRows`/`totalDocs`/`canManageDocuments` |
| `src/ViewModel/AdvanceViewViewModel.php` | El summary de legalización expone `documentRows`/`totalDocs` |
| `config/routes.php` | 2 rutas nuevas antes de `fallbacks()` |

### Tests que NO rompen (verificado)

`AdvanceLegalizationShortageTest` prueba `confirmShortageReceipt` solo con `receipt_number` / `received_at` (nunca `receipt_file`); sus asserts sobre `shortage_receipt_number` / `shortage_received_at` se conservan. No requiere cambios salvo revisión de que sigue verde.

### Tests a añadir

- **Service** (`tests/TestCase/Service/AdvanceLegalizationDocumentServiceTest.php`, nuevo): `uploadDocument()` crea fila con `legalization_id`/`uploaded_by` y `document_type = null`; `deleteDocument()` borra cuando pertenece; `deleteDocument()` devuelve `false` y no borra cuando el `documentId` es de otra legalización (anti-IDOR).
- **Policy** (`AdvanceStatesTest` o test del policy): `canManageDocuments()` de la entidad devuelve `true` en estados operables y `false` en `legalizada`; el policy compone con `_canOperate` (rol sin permiso → `false`).
- **Integración** (`tests/TestCase/Controller/AdvancesController...` o nuevo): subir un soporte en `contabilidad`/`tesoreria` responde JSON con `document`; delete anti-IDOR responde error para documento ajeno; el upload en `legalizada` responde 403.
- **Render operativo** (`tests/TestCase/Controller/AdvancesLegalizationRenderTest.php`): revisar/añadir — (a) el form de confirmar consignación en `tesoreria`+faltante ya no renderiza `name="receipt_file"`; (b) la card «Soportes» renderiza el botón «Subir» y el modal `uploadLegDocModal` cuando `canManageDocuments`; (c) no aparece el bloque especial «Comprobante de consignación».
- **Render hub de consulta** (`Advances/view.php`, en el render test que cubra `view()`): la card «Soportes» lista los soportes generales en read-only (sin botón «Subir», sin icono de borrar, sin modal) cuando el anticipo tiene legalización.
- `composer cs-check` en verde sobre los archivos tocados.

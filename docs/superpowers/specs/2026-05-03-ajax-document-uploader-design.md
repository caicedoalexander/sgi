# AJAX unificado para subida de documentos en módulos de flujo

**Fecha:** 2026-05-03
**Estado:** Diseño aprobado, pendiente plan de implementación

## Contexto

Auditoría de uploads de documentos en los 5 módulos de flujo del SGI:

| Módulo | Mecanismo actual | AJAX |
|---|---|---|
| Invoices (`templates/Invoices/edit.php:1139`) | `fetch` + `FormData` + `X-Requested-With` inline (~150 LOC en el template) | sí |
| PaymentSchedulings (`templates/PaymentSchedulings/edit.php:578`) | `fetch` + `X-Requested-With` | sí |
| NoveltyLiquidationDocs / Legalizaciones (`templates/NoveltyLiquidationDocs/edit.php:615`) | `Form->create` clásico, recarga de página | no |
| EmployeeNovelties / Novedades (`templates/EmployeeNovelties/edit.php:619`) | `Form->create` clásico, recarga | no |
| PettyCashRecords / Caja menor (`templates/PettyCashRecords/edit.php:627`) | `Form->create` clásico, recarga | no |

Tres módulos hacen submit tradicional con recarga; Invoices ya hace AJAX pero con código inline duplicado conceptualmente. La meta es que los 4 módulos de listas de soportes (Invoices + los 3 rezagados) compartan el mismo helper JS, el mismo contrato JSON y el mismo render de fila.

PaymentSchedulings queda fuera de alcance: ya usa AJAX y su forma de upload es distinta (no es lista append-only como los demás).

## Decisiones de alcance

- **Unificación nivel A** — Helper JS compartido + contrato JSON común. No se construye un endpoint genérico tipo `/documents/upload/{entity}/{id}`; cada módulo conserva su `documentService` y su acción de controller (cada uno tiene reglas propias de qué se puede borrar, dónde se guarda, columnas distintas).
- **Invoices se migra al helper compartido** en la misma tanda. Es la implementación más rica (badges por estado, `can_delete` por documento) y sirve de referencia para el helper.
- **Field name normalizado a `file`.** Hoy NoveltyLiquidationDocs y EmployeeNovelties leen `getUploadedFile('document')`; se cambian a `'file'`. Invoices y PettyCashRecords ya usan `'file'`.
- **Uploads dedicados de NoveltyLiquidationDocs (`uploadLiquidationDocument` / `updateLiquidationDocument`, campo `liquidation_file`) quedan fuera de alcance.** Es un patrón distinto: archivo único reemplazable, no lista append-only. Se podrá migrar más adelante si molesta.
- **Render de fila vía `<template>` HTML clonable + partial PHP `templates/element/document_row.php`.** Sin endpoint extra que devuelva HTML; el server manda JSON, el cliente clona el `<template>` y rellena por `data-slot`. El partial PHP se usa solo para el render inicial server-side.
- **`can_delete` se decide en el server** y viaja en el payload JSON. Hoy Invoices lo evalúa en JS comparando `pipeline_status === currentStatus`; mover esa decisión al server elimina ramas en el helper y cierra una pequeña fuga de regla de negocio al cliente.

## Arquitectura

### Helper JS — `webroot/js/sgi-document-uploader.js`

Archivo nuevo, sin dependencias. Inicialización declarativa por opciones:

```js
window.SgiDocumentUploader.init({
  formSelector: '#upload-doc-form',
  listSelector: '#docs-list',
  emptySelector: '#docs-empty-state',
  counterSelector: '.sgi-folder-count',
  rowTemplateSelector: '#doc-row-template',
  modalSelector: '#uploadDocModal',
  csrfToken: '...'
});
```

Responsabilidades:

- Validar tamaño máximo en cliente leyendo `window.SGI_MAX_UPLOAD_BYTES` (fallback 20 MB) — alert y abort si excede.
- POST `multipart/form-data` con headers `X-Requested-With: XMLHttpRequest`, `X-CSRF-Token: <token>`, `Accept: application/json`.
- Al recibir `success: true` → clonar `<template>`, rellenar por `[data-slot]`, hacer append a `listSelector`, ocultar `emptySelector`, incrementar contador, cerrar modal Bootstrap, hacer reset al form.
- Al recibir `success: false` → `alert(data.error)`, modal queda abierto.
- Error de red → `alert('Error de conexión. Intente nuevamente.')`.
- **Delete:** delegación de eventos sobre `.doc-delete-btn[data-url]` dentro del listSelector → confirm, POST con CSRF, al `success: true` quitar la fila, decrementar contador, mostrar emptyState si quedó vacío.
- Funciones puras internas: `docIconClass(mime)`, `docIconColorVal(mime)`, `formatFileSize(bytes)`, `esc(s)`.

Tamaño esperado: ~120 líneas.

Carga: explícita por template vía `$this->Html->script('sgi-document-uploader', ['block' => true])` para no inflar páginas que no lo usan.

### Contrato JSON unificado

**Upload exitoso:**
```json
{
  "success": true,
  "document": {
    "id": 123,
    "file_name": "factura.pdf",
    "document_type": "Soporte",
    "mime_type": "application/pdf",
    "file_path": "uploads/invoices/...",
    "file_size": 102400,
    "created": "03/05/2026 14:32",
    "pipeline_status": "tesoreria",
    "can_delete": true
  }
}
```

**Upload fallido:** `{ "success": false, "error": "..." }`
**Delete exitoso:** `{ "success": true }`
**Delete fallido:** `{ "success": false, "error": "..." }`

`pipeline_status` puede ser `null` cuando el módulo no asocia estado al documento (PettyCashRecords y EmployeeNovelties). El template controla si lo muestra por presencia/ausencia del slot en el `<template>`.

`can_delete` lo calcula el controller invocando el método correspondiente del documentService del módulo (la regla puede diferir por módulo).

### Render de fila

**a) `<template id="doc-row-template">`** declarado en cada `edit.php`. Estructura:

```html
<template id="doc-row-template">
  <div class="doc-row" data-doc-id="" style="...">
    <div class="doc-icon"><i class="bi" style=""></i></div>
    <div class="doc-body">
      <div class="doc-label" data-slot="label" title=""></div>
      <div class="doc-filename" data-slot="filename" title=""></div>
      <div class="doc-meta">
        <span class="badge" data-slot="badge"></span>
        <span class="doc-created"><i class="bi bi-clock"></i> <span data-slot="created"></span></span>
        <span class="doc-size" data-slot="size"></span>
      </div>
    </div>
    <div class="doc-actions">
      <a class="btn btn-sm btn-outline-secondary" data-slot="open-link" target="_blank" title="Abrir">
        <i class="bi bi-box-arrow-up-right"></i>
      </a>
      <button type="button" class="btn btn-sm btn-outline-danger doc-delete-btn"
              data-slot="delete-btn" data-url="" title="Eliminar">
        <i class="bi bi-trash"></i>
      </button>
    </div>
  </div>
</template>
```

El helper rellena por `[data-slot]`, decide ícono/color por `mime_type`, oculta `delete-btn` si `can_delete=false`, oculta `badge` si `pipeline_status=null`, oculta `filename` si no hay `document_type`.

**b) Partial `templates/element/document_row.php`** — recibe `$doc`, `$canDelete`, `$badgeColors`, `$statusLabels`, `$deleteUrl`, `$openUrl` y emite el mismo markup para el render inicial server-side. Misma estructura, mismos `data-slot`.

Si la estructura cambia hay que cambiarla en los dos lugares (template HTML + partial PHP). La divergencia es trivialmente revisable porque ambos viven cerca conceptualmente y comparten `data-slot`.

## Cambios por módulo

### Invoices (refactor — sirve de referencia para el helper)

- `templates/Invoices/edit.php`:
  - Quitar el `<script>` inline de upload/delete (líneas ~1258-1410).
  - Reemplazar por `<script>SgiDocumentUploader.init({...})</script>`.
  - Reemplazar el render inline de filas en `<div id="docs-list">` por iteración sobre `<?= $this->element('document_row', [...]) ?>`.
  - Agregar `<template id="doc-row-template">`.
- `InvoicesController::uploadDocument()`:
  - Agregar `can_delete` al payload JSON (calcular vía `$this->documentService->canDeleteDocument(...)`).
- Sin cambios en `InvoiceDocumentService`.

### PettyCashRecords (alta nueva)

- `PettyCashRecordsController::uploadDocument()` (línea 518) y `deleteDocument()` (línea 568):
  - Agregar rama `if ($this->_isJsonRequest())` retornando el contrato unificado.
  - Payload con `pipeline_status: null`.
  - `can_delete`: preservar la regla actual (el delete actual no tiene chequeo previo en el controller; mantener ese comportamiento mapeando a `can_delete=true` salvo que el record esté pagado). Se evalúa con un método nuevo o adaptado en el documentService de petty cash.
- `templates/PettyCashRecords/edit.php`:
  - Reemplazar `Form->create` clásico (línea 627) por `<form id="upload-doc-form" data-url="..." enctype="multipart/form-data">`. Campo ya se llama `file`.
  - Agregar `<template id="doc-row-template">`.
  - Iterar partial `document_row` para render inicial de la lista.
  - Bloque `<script>` con `SgiDocumentUploader.init`.

### EmployeeNovelties (alta nueva + cambio de field name)

- `EmployeeNoveltiesController`:
  - Si no existen, agregar acciones `uploadDocument`/`deleteDocument` siguiendo el patrón del resto (delegando al document service correspondiente).
  - Leer `getUploadedFile('file')` (no `'document'`).
  - Rama JSON con contrato unificado.
- `templates/EmployeeNovelties/edit.php`:
  - Cambiar `name="document"` (línea 627) → `name="file"`.
  - Mismo patrón que PettyCash.

### NoveltyLiquidationDocs (alta nueva + cambio de field name)

- `NoveltyLiquidationDocsController::uploadDocument()` (línea 321):
  - Cambiar `getUploadedFile('document')` (línea 326) → `getUploadedFile('file')`.
  - Agregar rama JSON con contrato unificado, incluyendo `pipeline_status` (este módulo sí tiene estado por documento) y `can_delete` calculado vía `$this->documentService->canDeleteDocument(...)`.
- `NoveltyLiquidationDocsController::deleteDocument()` (línea 423):
  - Agregar rama JSON con contrato unificado.
- `templates/NoveltyLiquidationDocs/edit.php`:
  - Migrar **solo** el formulario de la lista de soportes (línea 615).
  - Cambiar `name="document"` (línea 623) → `name="file"`.
  - Los formularios de `liquidation_file` (líneas 437 y 467) **no se tocan**.
  - `uploadLiquidationDocument` / `updateLiquidationDocument` no se tocan.

### Capa común

- Crear `webroot/js/sgi-document-uploader.js`.
- Crear `templates/element/document_row.php`.
- Cargar el helper JS explícitamente en cada `edit.php` que lo use:
  ```php
  <?= $this->Html->script('sgi-document-uploader', ['block' => true]) ?>
  ```

## Asimetrías toleradas

- Cada módulo conserva su `documentService` y su tabla de documentos. La regla de "qué se puede borrar" puede diferir y eso está bien — viaja como `can_delete` boolean.
- Los `pipeline_status` y `badgeColors`/`statusLabels` solo aplican a Invoices y NoveltyLiquidationDocs. PettyCashRecords y EmployeeNovelties simplemente no incluyen el `<span data-slot="badge">` en su `<template>`.

## Validación manual

Tras el merge, levantar `php bin/cake server` y para **cada uno de los 4 módulos** (Invoices, PettyCashRecords, EmployeeNovelties, NoveltyLiquidationDocs) en su `edit`:

1. Subir archivo válido (PDF < 20 MB) → modal se cierra, fila aparece al final del listado sin recargar, contador `.sgi-folder-count` incrementa en 1, empty-state desaparece si era la primera.
2. Subir archivo > 20 MB → `alert()` con mensaje de tamaño máximo, no hay request al servidor, modal sigue abierto.
3. Subir sin archivo → input `required` bloquea el submit (validación nativa).
4. Eliminar documento (cuando `can_delete=true`) → confirm `¿Eliminar este soporte?`, fila desaparece sin recarga, contador decrementa, empty-state reaparece si fue el último.
5. Botón eliminar ausente cuando el server marca `can_delete=false` (caso típico Invoices: documento de un estado anterior).
6. Error simulado (apagar DB o forzar 500) → `alert('Error de conexión...')`, modal sigue abierto, contador no cambia.
7. Recargar la página tras subir → la fila persiste y se renderiza idéntica a la que pintó el JS (verifica sincronía partial PHP ↔ `<template>`).

Cross-cutting:

8. Inspeccionar respuestas en DevTools → las 8 acciones (4 módulos × upload+delete) devuelven el mismo schema JSON.
9. Sin errores en consola JS.
10. CSRF: borrar cookie y subir → 403, alert de error, modal abierto (no debe romper el helper).
11. Verificar que NoveltyLiquidationDocs sigue manejando el upload de `liquidation_file` correctamente con submit clásico (fuera de alcance).

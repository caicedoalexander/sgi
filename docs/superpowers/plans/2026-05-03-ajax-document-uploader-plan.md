# AJAX unificado para subida de documentos — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unificar la subida AJAX de documentos en los 4 módulos de listas de soportes (Invoices, PettyCashRecords, EmployeeNovelties vía NoveltyDocuments, NoveltyLiquidationDocs) usando un helper JS compartido y un contrato JSON común.

**Architecture:** Helper JS único (`webroot/js/sgi-document-uploader.js`) + partial PHP único (`templates/element/document_row.php`) + `<template>` HTML clonable por página. Cada controller mantiene su `documentService`, agrega rama JSON con contrato uniforme, y `can_delete` se calcula server-side.

**Tech Stack:** CakePHP 5.3 / PHP 8.2 / JS vanilla. Sin dependencias nuevas.

**Spec:** `docs/superpowers/specs/2026-05-03-ajax-document-uploader-design.md`

**Notas:**
- No hay tests automatizados en el proyecto (ver `CLAUDE.md`). Cada tarea termina con un paso de **validación manual** que ejecuta el usuario (`php bin/cake server`) y un commit.
- PaymentSchedulings ya hace AJAX y queda fuera de alcance.
- Los uploads de `liquidation_file` en NoveltyLiquidationDocs (acciones `uploadLiquidationDocument`/`updateLiquidationDocument`) **no se tocan**.
- Para EmployeeNovelties, las acciones de upload/delete viven en `NoveltyDocumentsController` (no en `EmployeeNoveltiesController`), aunque las URLs sean `/employee-novelties/upload-document/{id}` (ver `config/routes.php:195-203`).

---

## Estructura de archivos

**Crear:**
- `templates/element/document_row.php` — partial server-side de una fila de documento.
- `templates/element/document_row_template.php` — emite el `<template id="doc-row-template">` para el clone client-side.
- `webroot/js/sgi-document-uploader.js` — helper JS reusable.

**Modificar (controllers):**
- `src/Controller/InvoicesController.php` (línea 578-628) — agregar `can_delete` al payload JSON.
- `src/Controller/PettyCashRecordsController.php` (líneas 518, 568) — agregar rama JSON.
- `src/Controller/NoveltyDocumentsController.php` (todo) — agregar rama JSON, cambiar field name.
- `src/Controller/NoveltyLiquidationDocsController.php` (líneas 321, 423) — agregar rama JSON, cambiar field name.

**Modificar (templates):**
- `templates/Invoices/edit.php` — extraer JS inline, usar partial + `<template>`.
- `templates/PettyCashRecords/edit.php` — convertir a AJAX.
- `templates/EmployeeNovelties/edit.php` — convertir a AJAX, cambiar `name="document"` → `name="file"`.
- `templates/NoveltyLiquidationDocs/edit.php` — migrar **solo** el form de la lista de soportes, cambiar `name="document"` → `name="file"`.

---

## Task 1: Crear partial `document_row.php`

**Files:**
- Create: `templates/element/document_row.php`

- [ ] **Step 1: Escribir el partial**

Crear `templates/element/document_row.php` con el siguiente contenido. Este partial es la única fuente de verdad del markup de una fila para render server-side. Recibe `$doc` (entity) y opcionalmente `$canDelete`, `$deleteUrl`, `$badgeColors`, `$statusLabels`, `$showBadge`.

```php
<?php
/**
 * Document row partial — used by Invoices, PettyCashRecords, EmployeeNovelties,
 * NoveltyLiquidationDocs for both initial server render and as the structural
 * twin of the <template id="doc-row-template"> used by sgi-document-uploader.js.
 *
 * Required: $doc (entity with id, file_name, document_type, mime_type, file_path,
 *           file_size, created, pipeline_status [optional]).
 * Optional: $canDelete (bool, default false)
 *           $deleteUrl (string, required if $canDelete)
 *           $showBadge (bool, default false) — show pipeline_status badge
 *           $badgeColors (array<string,string>) — required if $showBadge
 *           $statusLabels (array<string,string>) — required if $showBadge
 */
$canDelete    = $canDelete    ?? false;
$showBadge    = $showBadge    ?? false;
$badgeColors  = $badgeColors  ?? [];
$statusLabels = $statusLabels ?? [];
$deleteUrl    = $deleteUrl    ?? '';

$mime = $doc->mime_type ?? '';
$icon = 'bi-file-earmark';
$iconColor = '#aaa';
if (str_contains($mime, 'pdf'))                                         { $icon = 'bi-file-earmark-pdf';   $iconColor = '#dc3545'; }
elseif (str_contains($mime, 'image'))                                   { $icon = 'bi-file-earmark-image'; $iconColor = '#0dcaf0'; }
elseif (str_contains($mime, 'wordprocessingml') || str_contains($mime, 'msword')) { $icon = 'bi-file-earmark-word';  $iconColor = '#0d6efd'; }
elseif (str_contains($mime, 'spreadsheet') || str_contains($mime, 'excel'))       { $icon = 'bi-file-earmark-excel'; $iconColor = 'var(--primary-color)'; }

$label = $doc->document_type ?: $doc->file_name;
$badgeClass = $badgeColors[$doc->pipeline_status ?? ''] ?? 'bg-secondary';
$badgeLabel = $statusLabels[$doc->pipeline_status ?? ''] ?? ($doc->pipeline_status ?? '');
?>
<div class="doc-row" data-doc-id="<?= h($doc->id) ?>"
     style="display:flex;align-items:flex-start;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);">
    <div class="doc-icon"
         style="width:34px;height:34px;flex-shrink:0;background:#f5f5f5;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
        <i class="bi <?= h($icon) ?>" style="color:<?= h($iconColor) ?>;font-size:1rem;"></i>
    </div>
    <div class="doc-body" style="flex:1;min-width:0;">
        <div class="doc-label" data-slot="label"
             style="font-size:.79rem;font-weight:600;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.35;"
             title="<?= h($label) ?>"><?= h($label) ?></div>
        <?php if ($doc->document_type): ?>
        <div class="doc-filename" data-slot="filename"
             style="font-size:.7rem;color:#999;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:.1rem;"
             title="<?= h($doc->file_name) ?>"><?= h($doc->file_name) ?></div>
        <?php endif; ?>
        <div class="doc-meta" style="display:flex;align-items:center;gap:.5rem;margin-top:.35rem;flex-wrap:wrap;">
            <?php if ($showBadge && ($doc->pipeline_status ?? null)): ?>
            <span class="badge <?= h($badgeClass) ?>" data-slot="badge" style="font-size:.58rem;"><?= h($badgeLabel) ?></span>
            <?php endif; ?>
            <span class="doc-created" style="font-size:.65rem;color:#bbb;">
                <i class="bi bi-clock" style="font-size:.6rem;"></i>
                <span data-slot="created"><?= h($doc->created?->format('d/m/Y H:i')) ?></span>
            </span>
            <?php if ($doc->file_size): ?>
            <span class="doc-size" data-slot="size" style="font-size:.63rem;color:#ccc;"><?= $this->Number->toReadableSize($doc->file_size) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="doc-actions" style="display:flex;gap:.25rem;flex-shrink:0;align-self:center;">
        <a class="btn btn-sm btn-outline-secondary" data-slot="open-link"
           href="/<?= h($doc->file_path) ?>" target="_blank" title="Abrir"
           style="padding:.25rem .45rem;font-size:.72rem;line-height:1;">
            <i class="bi bi-box-arrow-up-right"></i>
        </a>
        <?php if ($canDelete): ?>
        <button type="button" class="btn btn-sm btn-outline-danger doc-delete-btn"
                data-slot="delete-btn" data-url="<?= h($deleteUrl) ?>"
                style="padding:.25rem .45rem;font-size:.72rem;line-height:1;" title="Eliminar">
            <i class="bi bi-trash"></i>
        </button>
        <?php endif; ?>
    </div>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add templates/element/document_row.php
git commit -m "feat(documents): partial reusable document_row.php para listas de soportes"
```

---

## Task 2: Crear partial `document_row_template.php`

**Files:**
- Create: `templates/element/document_row_template.php`

- [ ] **Step 1: Escribir el `<template>`**

Este partial emite el `<template id="doc-row-template">` que el helper JS clonará. Comparte estructura y `data-slot` con `document_row.php`.

```php
<?php
/**
 * Emits <template id="doc-row-template"> for sgi-document-uploader.js to clone.
 * Mirror of document_row.php structure (same data-slot keys).
 *
 * Optional: $showBadge (bool, default false) — include the badge slot for modules
 *           with per-document pipeline_status (Invoices, NoveltyLiquidationDocs).
 */
$showBadge = $showBadge ?? false;
?>
<template id="doc-row-template">
    <div class="doc-row" data-doc-id=""
         style="display:flex;align-items:flex-start;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);">
        <div class="doc-icon"
             style="width:34px;height:34px;flex-shrink:0;background:#f5f5f5;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
            <i class="bi" style="font-size:1rem;"></i>
        </div>
        <div class="doc-body" style="flex:1;min-width:0;">
            <div class="doc-label" data-slot="label"
                 style="font-size:.79rem;font-weight:600;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.35;"></div>
            <div class="doc-filename" data-slot="filename"
                 style="font-size:.7rem;color:#999;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:.1rem;display:none;"></div>
            <div class="doc-meta" style="display:flex;align-items:center;gap:.5rem;margin-top:.35rem;flex-wrap:wrap;">
                <?php if ($showBadge): ?>
                <span class="badge" data-slot="badge" style="font-size:.58rem;display:none;"></span>
                <?php endif; ?>
                <span class="doc-created" style="font-size:.65rem;color:#bbb;">
                    <i class="bi bi-clock" style="font-size:.6rem;"></i>
                    <span data-slot="created"></span>
                </span>
                <span class="doc-size" data-slot="size" style="font-size:.63rem;color:#ccc;display:none;"></span>
            </div>
        </div>
        <div class="doc-actions" style="display:flex;gap:.25rem;flex-shrink:0;align-self:center;">
            <a class="btn btn-sm btn-outline-secondary" data-slot="open-link"
               href="" target="_blank" title="Abrir"
               style="padding:.25rem .45rem;font-size:.72rem;line-height:1;">
                <i class="bi bi-box-arrow-up-right"></i>
            </a>
            <button type="button" class="btn btn-sm btn-outline-danger doc-delete-btn"
                    data-slot="delete-btn" data-url=""
                    style="padding:.25rem .45rem;font-size:.72rem;line-height:1;display:none;" title="Eliminar">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Commit**

```bash
git add templates/element/document_row_template.php
git commit -m "feat(documents): partial document_row_template.php espejo del row server-side"
```

---

## Task 3: Crear helper JS `sgi-document-uploader.js`

**Files:**
- Create: `webroot/js/sgi-document-uploader.js`

- [ ] **Step 1: Escribir el helper**

```javascript
/**
 * SgiDocumentUploader
 * Helper unificado para subida y eliminación AJAX de documentos en módulos
 * de flujo (Invoices, PettyCashRecords, EmployeeNovelties, NoveltyLiquidationDocs).
 *
 * Uso:
 *   SgiDocumentUploader.init({
 *     formSelector:        '#upload-doc-form',
 *     listSelector:        '#docs-list',
 *     emptySelector:       '#docs-empty-state',
 *     counterSelector:     '.card-header .sgi-folder-count',
 *     rowTemplateSelector: '#doc-row-template',
 *     modalSelector:       '#uploadDocModal',
 *     csrfToken:           '...'
 *   });
 *
 * Contrato JSON esperado:
 *   Upload OK:    { success: true, document: { id, file_name, document_type,
 *                   mime_type, file_path, file_size, created,
 *                   pipeline_status?, can_delete } }
 *   Upload fail:  { success: false, error: '...' }
 *   Delete OK:    { success: true }
 *   Delete fail:  { success: false, error: '...' }
 */
(function (global) {
    'use strict';

    function docIconClass(mime) {
        mime = mime || '';
        if (mime.indexOf('pdf') !== -1) return 'bi-file-earmark-pdf';
        if (mime.indexOf('image') !== -1) return 'bi-file-earmark-image';
        if (mime.indexOf('wordprocessingml') !== -1 || mime.indexOf('msword') !== -1) return 'bi-file-earmark-word';
        if (mime.indexOf('spreadsheet') !== -1 || mime.indexOf('excel') !== -1) return 'bi-file-earmark-excel';
        return 'bi-file-earmark';
    }

    function docIconColor(mime) {
        mime = mime || '';
        if (mime.indexOf('pdf') !== -1) return '#dc3545';
        if (mime.indexOf('image') !== -1) return '#0dcaf0';
        if (mime.indexOf('wordprocessingml') !== -1 || mime.indexOf('msword') !== -1) return '#0d6efd';
        if (mime.indexOf('spreadsheet') !== -1 || mime.indexOf('excel') !== -1) return 'var(--primary-color)';
        return '#aaa';
    }

    function formatFileSize(bytes) {
        if (!bytes) return '';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function setSlot(root, slot, value, attr) {
        var el = root.querySelector('[data-slot="' + slot + '"]');
        if (!el) return null;
        if (attr) {
            el.setAttribute(attr, value);
        } else {
            el.textContent = value;
        }
        return el;
    }

    function buildDocRow(template, doc) {
        var clone = template.content.firstElementChild.cloneNode(true);
        clone.setAttribute('data-doc-id', doc.id);

        // Icon
        var iconEl = clone.querySelector('.doc-icon i');
        if (iconEl) {
            iconEl.classList.add(docIconClass(doc.mime_type));
            iconEl.style.color = docIconColor(doc.mime_type);
        }

        // Label
        var label = doc.document_type || doc.file_name;
        var labelEl = setSlot(clone, 'label', label);
        if (labelEl) labelEl.title = label;

        // Filename (only when document_type is set, since label already shows file_name otherwise)
        var filenameEl = clone.querySelector('[data-slot="filename"]');
        if (filenameEl) {
            if (doc.document_type) {
                filenameEl.textContent = doc.file_name;
                filenameEl.title = doc.file_name;
                filenameEl.style.display = '';
            } else {
                filenameEl.style.display = 'none';
            }
        }

        // Badge
        var badgeEl = clone.querySelector('[data-slot="badge"]');
        if (badgeEl) {
            if (doc.pipeline_status && doc.badge_class && doc.badge_label) {
                badgeEl.classList.add(doc.badge_class);
                badgeEl.textContent = doc.badge_label;
                badgeEl.style.display = '';
            } else {
                badgeEl.style.display = 'none';
            }
        }

        // Created
        setSlot(clone, 'created', doc.created || '');

        // Size
        var sizeEl = clone.querySelector('[data-slot="size"]');
        if (sizeEl) {
            if (doc.file_size) {
                sizeEl.textContent = formatFileSize(doc.file_size);
                sizeEl.style.display = '';
            } else {
                sizeEl.style.display = 'none';
            }
        }

        // Open link
        var openEl = clone.querySelector('[data-slot="open-link"]');
        if (openEl) openEl.href = '/' + doc.file_path;

        // Delete button
        var deleteEl = clone.querySelector('[data-slot="delete-btn"]');
        if (deleteEl) {
            if (doc.can_delete && doc.delete_url) {
                deleteEl.setAttribute('data-url', doc.delete_url);
                deleteEl.style.display = '';
            } else {
                deleteEl.style.display = 'none';
            }
        }

        return clone;
    }

    function updateCounter(el, delta) {
        if (!el) return;
        var m = el.textContent.match(/(\d+)/);
        var n = m ? parseInt(m[1], 10) + delta : delta;
        if (n < 0) n = 0;
        el.textContent = n + ' doc' + (n !== 1 ? 's' : '');
    }

    function init(opts) {
        var form         = document.querySelector(opts.formSelector);
        var list         = document.querySelector(opts.listSelector);
        var emptyState   = document.querySelector(opts.emptySelector);
        var counter      = document.querySelector(opts.counterSelector);
        var template     = document.querySelector(opts.rowTemplateSelector);
        var modalEl      = opts.modalSelector ? document.querySelector(opts.modalSelector) : null;
        var csrfToken    = opts.csrfToken || '';
        var submitBtn    = form ? form.querySelector('button[type="submit"]') : null;

        if (!list || !template) return;

        // ─── Upload ───
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var fileInput = form.querySelector('input[type="file"]');
                if (!fileInput || !fileInput.files.length) return;

                var file = fileInput.files[0];
                var maxBytes = global.SGI_MAX_UPLOAD_BYTES || (20 * 1024 * 1024);
                var maxLabel = global.SGI_MAX_UPLOAD_LABEL || '20 MB';
                if (file.size > maxBytes) {
                    alert('El archivo supera el tamaño máximo de ' + maxLabel + '.');
                    return;
                }

                var originalHtml = submitBtn ? submitBtn.innerHTML : '';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Subiendo...';
                }

                fetch(form.dataset.url, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: new FormData(form),
                    redirect: 'follow'
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        if (emptyState) emptyState.style.display = 'none';
                        list.appendChild(buildDocRow(template, data.document));
                        updateCounter(counter, 1);
                        form.reset();
                        if (modalEl && global.bootstrap) {
                            var modal = global.bootstrap.Modal.getInstance(modalEl);
                            if (modal) modal.hide();
                        }
                    } else {
                        alert(data.error || 'Error al subir el archivo.');
                    }
                })
                .catch(function () { alert('Error de conexión. Intente nuevamente.'); })
                .finally(function () {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalHtml; }
                });
            });
        }

        // ─── Delete (event delegation on list) ───
        list.addEventListener('click', function (e) {
            var btn = e.target.closest('.doc-delete-btn');
            if (!btn || !list.contains(btn)) return;
            if (!confirm('¿Eliminar este soporte?')) return;

            btn.disabled = true;
            fetch(btn.dataset.url, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken,
                    'Accept': 'application/json'
                }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var row = btn.closest('.doc-row');
                    if (row) row.remove();
                    updateCounter(counter, -1);
                    if (!list.querySelector('.doc-row') && emptyState) emptyState.style.display = '';
                } else {
                    alert(data.error || 'Error al eliminar.');
                    btn.disabled = false;
                }
            })
            .catch(function () {
                alert('Error de conexión. Intente nuevamente.');
                btn.disabled = false;
            });
        });
    }

    global.SgiDocumentUploader = { init: init };
})(window);
```

- [ ] **Step 2: Commit**

```bash
git add webroot/js/sgi-document-uploader.js
git commit -m "feat(documents): helper JS unificado SgiDocumentUploader (upload + delete AJAX)"
```

---

## Task 4: Refactor Invoices — controller (agregar `can_delete` y datos de badge al payload)

**Files:**
- Modify: `src/Controller/InvoicesController.php` (líneas 578-628)

- [ ] **Step 1: Modificar el payload JSON de `uploadDocument`**

Reemplazar el bloque que arma la respuesta JSON (`InvoicesController::uploadDocument`, ~líneas 602-619) por uno que incluya `can_delete`, `badge_class`, `badge_label` y `delete_url`:

Localizar:
```php
        if ($this->_isJsonRequest()) {
            if (is_string($result)) {
                return $this->_jsonResponse(['success' => false, 'error' => $result]);
            }

            return $this->_jsonResponse([
                'success' => true,
                'document' => [
                    'id' => $result->id,
                    'file_name' => $result->file_name,
                    'document_type' => $result->document_type,
                    'mime_type' => $result->mime_type,
                    'file_path' => $result->file_path,
                    'file_size' => $result->file_size,
                    'pipeline_status' => $result->pipeline_status,
                    'created' => $result->created->format('d/m/Y H:i'),
                ],
            ]);
        }
```

Reemplazar por (las constantes `BADGE_COLORS` y `STATUS_LABELS` ya las usa el template; reutilizamos las del controller — si no existen como propiedades, leerlas desde donde el `edit` las inyecta. Ver paso siguiente para localizarlas):

```php
        if ($this->_isJsonRequest()) {
            if (is_string($result)) {
                return $this->_jsonResponse(['success' => false, 'error' => $result]);
            }

            $canDelete = $this->documentService->canDeleteDocument($result, $invoice->pipeline_status);
            [$badgeColors, $statusLabels] = $this->_invoiceDocumentLabels();

            return $this->_jsonResponse([
                'success' => true,
                'document' => [
                    'id' => $result->id,
                    'file_name' => $result->file_name,
                    'document_type' => $result->document_type,
                    'mime_type' => $result->mime_type,
                    'file_path' => $result->file_path,
                    'file_size' => $result->file_size,
                    'pipeline_status' => $result->pipeline_status,
                    'created' => $result->created->format('d/m/Y H:i'),
                    'can_delete' => $canDelete,
                    'badge_class' => $badgeColors[$result->pipeline_status] ?? 'bg-secondary',
                    'badge_label' => $statusLabels[$result->pipeline_status] ?? $result->pipeline_status,
                    'delete_url' => $canDelete
                        ? \Cake\Routing\Router::url(['action' => 'deleteDocument', $invoice->id, $result->id])
                        : null,
                ],
            ]);
        }
```

- [ ] **Step 2: Localizar dónde se definen `badgeColors` y `statusLabels` para reutilizarlos**

Buscar:
```bash
grep -n "badgeColors\|statusLabels" src/Controller/InvoicesController.php templates/Invoices/edit.php
```

Si están definidos solo en el template (`edit.php`), extraer ambas a un método privado en el controller:

Agregar al final de `InvoicesController` (antes del cierre de la clase):

```php
    /**
     * Returns invoice document badge colors and status labels.
     * Single source for both edit template and uploadDocument JSON payload.
     *
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    private function _invoiceDocumentLabels(): array
    {
        $badgeColors = [
            \App\Constants\InvoiceConstants::STATUS_APROBACION       => 'bg-warning text-dark',
            \App\Constants\InvoiceConstants::STATUS_CONTABILIDAD     => 'bg-info text-dark',
            \App\Constants\InvoiceConstants::STATUS_TESORERIA        => 'bg-primary',
            \App\Constants\InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'bg-secondary',
            \App\Constants\InvoiceConstants::STATUS_PAGADA           => 'bg-success',
        ];
        $statusLabels = [
            \App\Constants\InvoiceConstants::STATUS_APROBACION       => 'Aprobación',
            \App\Constants\InvoiceConstants::STATUS_CONTABILIDAD     => 'Contabilidad',
            \App\Constants\InvoiceConstants::STATUS_TESORERIA        => 'Tesorería',
            \App\Constants\InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'Autorización Pago',
            \App\Constants\InvoiceConstants::STATUS_PAGADA           => 'Pagada',
        ];

        return [$badgeColors, $statusLabels];
    }
```

**Importante:** revisar el `grep` del paso anterior — si los arrays ya existen con otros valores en el template, **copiar exactamente esos valores** al método privado (no inventar valores). Luego en `edit()` (acción que renderiza el template), reemplazar la definición original por:

```php
[$badgeColors, $statusLabels] = $this->_invoiceDocumentLabels();
$this->set(compact('badgeColors', 'statusLabels'));
```

- [ ] **Step 3: Validación manual**

Pedir al usuario que en `Invoices/edit` suba un soporte y verifique que la fila aparece sin recargar (comportamiento actual no debe romperse), y que en DevTools la respuesta JSON ahora incluya `can_delete`, `badge_class`, `badge_label`, `delete_url`.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/InvoicesController.php
git commit -m "refactor(invoices): incluir can_delete y datos de badge en payload de uploadDocument"
```

---

## Task 5: Refactor Invoices — template (usar partial + `<template>`, eliminar JS inline de upload/delete)

**Files:**
- Modify: `templates/Invoices/edit.php` (líneas ~1004-1060 render de filas, ~1258-1410 JS inline)

- [ ] **Step 1: Reemplazar render de filas inline por el partial**

Localizar el bloque `<div id="docs-list">` (línea ~1004) y reemplazar el `foreach` interno por llamadas al element. El bloque resultante queda así:

```php
    <div id="docs-list" style="max-height:420px;overflow-y:auto;">
        <?php
        $multipleStatuses = count($documentsByStatus) > 1;
        foreach ($documentsByStatus as $status => $docs):
        ?>
        <?php if ($multipleStatuses): ?>
        <div style="padding:.3rem .875rem;background:#f8f9fa;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:.4rem;">
            <span class="badge <?= $badgeColors[$status] ?? 'bg-secondary' ?>" style="font-size:.6rem;"><?= $statusLabels[$status] ?? $status ?></span>
            <span style="font-size:.67rem;color:#aaa;"><?= count($docs) ?> archivo<?= count($docs) !== 1 ? 's' : '' ?></span>
        </div>
        <?php endif; ?>
        <?php foreach ($docs as $doc): ?>
            <?= $this->element('document_row', [
                'doc'          => $doc,
                'canDelete'    => $canDeleteDocuments && $doc->pipeline_status === $currentStatus,
                'deleteUrl'    => $this->Url->build(['action' => 'deleteDocument', $invoice->id, $doc->id]),
                'showBadge'    => !$multipleStatuses,
                'badgeColors'  => $badgeColors,
                'statusLabels' => $statusLabels,
            ]) ?>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
```

- [ ] **Step 2: Insertar el `<template>` y la carga del helper**

Justo después del modal `#uploadInvoiceDocModal` (línea ~1163, dentro del `<?php if ($showUploadSection): ?>` no — el template y el JS deben cargarse siempre que haya lista, no solo cuando se puede subir; ponerlo fuera del `endif`), agregar:

```php
<?= $this->element('document_row_template', ['showBadge' => true]) ?>
<?= $this->Html->script('sgi-document-uploader', ['block' => true]) ?>
```

- [ ] **Step 3: Reemplazar JS inline de upload/delete por `SgiDocumentUploader.init`**

En el bloque `<?php $this->append('script') ?>` (línea ~1165), eliminar **todo el código** desde el comentario `// ── AJAX: Upload document ──` (línea ~1258) hasta el cierre del listener de delete (línea ~1410, antes del `})();`). Conservar la lógica de observaciones (que está antes) y los auto-resize textareas.

En su lugar insertar:

```javascript
    // ── Documents (upload + delete) via shared helper ──
    SgiDocumentUploader.init({
        formSelector:        '#upload-doc-form',
        listSelector:        '#docs-list',
        emptySelector:       '#docs-empty-state',
        counterSelector:     '.card.card-primary .card-header .sgi-folder-count',
        rowTemplateSelector: '#doc-row-template',
        modalSelector:       '#uploadInvoiceDocModal',
        csrfToken:           <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
    });
```

- [ ] **Step 4: Renombrar el form id si hace falta**

El helper espera `formSelector: '#upload-doc-form'`. Verificar línea 1139:
```php
<form id="upload-doc-form" data-url="...">
```
Ya tiene ese id — sin cambios.

- [ ] **Step 5: Validación manual**

Pedir al usuario que en `Invoices/edit`:
1. Suba un soporte → fila aparece sin recargar.
2. Recargue la página → la fila persiste y se ve idéntica.
3. Elimine el soporte (cuando aplique) → desaparece sin recargar.
4. Sin errores en la consola JS.

- [ ] **Step 6: Commit**

```bash
git add templates/Invoices/edit.php
git commit -m "refactor(invoices): migrar upload/delete de soportes al helper SgiDocumentUploader"
```

---

## Task 6: PettyCashRecords — controller (agregar rama JSON)

**Files:**
- Modify: `src/Controller/PettyCashRecordsController.php` (líneas 518-545, 568-579)

- [ ] **Step 1: Agregar rama JSON a `uploadDocument`**

Localizar el método `uploadDocument` (línea 518). Reemplazar la implementación completa por:

```php
    public function uploadDocument($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PettyCashRecords->get($id);

        $file = $this->request->getUploadedFile('file');
        if (!$file) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'No se recibió ningún archivo válido.']);
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

            return $this->_jsonResponse([
                'success' => true,
                'document' => [
                    'id' => $result->id,
                    'file_name' => $result->file_name,
                    'document_type' => $result->document_type,
                    'mime_type' => $result->mime_type,
                    'file_path' => $result->file_path,
                    'file_size' => $result->file_size,
                    'pipeline_status' => null,
                    'created' => $result->created->format('d/m/Y H:i'),
                    'can_delete' => $canDelete,
                    'delete_url' => $canDelete
                        ? \Cake\Routing\Router::url(['action' => 'deleteDocument', $id, $result->id])
                        : null,
                ],
            ]);
        }

        if (is_string($result)) {
            $this->Flash->error($result);
        } else {
            $this->Flash->success('El soporte ha sido subido.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }
```

- [ ] **Step 2: Agregar rama JSON a `deleteDocument`**

Localizar el método `deleteDocument` (línea 568). Reemplazar por:

```php
    public function deleteDocument($recordId = null, $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $record = $this->PettyCashRecords->get($recordId);

        if ($record->isPagado()) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'No se puede eliminar un soporte de un registro pagado.']);
            }
            $this->Flash->error('No se puede eliminar un soporte de un registro pagado.');

            return $this->redirect(['action' => 'edit', $recordId]);
        }

        $deleted = $this->documentService->deleteDocument((int)$documentId);

        if ($this->_isJsonRequest()) {
            return $this->_jsonResponse(
                $deleted
                    ? ['success' => true]
                    : ['success' => false, 'error' => 'No se pudo eliminar el soporte.']
            );
        }

        if ($deleted) {
            $this->Flash->success('El soporte ha sido eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el soporte.');
        }

        return $this->redirect(['action' => 'edit', $recordId]);
    }
```

- [ ] **Step 3: Commit**

```bash
git add src/Controller/PettyCashRecordsController.php
git commit -m "feat(petty-cash): rama JSON en uploadDocument/deleteDocument"
```

---

## Task 7: PettyCashRecords — template (migrar a AJAX)

**Files:**
- Modify: `templates/PettyCashRecords/edit.php` (líneas ~485-548 lista de docs, ~622-648 modal)

- [ ] **Step 1: Reemplazar render inline de filas por el partial**

Localizar el bloque que itera `$documentsByStatus` en el card "Soportes" (alrededor de líneas 485-548) y reemplazar cada fila por una llamada al element `document_row`. La estructura final (preservando empty-state y agrupado por status si aplica):

```php
<div class="card card-primary">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip" style="font-size:.85rem;"></i>
            <span style="font-size:.85rem;font-weight:600;">Soportes</span>
            <span class="sgi-folder-count"><?= count($docs) ?> doc<?= count($docs) !== 1 ? 's' : '' ?></span>
        </span>
        <?php if (!$record->isPagado()): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadPcDocModal">
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

**Nota:** la variable que itera la lista actual en este template puede llamarse distinto (`$documentsByStatus` u otra). Reemplazar `$docs` arriba por la variable real que el controlador `edit` esté pasando. Si la vista actual usa `$documentsByStatus`, mapear:

```php
<?php
$flatDocs = [];
foreach ($documentsByStatus ?? [] as $statusDocs) {
    foreach ($statusDocs as $d) { $flatDocs[] = $d; }
}
?>
... iterar $flatDocs en lugar de $docs ...
<span class="sgi-folder-count"><?= count($flatDocs) ?> doc<?= count($flatDocs) !== 1 ? 's' : '' ?></span>
```

(Verificar primero qué variable usa el `edit()` action y ajustar.)

- [ ] **Step 2: Convertir el form modal a AJAX**

Localizar el modal `#uploadPcDocModal` (línea ~624). Reemplazar el `Form->create` por un form HTML con `id` y `data-url`:

```html
<div class="modal fade" id="uploadPcDocModal" tabindex="-1">
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
```

- [ ] **Step 3: Insertar `<template>` + helper + init**

Después del cierre del modal (después del `<?php endif; ?>` que envuelve el modal cuando aplique), agregar:

```php
<?= $this->element('document_row_template', ['showBadge' => false]) ?>
<?= $this->Html->script('sgi-document-uploader', ['block' => true]) ?>
```

Y dentro del bloque `<?php $this->append('script') ?>` existente, antes del `})();` final, agregar:

```javascript
    SgiDocumentUploader.init({
        formSelector:        '#upload-doc-form',
        listSelector:        '#docs-list',
        emptySelector:       '#docs-empty-state',
        counterSelector:     '.card.card-primary .card-header .sgi-folder-count',
        rowTemplateSelector: '#doc-row-template',
        modalSelector:       '#uploadPcDocModal',
        csrfToken:           <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
    });
```

- [ ] **Step 4: Validación manual**

Pedir al usuario que en `PettyCashRecords/edit`:
1. Suba un soporte → modal cierra, fila aparece sin recargar.
2. Suba archivo > 20 MB → alert, modal abierto, sin request.
3. Elimine un soporte (registro no pagado) → desaparece sin recargar.
4. En un registro pagado, el botón "Subir" no debe aparecer; los iconos de eliminar tampoco.
5. Recargar la página tras subir → la fila persiste idéntica.

- [ ] **Step 5: Commit**

```bash
git add templates/PettyCashRecords/edit.php
git commit -m "feat(petty-cash): subida de soportes vía AJAX con helper SgiDocumentUploader"
```

---

## Task 8: NoveltyDocumentsController (EmployeeNovelties) — controller (rama JSON + field name)

**Files:**
- Modify: `src/Controller/NoveltyDocumentsController.php` (todo)

- [ ] **Step 1: Reemplazar `upload` y `delete` con ramas JSON y campo `file`**

Reemplazar el contenido completo del archivo por:

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\NoveltyDocumentService;
use Cake\Routing\Router;

class NoveltyDocumentsController extends AppController
{
    private NoveltyDocumentService $documentService;

    public function initialize(): void
    {
        parent::initialize();
        $this->documentService = $this->getContainer()->get(NoveltyDocumentService::class);
    }

    public function upload(?string $noveltyId = null)
    {
        $this->request->allowMethod(['post']);
        $noveltiesTable = $this->fetchTable('EmployeeNovelties');
        $novelty = $noveltiesTable->get($noveltyId);
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $file = $this->request->getUploadedFile('file');

        if (!$file) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'No se seleccionó ningún archivo.']);
            }
            $this->Flash->error('No se seleccionó ningún archivo.');

            return $this->redirect(['controller' => 'EmployeeNovelties', 'action' => 'edit', $noveltyId]);
        }

        $result = $this->documentService->uploadForNovelty($novelty->id, $novelty->pipeline_status, $file, $user->id);

        if ($this->_isJsonRequest()) {
            if (is_string($result)) {
                return $this->_jsonResponse(['success' => false, 'error' => $result]);
            }

            $canDelete = $this->documentService->canDeleteDocument($result, $novelty->pipeline_status);
            [$badgeColors, $statusLabels] = $this->_noveltyDocumentLabels();

            return $this->_jsonResponse([
                'success' => true,
                'document' => [
                    'id' => $result->id,
                    'file_name' => $result->file_name,
                    'document_type' => $result->document_type ?? null,
                    'mime_type' => $result->mime_type,
                    'file_path' => $result->file_path,
                    'file_size' => $result->file_size,
                    'pipeline_status' => $result->pipeline_status,
                    'created' => $result->created->format('d/m/Y H:i'),
                    'can_delete' => $canDelete,
                    'badge_class' => $badgeColors[$result->pipeline_status] ?? 'bg-secondary',
                    'badge_label' => $statusLabels[$result->pipeline_status] ?? $result->pipeline_status,
                    'delete_url' => $canDelete
                        ? Router::url(['controller' => 'NoveltyDocuments', 'action' => 'delete', $novelty->id, $result->id])
                        : null,
                ],
            ]);
        }

        if (is_string($result)) {
            $this->Flash->error($result);
        } else {
            $this->Flash->success('Documento subido exitosamente.');
        }

        return $this->redirect(['controller' => 'EmployeeNovelties', 'action' => 'edit', $noveltyId]);
    }

    public function delete(?string $noveltyId = null, ?string $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $noveltiesTable = $this->fetchTable('EmployeeNovelties');
        $novelty = $noveltiesTable->get($noveltyId);

        $documentsTable = $this->fetchTable('NoveltyDocuments');
        $document = $documentsTable->get($documentId);

        if (!$this->documentService->canDeleteDocument($document, $novelty->pipeline_status)) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'Solo puede eliminar documentos de la etapa actual.']);
            }
            $this->Flash->error('Solo puede eliminar documentos de la etapa actual.');

            return $this->redirect(['controller' => 'EmployeeNovelties', 'action' => 'edit', $noveltyId]);
        }

        $deleted = $this->documentService->deleteDocument((int)$documentId);

        if ($this->_isJsonRequest()) {
            return $this->_jsonResponse(
                $deleted
                    ? ['success' => true]
                    : ['success' => false, 'error' => 'No se pudo eliminar el documento.']
            );
        }

        if ($deleted) {
            $this->Flash->success('Documento eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el documento.');
        }

        return $this->redirect(['controller' => 'EmployeeNovelties', 'action' => 'edit', $noveltyId]);
    }

    /**
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    private function _noveltyDocumentLabels(): array
    {
        // Copiar exactamente los arrays usados por templates/EmployeeNovelties/edit.php.
        // Buscar con: grep -n "badgeColors\|statusLabels" src/Controller/EmployeeNoveltiesController.php templates/EmployeeNovelties/edit.php
        // y replicar aquí. Si difieren entre archivos, mantener los del template del edit.
        return [
            // $badgeColors
            [],
            // $statusLabels
            [],
        ];
    }
}
```

- [ ] **Step 2: Rellenar `_noveltyDocumentLabels()` con los valores reales**

Ejecutar:
```bash
grep -n "badgeColors\|statusLabels" src/Controller/EmployeeNoveltiesController.php templates/EmployeeNovelties/edit.php
```

Localizar dónde se inicializan ambos arrays (probablemente en `EmployeeNoveltiesController::edit()`) y copiar los pares clave→valor exactos al método `_noveltyDocumentLabels()`. Reemplazar los `[]` vacíos con los arrays completos.

- [ ] **Step 3: Commit**

```bash
git add src/Controller/NoveltyDocumentsController.php
git commit -m "feat(novelty-documents): rama JSON y field name 'file' para AJAX uploader"
```

---

## Task 9: EmployeeNovelties — template (migrar a AJAX)

**Files:**
- Modify: `templates/EmployeeNovelties/edit.php` (líneas ~474-548 lista, ~614-639 modal)

- [ ] **Step 1: Reemplazar render inline por el partial**

Localizar el card "Soportes" (línea ~474) y reemplazar el bloque desde el `<?php if (empty($documentsByStatus)): ?>` hasta el `<?php endif; ?>` que cierra la sección por:

```php
    <div id="docs-empty-state" style="padding:2rem 1rem;text-align:center;color:#c8c8c8;<?= !empty($documentsByStatus) ? 'display:none;' : '' ?>">
        <i class="bi bi-file-earmark-x d-block mb-2" style="font-size:1.5rem;"></i>
        <span style="font-size:.8rem;">Sin soportes adjuntos</span>
    </div>
    <div id="docs-list" style="max-height:420px;overflow-y:auto;">
        <?php
        $multipleStatuses = count($documentsByStatus) > 1;
        foreach ($documentsByStatus as $status => $docs):
        ?>
        <?php if ($multipleStatuses): ?>
        <div style="padding:.3rem .875rem;background:#f8f9fa;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:.4rem;">
            <span class="badge <?= $badgeColors[$status] ?? 'bg-secondary' ?>" style="font-size:.6rem;"><?= $statusLabels[$status] ?? $status ?></span>
            <span style="font-size:.67rem;color:#aaa;"><?= count($docs) ?> archivo<?= count($docs) !== 1 ? 's' : '' ?></span>
        </div>
        <?php endif; ?>
        <?php foreach ($docs as $doc): ?>
            <?= $this->element('document_row', [
                'doc'          => $doc,
                'canDelete'    => $showUploadSection && $doc->pipeline_status === $currentStatus,
                'deleteUrl'    => $this->Url->build(['controller' => 'NoveltyDocuments', 'action' => 'delete', $novelty->id, $doc->id]),
                'showBadge'    => !$multipleStatuses,
                'badgeColors'  => $badgeColors,
                'statusLabels' => $statusLabels,
            ]) ?>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
```

- [ ] **Step 2: Convertir el modal a form AJAX**

Localizar `#uploadDocModal` (línea ~615) y reemplazar el bloque por:

```html
<?php if ($showUploadSection): ?>
<div class="modal fade" id="uploadDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="upload-doc-form"
                  data-url="<?= $this->Url->build(['controller' => 'NoveltyDocuments', 'action' => 'upload', $novelty->id]) ?>"
                  enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Subir Soporte</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Archivo</label>
                        <input type="file" name="file" class="form-control" required
                               accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx">
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
```

**Importante:** la URL del form ahora apunta a `NoveltyDocuments::upload` (no `EmployeeNovelties::uploadDocument`) — esto coincide con la ruta `/employee-novelties/upload-document/{id}` definida en `config/routes.php:195`. La URL final que renderiza Cake debe ser idéntica a la actual; verificar con DevTools.

- [ ] **Step 3: Insertar `<template>` + helper + init**

Justo después del modal agregar:
```php
<?= $this->element('document_row_template', ['showBadge' => true]) ?>
<?= $this->Html->script('sgi-document-uploader', ['block' => true]) ?>
```

Dentro del bloque `<?php $this->append('script') ?>` (si existe; si no, agregarlo al final del template), antes del `})();`:
```javascript
    SgiDocumentUploader.init({
        formSelector:        '#upload-doc-form',
        listSelector:        '#docs-list',
        emptySelector:       '#docs-empty-state',
        counterSelector:     '.card.card-primary .card-header .sgi-folder-count',
        rowTemplateSelector: '#doc-row-template',
        modalSelector:       '#uploadDocModal',
        csrfToken:           <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
    });
```

Si el template no tiene un IIFE `(function(){ ... })();` ya, envolverlo:
```html
<?php $this->append('script') ?>
<script>
(function(){
    SgiDocumentUploader.init({ ... });
})();
</script>
<?php $this->end() ?>
```

- [ ] **Step 4: Validación manual**

Pedir al usuario que en `EmployeeNovelties/edit`:
1. Suba un soporte → modal cierra, fila aparece sin recargar.
2. Elimine un soporte de la etapa actual → desaparece sin recargar.
3. Sin botón eliminar para soportes de etapas anteriores.
4. Recargar → la fila persiste idéntica.

- [ ] **Step 5: Commit**

```bash
git add templates/EmployeeNovelties/edit.php
git commit -m "feat(novelties): subida AJAX de soportes con helper unificado"
```

---

## Task 10: NoveltyLiquidationDocs — controller (rama JSON + field name)

**Files:**
- Modify: `src/Controller/NoveltyLiquidationDocsController.php` (líneas 321-343, 423-444)

- [ ] **Step 1: Modificar `uploadDocument` (línea 321)**

Reemplazar el método `uploadDocument` completo por:

```php
    public function uploadDocument(?string $id = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $doc = $this->NoveltyLiquidationDocs->get($id);
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $file = $this->request->getUploadedFile('file');

        if (!$file) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'No se seleccionó ningún archivo.']);
            }
            $this->Flash->error('No se seleccionó ningún archivo.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $result = $this->documentService->uploadForGroup($doc->id, $doc->pipeline_status, $file, $user->id);

        if ($this->_isJsonRequest()) {
            if (is_string($result)) {
                return $this->_jsonResponse(['success' => false, 'error' => $result]);
            }

            $canDelete = $this->documentService->canDeleteDocument($result, $doc->pipeline_status);
            [$badgeColors, $statusLabels] = $this->_liquidationDocumentLabels();

            return $this->_jsonResponse([
                'success' => true,
                'document' => [
                    'id' => $result->id,
                    'file_name' => $result->file_name,
                    'document_type' => $result->document_type ?? null,
                    'mime_type' => $result->mime_type,
                    'file_path' => $result->file_path,
                    'file_size' => $result->file_size,
                    'pipeline_status' => $result->pipeline_status,
                    'created' => $result->created->format('d/m/Y H:i'),
                    'can_delete' => $canDelete,
                    'badge_class' => $badgeColors[$result->pipeline_status] ?? 'bg-secondary',
                    'badge_label' => $statusLabels[$result->pipeline_status] ?? $result->pipeline_status,
                    'delete_url' => $canDelete
                        ? \Cake\Routing\Router::url(['action' => 'deleteDocument', $doc->id, $result->id])
                        : null,
                ],
            ]);
        }

        if (is_string($result)) {
            $this->Flash->error($result);
        } else {
            $this->Flash->success('Documento subido exitosamente.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }
```

- [ ] **Step 2: Modificar `deleteDocument` (línea 423)**

Reemplazar por:

```php
    public function deleteDocument(?string $id = null, ?string $documentId = null): ?Response
    {
        $this->request->allowMethod(['post', 'delete']);
        $doc = $this->NoveltyLiquidationDocs->get($id);

        $documentsTable = $this->fetchTable('NoveltyDocuments');
        $document = $documentsTable->get($documentId);

        if (!$this->documentService->canDeleteDocument($document, $doc->pipeline_status)) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'Solo puede eliminar documentos de la etapa actual.']);
            }
            $this->Flash->error('Solo puede eliminar documentos de la etapa actual.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $deleted = $this->documentService->deleteDocument((int)$documentId);

        if ($this->_isJsonRequest()) {
            return $this->_jsonResponse(
                $deleted
                    ? ['success' => true]
                    : ['success' => false, 'error' => 'No se pudo eliminar el documento.']
            );
        }

        if ($deleted) {
            $this->Flash->success('Documento eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el documento.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }
```

- [ ] **Step 3: Agregar helper privado de labels**

Antes del cierre de la clase, agregar:

```php
    /**
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    private function _liquidationDocumentLabels(): array
    {
        // Copiar valores exactos de templates/NoveltyLiquidationDocs/edit.php
        // Buscar: grep -n "badgeColors\|statusLabels" src/Controller/NoveltyLiquidationDocsController.php templates/NoveltyLiquidationDocs/edit.php
        return [
            [],
            [],
        ];
    }
```

Ejecutar el `grep` y rellenar los arrays con los valores que ya usa el template.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/NoveltyLiquidationDocsController.php
git commit -m "feat(liquidation-docs): rama JSON y field 'file' para AJAX uploader"
```

---

## Task 11: NoveltyLiquidationDocs — template (migrar SOLO la lista de soportes)

**Files:**
- Modify: `templates/NoveltyLiquidationDocs/edit.php` (lista de soportes alrededor línea 395-548, modal de soportes alrededor línea 615-630)

**Importante:** los formularios `liquidation_file` (líneas 437 y 467) y los modales asociados a `uploadLiquidationDocument`/`updateLiquidationDocument` **no se tocan**. Solo se migra el modal de la lista de soportes (línea ~615) y el listado (línea ~395-548).

- [ ] **Step 1: Reemplazar render inline de la lista por el partial**

Misma estructura que Task 9 paso 1 — adaptar variables al contexto (`$doc->id` aquí es la liquidación, los archivos están en `$documentsByStatus` por status). Cambiar la URL de delete a la acción local:

```php
            <?= $this->element('document_row', [
                'doc'          => $docFile,
                'canDelete'    => $showUploadSection && $docFile->pipeline_status === $currentStatus,
                'deleteUrl'    => $this->Url->build(['action' => 'deleteDocument', $doc->id, $docFile->id]),
                'showBadge'    => !$multipleStatuses,
                'badgeColors'  => $badgeColors,
                'statusLabels' => $statusLabels,
            ]) ?>
```

(Nombre del iterador en este template puede ser `$docFile` o similar — verificar y respetar.)

Envolver la lista en:
```html
<div id="docs-empty-state" style="...; <?= !empty($documentsByStatus) ? 'display:none;' : '' ?>">...</div>
<div id="docs-list" style="max-height:420px;overflow-y:auto;">
    ...iteración con element document_row...
</div>
```

- [ ] **Step 2: Convertir el modal de la lista (línea ~615) a form AJAX**

Localizar el `Form->create(null, ['url' => ['action' => 'uploadDocument', $doc->id], 'type' => 'file'])` (línea 615) y reemplazar por:

```html
<form id="upload-doc-form"
      data-url="<?= $this->Url->build(['action' => 'uploadDocument', $doc->id]) ?>"
      enctype="multipart/form-data">
```

Cerrar con `</form>` en lugar de `<?= $this->Form->end() ?>`. Cambiar `<input type="file" name="document" ...>` (línea 623) → `<input type="file" name="file" ...>`.

**Confirmar que NO se tocan** los formularios de `liquidation_file` (líneas 437 y 467) ni sus modales/handlers.

- [ ] **Step 3: Insertar `<template>` + helper + init**

Después del modal de la lista, agregar:
```php
<?= $this->element('document_row_template', ['showBadge' => true]) ?>
<?= $this->Html->script('sgi-document-uploader', ['block' => true]) ?>
```

En el bloque `<?php $this->append('script') ?>`:
```javascript
    SgiDocumentUploader.init({
        formSelector:        '#upload-doc-form',
        listSelector:        '#docs-list',
        emptySelector:       '#docs-empty-state',
        counterSelector:     '.card.card-primary .card-header .sgi-folder-count',
        rowTemplateSelector: '#doc-row-template',
        modalSelector:       /* el id del modal de la lista, p.ej. */ '#uploadDocModal',
        csrfToken:           <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
    });
```

Verificar el id real del modal de la lista (puede ser distinto del modal de `liquidation_file`). Inspeccionar con `grep "modal fade" templates/NoveltyLiquidationDocs/edit.php`.

- [ ] **Step 4: Validación manual**

Pedir al usuario que en `NoveltyLiquidationDocs/edit`:
1. Suba un soporte (lista) → modal cierra, fila aparece sin recargar.
2. Elimine soporte etapa actual → desaparece sin recargar.
3. **Verificar que el upload de `liquidation_file` (documento de liquidación dedicado) sigue funcionando con submit clásico** (con recarga, comportamiento previo intacto).
4. Recargar → la fila persiste idéntica.

- [ ] **Step 5: Commit**

```bash
git add templates/NoveltyLiquidationDocs/edit.php
git commit -m "feat(liquidation-docs): subida AJAX de soportes con helper unificado"
```

---

## Task 12: Validación cross-cutting + revisión de calidad

**Files:** ninguno

- [ ] **Step 1: Smoke test completo (manual, ejecutado por el usuario)**

Recorrido por los 4 módulos en `php bin/cake server`:

1. **Invoices/edit** → subir + eliminar + recargar.
2. **PettyCashRecords/edit** (registro no pagado) → subir + eliminar + recargar.
3. **EmployeeNovelties/edit** → subir + eliminar (etapa actual) + recargar.
4. **NoveltyLiquidationDocs/edit** → subir + eliminar (etapa actual) + recargar; **además** verificar que `liquidation_file` (formulario aparte) sigue funcionando con submit clásico.

Cross-cutting:

5. Inspeccionar 8 respuestas en DevTools (4 uploads + 4 deletes) → todas siguen el mismo schema JSON.
6. Sin errores en consola JS en ninguno.
7. CSRF: borrar la cookie `csrfToken` y subir → 403, alert "Error de conexión", modal abierto, app sigue funcional al refrescar.
8. Subir archivo > 20 MB → alert local, modal abierto, sin request al servidor.

- [ ] **Step 2: `composer cs-check`**

```bash
composer cs-check
```
Si hay violaciones, ejecutar `composer cs-fix` y commitear:
```bash
git add -A
git commit -m "style: cs-fix tras refactor del uploader unificado"
```

- [ ] **Step 3: Revisión de calidad final (al cierre del plan, no por tarea)**

Esta es la única revisión global del plan según política del proyecto. Lanzar:

```
/acc:code-review
```

Aplicar correcciones que reporte sobre los archivos tocados en este plan. Commitear arreglos puntuales con mensajes propios.

---

## Notas de cierre

- **Asimetrías toleradas:** PettyCashRecords y EmployeeNovelties pueden o no usar `pipeline_status` por documento; el helper lo trata como opcional. PettyCash no muestra badge.
- **Fuera de alcance:** PaymentSchedulings (ya AJAX, distinto patrón), `liquidation_file`/`uploadLiquidationDocument`/`updateLiquidationDocument` en NoveltyLiquidationDocs, uploads de Employees/view (no es módulo de flujo).
- **Riesgo principal:** divergencia entre `templates/element/document_row.php` y `templates/element/document_row_template.php`. Mitigación: ambos comparten `data-slot` y viven en el mismo directorio; cualquier cambio futuro debe tocar los dos.

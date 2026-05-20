# Observaciones como drawer flotante en Invoices/edit — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mover el chat de Observaciones de `Invoices/edit` de una tarjeta inline a un panel lateral (Bootstrap Offcanvas restilizado como drawer) abierto desde una pestaña flotante en el borde derecho.

**Architecture:** El drawer es un Bootstrap Offcanvas (`offcanvas-end`) restilizado por CSS para coincidir con el spec `.drawer` del sistema de diseño. El contenido del chat (lista de burbujas + barra de input) se reubica a un nuevo element con sus IDs estándar intactos, de modo que `SgiObservationChat` sigue funcionando sin cambios. Soportes pasa a ancho completo.

**Tech Stack:** PHP 8.4 / CakePHP 5 (templates + elements), Bootstrap 5 (offcanvas), CSS del sistema de diseño SGI.

**Spec:** `docs/superpowers/specs/2026-05-20-observaciones-drawer-design.md`

**Nota sobre testing:** este proyecto NO usa tests automatizados (ver `CLAUDE.md` → "Testing Policy"). Cada tarea cierra con validación manual y commit; no hay pasos de test unitario.

---

## Estructura de archivos

- **Modificar** `webroot/css/components.css` — añadir estilos del disparador flotante y del restyle del offcanvas.
- **Crear** `templates/element/invoice_edit/observations_drawer.php` — disparador + offcanvas con el chat reubicado + listener de scroll.
- **Modificar** `templates/Invoices/edit.php` — quitar el grid Soportes/Observaciones (Soportes a ancho completo), incluir el nuevo element.

---

### Task 1: CSS del disparador flotante y del drawer

**Files:**
- Modify: `webroot/css/components.css` (append al final del archivo)

- [ ] **Step 1: Añadir el bloque CSS**

Append el siguiente bloque al final de `webroot/css/components.css`:

```css


/* ─── Observaciones · drawer flotante (Invoices/edit) ─────────────────
   El chat de observaciones vive en un Bootstrap Offcanvas restilizado como
   el `.drawer` del sistema de diseño (overlays.md), abierto desde una
   pestaña flotante fija al borde derecho del viewport. */

/* Disparador: pestaña fija al borde derecho, centrada verticalmente. */
.sgi-obs-trigger {
    position: fixed;
    right: 0;
    top: 50%;
    transform: translateY(-50%);
    z-index: 1030;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 14px;
    border: 0;
    background: var(--primary-color);
    color: #fff;
    border-radius: var(--radius-sm) 0 0 var(--radius-sm);
    cursor: pointer;
    transition: padding-right var(--t-fast) ease;
}
.sgi-obs-trigger:hover { padding-right: 18px; }
.sgi-obs-trigger > .bi { font-size: 16px; }
.sgi-obs-trigger-badge {
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #fff;
    color: var(--primary-color);
    font-size: 11px;
    font-weight: 700;
    border-radius: 9px;
}

/* Drawer: Bootstrap Offcanvas restilizado como `.drawer` del sistema. */
.sgi-obs-drawer.offcanvas {
    --bs-offcanvas-width: 440px;
    border-left: 3px solid var(--primary-color);
    box-shadow: none;
}
.sgi-obs-drawer .offcanvas-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--rule);
}
.sgi-obs-drawer .offcanvas-title {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 16px;
    font-weight: 700;
    color: var(--text-strong);
}
.sgi-obs-drawer .offcanvas-body {
    padding: 0;
    display: flex;
    flex-direction: column;
}
/* La lista de burbujas ocupa el alto disponible del panel (anula el
   max-height:340px que usa la variante inline en tarjeta). */
.sgi-obs-drawer .sgi-obs-list {
    flex: 1;
    min-height: 0;
    max-height: none;
}
```

- [ ] **Step 2: Validación manual**

Las clases nuevas aún no se usan, así que no hay cambio visual. Verificar solo que el archivo no quedó roto:

Run: `php -r "echo 'css ok';"` no aplica a CSS — en su lugar abrir `webroot/css/components.css` y confirmar que el bloque quedó al final, bien cerrado (cada `{` tiene su `}`).

- [ ] **Step 3: Commit**

```bash
git add webroot/css/components.css
git commit -m "feat(ui): estilos del disparador flotante y drawer de observaciones

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Crear el element `observations_drawer.php`

**Files:**
- Create: `templates/element/invoice_edit/observations_drawer.php`

- [ ] **Step 1: Crear el element**

Crear `templates/element/invoice_edit/observations_drawer.php` con este contenido exacto:

```php
<?php
/**
 * Drawer flotante de Observaciones para Invoices/edit.
 *
 * Disparador fijo al borde derecho del viewport + Bootstrap Offcanvas con el
 * chat de observaciones. Conserva los IDs estándar (#obs-form, #obs-chat-scroll,
 * #obs-empty-state, #obs-count) que consume observation_chat_init.php, de modo
 * que SgiObservationChat funciona sin cambios.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 * @var \App\Model\Entity\User|null $currentUser
 */
$obsCount = count($invoice->invoice_observations ?? []);
?>
<button type="button" class="sgi-obs-trigger"
        data-bs-toggle="offcanvas" data-bs-target="#obsDrawer"
        aria-label="Abrir observaciones">
    <i class="bi bi-chat-left-text" aria-hidden="true"></i>
    <span id="obs-count" class="sgi-obs-trigger-badge"
          <?= $obsCount === 0 ? 'style="display:none;"' : '' ?>><?= $obsCount ?></span>
</button>

<div class="offcanvas offcanvas-end sgi-obs-drawer" id="obsDrawer" tabindex="-1"
     aria-labelledby="obsDrawerTitle">
    <div class="offcanvas-header">
        <h2 class="offcanvas-title" id="obsDrawerTitle">
            <i class="bi bi-chat-left-text" aria-hidden="true"></i>Observaciones
        </h2>
        <button type="button" class="btn-icon" data-bs-dismiss="offcanvas" aria-label="Cerrar">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>
    <div class="offcanvas-body">
        <div id="obs-chat-scroll" class="sgi-obs-list">
            <?php foreach ($invoice->invoice_observations ?? [] as $obs): ?>
                <?= $this->element('observation_bubble', [
                    'observation' => $obs,
                    'isMine' => $currentUser && $obs->user_id === $currentUser->id,
                ]) ?>
            <?php endforeach; ?>
        </div>

        <div id="obs-empty-state" class="empty-state" <?= $obsCount > 0 ? 'hidden' : '' ?>>
            <div class="es-icon es-icon-neutral">
                <i class="bi bi-chat-square-dots" aria-hidden="true"></i>
            </div>
            <div class="es-msg">Sin observaciones aún</div>
        </div>

        <div class="sgi-obs-input-bar">
            <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $invoice->id], 'id' => 'obs-form']) ?>
            <div class="sgi-obs-compose">
                <textarea id="obs-message" name="message" class="auto-resize" rows="1"
                          placeholder="Escriba una observación..."></textarea>
                <button type="submit" class="sgi-obs-compose-send" title="Enviar">
                    <i class="bi bi-send" aria-hidden="true"></i>
                </button>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>

<?php $this->append('script') ?>
<script>
(function () {
    // El panel está oculto al cargar; al mostrarse, posicionar el chat en el
    // último mensaje (el scroll-al-fondo inicial de SgiObservationChat puede
    // no aplicar sobre un contenedor aún sin layout visible).
    var drawer = document.getElementById('obsDrawer');
    var scroll = document.getElementById('obs-chat-scroll');
    if (!drawer || !scroll) return;
    drawer.addEventListener('shown.bs.offcanvas', function () {
        scroll.scrollTop = scroll.scrollHeight;
    });
})();
</script>
<?php $this->end() ?>
```

- [ ] **Step 2: Verificar sintaxis PHP**

Run: `php -l templates/element/invoice_edit/observations_drawer.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Validación manual**

El element todavía no se incluye en ninguna vista, así que no hay cambio visible. Confirmar solo que el archivo existe y `php -l` pasó.

- [ ] **Step 4: Commit**

```bash
git add templates/element/invoice_edit/observations_drawer.php
git commit -m "feat(view): element observations_drawer para Invoices/edit

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Cablear el drawer en `Invoices/edit` y dejar Soportes a ancho completo

**Files:**
- Modify: `templates/Invoices/edit.php`

- [ ] **Step 1: Reemplazar el grid Soportes/Observaciones por Soportes a ancho completo**

En `templates/Invoices/edit.php`, reemplazar el bloque completo que va desde el comentario `── Soportes + Observaciones (grid 2 columnas) ──` hasta el `</div>` que cierra la `.row` (el bloque que abre con `<div class="row g-3">` y contiene las dos `col-md-6`).

Buscar y reemplazar este bloque exacto:

```php
        <?php /* ── Soportes + Observaciones (grid 2 columnas) ──── */ ?>
        <div class="row g-3">

            <?php /* ── Soportes ───────────────────────────────── */ ?>
            <div class="col-md-6">
                <div class="sgi-card h-100 d-flex flex-column">
                    <div class="d-flex align-items-center justify-content-between" style="margin-bottom:12px;">
                        <span class="sgi-label d-inline-flex align-items-center gap-2">
                            <i class="bi bi-paperclip" aria-hidden="true"></i>
                            Soportes
                            <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
                        </span>
                        <?php if ($showUploadSection): ?>
                        <button type="button" class="btn btn-default btn-sm"
                                data-bs-toggle="modal" data-bs-target="#uploadInvoiceDocModal">
                            <i class="bi bi-upload" aria-hidden="true"></i>Subir
                        </button>
                        <?php endif; ?>
                    </div>

                    <?php if ($showUploadSection): ?>
                    <?php /* Empty state = dropzone del sistema de diseño. Toda la
                              zona abre el modal de subida (el JS solo alterna su
                              display, no depende de la clase). */ ?>
                    <div id="docs-empty-state" class="dropzone"
                         data-bs-toggle="modal" data-bs-target="#uploadInvoiceDocModal"
                         style="cursor:pointer;<?= !empty($documentsByStatus) ? 'display:none;' : '' ?>">
                        <i class="bi bi-paperclip" aria-hidden="true"></i>
                        <div>Arrastra archivos o <a class="dz-link">examina</a></div>
                        <div class="dz-hint">PDF, JPG, PNG · máximo 10 MB por archivo</div>
                    </div>
                    <?php else: ?>
                    <div id="docs-empty-state" class="empty-state"
                         <?= !empty($documentsByStatus) ? 'style="display:none;"' : '' ?>>
                        <div class="es-icon es-icon-neutral">
                            <i class="bi bi-paperclip" aria-hidden="true"></i>
                        </div>
                        <div class="es-title">Sin soportes adjuntos</div>
                    </div>
                    <?php endif; ?>

                    <div id="docs-list" style="max-height:420px;overflow-y:auto;">
                        <?php foreach ($documentsByStatus as $status => $docs): ?>
                            <?php if ($multipleDocStatuses):
                                $docPillKind = $statusPills[$status] ?? 'pill-muted';
                            ?>
                            <div class="d-flex align-items-center gap-2"
                                 style="padding:.3rem .5rem;background:var(--bg-subtle);margin-top:.5rem;">
                                <span class="pill <?= $docPillKind ?>"><?= h($statusLabels[$status] ?? $status) ?></span>
                                <span style="font-size:var(--fs-label);color:var(--text-faint);">
                                    <?= count($docs) ?> archivo<?= count($docs) !== 1 ? 's' : '' ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <?php foreach ($docs as $doc): ?>
                                <?= $this->element('document_row', [
                                    'doc'          => $doc,
                                    'canDelete'    => $viewModel->canDeleteDocuments && $doc->pipeline_status === $currentStatus,
                                    'deleteUrl'    => $this->Url->build(['action' => 'deleteDocument', $invoice->id, $doc->id]),
                                    'showBadge'    => !$multipleDocStatuses,
                                    'badgeColors'  => $badgeColors,
                                    'statusLabels' => $statusLabels,
                                ]) ?>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php /* ── Observaciones ──────────────────────────── */ ?>
            <?php $obsCount = count($invoice->invoice_observations ?? []); ?>
            <div class="col-md-6">
                <div class="sgi-card sgi-obs-card h-100 d-flex flex-column">
                    <div class="d-flex align-items-center justify-content-between" style="margin-bottom:12px;">
                        <span class="sgi-label d-inline-flex align-items-center gap-2">
                            <i class="bi bi-chat-left-text" aria-hidden="true"></i>
                            Observaciones
                            <span id="obs-count" class="sgi-folder-count"
                                  <?= $obsCount === 0 ? 'style="display:none;"' : '' ?>><?= $obsCount ?></span>
                        </span>
                    </div>

                    <div id="obs-chat-scroll" class="sgi-obs-list">
                        <?php foreach ($invoice->invoice_observations ?? [] as $obs): ?>
                            <?= $this->element('observation_bubble', [
                                'observation' => $obs,
                                'isMine' => $currentUser && $obs->user_id === $currentUser->id,
                            ]) ?>
                        <?php endforeach; ?>
                    </div>

                    <div id="obs-empty-state" class="empty-state" <?= $obsCount > 0 ? 'hidden' : '' ?>>
                        <div class="es-icon es-icon-neutral">
                            <i class="bi bi-chat-square-dots" aria-hidden="true"></i>
                        </div>
                        <div class="es-msg">Sin observaciones aún</div>
                    </div>

                    <div class="sgi-obs-input-bar">
                        <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $invoice->id], 'id' => 'obs-form']) ?>
                        <div class="sgi-obs-compose">
                            <textarea id="obs-message" name="message" class="auto-resize" rows="1"
                                      placeholder="Escriba una observación..."></textarea>
                            <button type="submit" class="sgi-obs-compose-send" title="Enviar">
                                <i class="bi bi-send" aria-hidden="true"></i>
                            </button>
                        </div>
                        <?= $this->Form->end() ?>
                    </div>
                </div>
            </div>

        </div>
```

por este bloque (Soportes a ancho completo, sin grid, sin la columna de Observaciones):

```php
        <?php /* ── Soportes (ancho completo; Observaciones vive en el drawer) ── */ ?>
        <div class="sgi-card d-flex flex-column">
            <div class="d-flex align-items-center justify-content-between" style="margin-bottom:12px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-paperclip" aria-hidden="true"></i>
                    Soportes
                    <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
                </span>
                <?php if ($showUploadSection): ?>
                <button type="button" class="btn btn-default btn-sm"
                        data-bs-toggle="modal" data-bs-target="#uploadInvoiceDocModal">
                    <i class="bi bi-upload" aria-hidden="true"></i>Subir
                </button>
                <?php endif; ?>
            </div>

            <?php if ($showUploadSection): ?>
            <?php /* Empty state = dropzone del sistema de diseño. Toda la
                      zona abre el modal de subida (el JS solo alterna su
                      display, no depende de la clase). */ ?>
            <div id="docs-empty-state" class="dropzone"
                 data-bs-toggle="modal" data-bs-target="#uploadInvoiceDocModal"
                 style="cursor:pointer;<?= !empty($documentsByStatus) ? 'display:none;' : '' ?>">
                <i class="bi bi-paperclip" aria-hidden="true"></i>
                <div>Arrastra archivos o <a class="dz-link">examina</a></div>
                <div class="dz-hint">PDF, JPG, PNG · máximo 10 MB por archivo</div>
            </div>
            <?php else: ?>
            <div id="docs-empty-state" class="empty-state"
                 <?= !empty($documentsByStatus) ? 'style="display:none;"' : '' ?>>
                <div class="es-icon es-icon-neutral">
                    <i class="bi bi-paperclip" aria-hidden="true"></i>
                </div>
                <div class="es-title">Sin soportes adjuntos</div>
            </div>
            <?php endif; ?>

            <div id="docs-list" style="max-height:420px;overflow-y:auto;">
                <?php foreach ($documentsByStatus as $status => $docs): ?>
                    <?php if ($multipleDocStatuses):
                        $docPillKind = $statusPills[$status] ?? 'pill-muted';
                    ?>
                    <div class="d-flex align-items-center gap-2"
                         style="padding:.3rem .5rem;background:var(--bg-subtle);margin-top:.5rem;">
                        <span class="pill <?= $docPillKind ?>"><?= h($statusLabels[$status] ?? $status) ?></span>
                        <span style="font-size:var(--fs-label);color:var(--text-faint);">
                            <?= count($docs) ?> archivo<?= count($docs) !== 1 ? 's' : '' ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <?php foreach ($docs as $doc): ?>
                        <?= $this->element('document_row', [
                            'doc'          => $doc,
                            'canDelete'    => $viewModel->canDeleteDocuments && $doc->pipeline_status === $currentStatus,
                            'deleteUrl'    => $this->Url->build(['action' => 'deleteDocument', $invoice->id, $doc->id]),
                            'showBadge'    => !$multipleDocStatuses,
                            'badgeColors'  => $badgeColors,
                            'statusLabels' => $statusLabels,
                        ]) ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
```

- [ ] **Step 2: Incluir el element del drawer junto a los modales**

En el mismo archivo, ubicar el bloque del modal de subida cerca del final:

```php
<?php if ($showUploadSection): ?>
<?= $this->element('invoice_edit/upload_doc_modal', ['invoice' => $invoice]) ?>
<?php endif; ?>

<?= $this->element('document_row_template', ['showBadge' => true]) ?>
```

y reemplazarlo por (se inserta la inclusión del drawer entre el `endif` del modal y `document_row_template`):

```php
<?php if ($showUploadSection): ?>
<?= $this->element('invoice_edit/upload_doc_modal', ['invoice' => $invoice]) ?>
<?php endif; ?>

<?= $this->element('invoice_edit/observations_drawer', [
    'invoice' => $invoice,
    'currentUser' => $currentUser,
]) ?>

<?= $this->element('document_row_template', ['showBadge' => true]) ?>
```

- [ ] **Step 3: Verificar sintaxis PHP**

Run: `php -l templates/Invoices/edit.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Validación manual**

Levantar el servidor (`php bin/cake server`) y abrir la edición de una factura:

1. **Disparador:** pestaña flotante con ícono de chat pegada al borde derecho, centrada verticalmente; si la factura tiene observaciones, muestra el conteo en un badge blanco.
2. **Apertura:** al hacer clic, el panel entra deslizando desde la derecha (440px, alto completo, franja verde a la izquierda, sin sombra) con backdrop; la factura queda atenuada detrás.
3. **Cierre:** cierra con el botón ×, con Esc y con click en el backdrop.
4. **Chat funcional:** escribir y enviar una observación la agrega a la lista sin recargar; el badge del disparador se actualiza (y aparece si estaba en 0); el empty state "Sin observaciones aún" desaparece al haber el primer mensaje.
5. **Scroll:** al abrir el panel con varias observaciones, la lista aparece desplazada al último mensaje.
6. **Layout:** la tarjeta de Soportes ocupa el ancho completo de la columna de contenido; ya no hay tarjeta de Observaciones inline.
7. **Sin form anidado:** en las DevTools, `#obs-form` ya no está dentro de `#invoiceEditForm`.
8. **Soportes intacto:** subir y eliminar un soporte sigue funcionando (modal, dropzone, contador).

- [ ] **Step 5: Commit**

```bash
git add templates/Invoices/edit.php
git commit -m "feat(view): observaciones en drawer flotante; soportes a ancho completo

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Validación final

Tras las 3 tareas, con `php bin/cake server` levantado:

- Recorrer los 8 puntos de validación de la Task 3, Step 4.
- Abrir una factura **sin** observaciones: el disparador no muestra badge; al abrir el panel se ve el empty state "Sin observaciones aún".
- Abrir una factura **con** observaciones: el badge muestra el conteo; el panel lista las burbujas (propias a la derecha, ajenas a la izquierda).
- `composer cs-check` no introduce errores nuevos respecto a los archivos tocados (`edit.php` ya tiene violaciones previas no relacionadas; no agregar nuevas en el element ni en el CSS).

## Fuera de alcance

- Los otros 5 templates que usan el chat de observaciones conservan la tarjeta inline.
- El comportamiento del empty state dentro del drawer se mueve tal cual; no se rediseña.
- Cambios en `SgiObservationChat` o en el backend de `addObservation`.

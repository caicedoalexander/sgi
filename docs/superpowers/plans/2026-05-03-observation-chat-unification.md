# Unificación AJAX del chat de observaciones — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unificar el chat de observaciones de los 6 módulos del SGI (Invoices, PaymentSchedulings, EmployeeNovelties, Employees, PettyCashRecords, Refunds) en un único helper JS, partial PHP compartido y contrato JSON común — replicando el patrón de `SgiDocumentUploader`.

**Architecture:** Helper `SgiObservationChat` en `webroot/js/sgi-observation-chat.js`, partial server-side `templates/element/observation_bubble.php` y su gemelo `<template>` `observation_bubble_template.php`. Cada `addObservation` action obtiene rama JSON normalizada vía `_isJsonRequest()`/`_jsonResponse()`. Sin extracción a servicio (simetría con la unificación de documentos).

**Tech Stack:** CakePHP 5.3, PHP 8.2+, JS vanilla (sin frameworks), Bootstrap 5 (toasts/modal opcionales reutilizados de `SgiDocumentUploader`).

**Spec relacionado:** `docs/superpowers/specs/2026-05-03-observation-chat-unification-design.md`.

**Política del proyecto:** SGI no usa tests automatizados. Cada tarea cierra con criterios de validación manual ejecutables con `php bin/cake server`. **No** se generan tests PHPUnit, fixtures ni TDD.

**Ajustes vs. spec aprobado:**
- El partial usa el patrón **chat-bubble** real que ya existe en 5 de 6 templates (no el item plano que el spec sketcheó). Mismas clases CSS, mismo HTML, deduplicado.
- Migrar Employees al partial implica un **cambio visual menor**: pasa del layout "avatar+nombre+mensaje en línea" al estilo burbuja del resto. Decisión consistente con "tratar los 6 igual" tomada en brainstorm.

---

## Estructura de archivos

**Nuevos:**
- `webroot/js/sgi-observation-chat.js` — Helper único `SgiObservationChat.init({...})`.
- `templates/element/observation_bubble.php` — Render server-side de una observación (burbuja de chat).
- `templates/element/observation_bubble_template.php` — `<template>` con los mismos `data-slot` para clonado por el helper JS.

**Modificados:**
- 6 controllers (`InvoicesController`, `PaymentSchedulingsController`, `EmployeeNoveltiesController`, `EmployeesController`, `PettyCashRecordsController`, `RefundsController`) — rama JSON normalizada en `addObservation`.
- 6 templates (`Invoices/edit.php`, `PaymentSchedulings/edit.php`, `EmployeeNovelties/edit.php`, `Employees/view.php`, `PettyCashRecords/edit.php`, `Refunds/edit.php`) — quitan JS inline + HTML duplicado y delegan en el helper + partial.

**No tocados:** tablas, entidades, migraciones, servicios, permisos.

---

## Task 1: Crear infra compartida

**Files:**
- Create: `webroot/js/sgi-observation-chat.js`
- Create: `templates/element/observation_bubble.php`
- Create: `templates/element/observation_bubble_template.php`

**Contexto:** Aún no se toca ningún módulo. Esta tarea solo entrega los 3 archivos compartidos. Como nada los consume todavía, no hay validación visual posible — la verificación es solo sintáctica.

- [ ] **Step 1: Crear `templates/element/observation_bubble.php`**

```php
<?php
/**
 * Observation chat bubble — usado por Invoices, PaymentSchedulings,
 * EmployeeNovelties, Employees, PettyCashRecords, Refunds para el render
 * inicial server-side, y como gemelo estructural de
 * `templates/element/observation_bubble_template.php` (consumido por
 * `webroot/js/sgi-observation-chat.js`).
 *
 * IMPORTANTE: mantener markup, clases y `data-slot` sincronizados entre:
 *   - templates/element/observation_bubble.php           (server render)
 *   - templates/element/observation_bubble_template.php  (<template> JS)
 *   - webroot/js/sgi-observation-chat.js                 (slot consumer)
 *
 * Required: $observation (entity con id, message, created, user_id, user->full_name)
 * Required: $isMine (bool) — true si la observación es del usuario actual
 */
$names = explode(' ', trim($observation->user->full_name ?? ''));
$displayName = $isMine ? 'Tú' : h($observation->user->full_name ?? '');
?>
<div class="sgi-obs-bubble <?= $isMine ? 'is-mine' : 'is-other' ?>"
     data-obs-id="<?= h($observation->id) ?>">
    <div class="sgi-obs-bubble-name" data-slot="user_name"><?= $displayName ?></div>
    <div class="sgi-obs-bubble-body" data-slot="message"><?= nl2br(h($observation->message)) ?></div>
    <div class="sgi-obs-bubble-time" data-slot="created"><?= h($observation->created?->format('d/m/Y H:i')) ?></div>
</div>
```

- [ ] **Step 2: Crear `templates/element/observation_bubble_template.php`**

```php
<?php
/**
 * Emite <template id="observation-bubble-template"> para clonado por
 * webroot/js/sgi-observation-chat.js.
 *
 * Gemelo estructural de templates/element/observation_bubble.php.
 * Cualquier cambio a data-slot, clases o markup debe aplicarse en ambos.
 */
?>
<template id="observation-bubble-template">
    <div class="sgi-obs-bubble is-mine" data-obs-id="">
        <div class="sgi-obs-bubble-name" data-slot="user_name"></div>
        <div class="sgi-obs-bubble-body" data-slot="message"></div>
        <div class="sgi-obs-bubble-time" data-slot="created"></div>
    </div>
</template>
```

- [ ] **Step 3: Crear `webroot/js/sgi-observation-chat.js`**

```js
/**
 * SgiObservationChat
 * Helper unificado para chats de observaciones en módulos SGI
 * (Invoices, PaymentSchedulings, EmployeeNovelties, Employees,
 * PettyCashRecords, Refunds).
 *
 * Uso:
 *   SgiObservationChat.init({
 *     formSelector:           '#obs-form',
 *     listSelector:           '#obs-chat-scroll',
 *     emptySelector:          '#obs-empty-state',
 *     bubbleTemplateSelector: '#observation-bubble-template',
 *     csrfToken:              '<?= $this->request->getAttribute('csrfToken') ?>'
 *   });
 *
 * El contador se resuelve automáticamente desde el `.card` que contiene
 * `listSelector` (debe contener un `.sgi-folder-count`). Si no existe,
 * se crea uno al insertar la primera observación.
 *
 * Contrato JSON esperado:
 *   OK:    { success: true, observation: { id, message, user_name, created } }
 *   Fail:  { success: false, error: '...' }
 */
(function (global) {
    'use strict';

    function ensureToastContainer() {
        var c = document.getElementById('sgi-toast-container');
        if (c) return c;
        c = document.createElement('div');
        c.id = 'sgi-toast-container';
        c.className = 'toast-container position-fixed top-0 end-0 p-3';
        c.style.zIndex = '1090';
        document.body.appendChild(c);
        return c;
    }

    function showToast(message, variant) {
        if (!global.bootstrap || !global.bootstrap.Toast) {
            global.alert(message);
            return;
        }
        variant = variant || 'danger';
        var container = ensureToastContainer();
        var toastEl = document.createElement('div');
        toastEl.className = 'toast align-items-center text-bg-' + variant + ' border-0';
        toastEl.setAttribute('role', 'alert');
        toastEl.innerHTML =
            '<div class="d-flex">' +
              '<div class="toast-body"></div>' +
              '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>' +
            '</div>';
        toastEl.querySelector('.toast-body').textContent = message;
        container.appendChild(toastEl);
        var t = new global.bootstrap.Toast(toastEl, { delay: 4500 });
        toastEl.addEventListener('hidden.bs.toast', function () { toastEl.remove(); });
        t.show();
    }

    function setSlot(root, slot, value) {
        var el = root.querySelector('[data-slot="' + slot + '"]');
        if (!el) return null;
        el.textContent = value;
        return el;
    }

    // Inserta `text` en `el` preservando saltos de línea como <br>, sin permitir HTML.
    function setMessageWithBreaks(el, text) {
        if (!el) return;
        el.textContent = '';
        var parts = String(text == null ? '' : text).split(/\n/);
        for (var i = 0; i < parts.length; i++) {
            if (i > 0) el.appendChild(document.createElement('br'));
            el.appendChild(document.createTextNode(parts[i]));
        }
    }

    function buildBubble(template, obs) {
        var clone = template.content.firstElementChild.cloneNode(true);
        clone.classList.remove('is-other');
        clone.classList.add('is-mine');
        if (obs.id != null) clone.setAttribute('data-obs-id', obs.id);
        setSlot(clone, 'user_name', 'Tú');
        setMessageWithBreaks(clone.querySelector('[data-slot="message"]'), obs.message);
        setSlot(clone, 'created', obs.created || '');
        return clone;
    }

    function ensureCounter(listCard) {
        if (!listCard) return null;
        var existing = listCard.querySelector('.sgi-folder-count');
        if (existing) return existing;
        var header = listCard.querySelector('.card-header');
        if (!header) return null;
        var badge = document.createElement('span');
        badge.className = 'sgi-folder-count ms-auto';
        badge.textContent = '0';
        header.appendChild(badge);
        return badge;
    }

    function updateCounter(el, delta) {
        if (!el) return;
        var m = el.textContent.match(/(\d+)/);
        var base = m ? parseInt(m[1], 10) : 0;
        var n = base + delta;
        if (n < 0 || isNaN(n)) n = 0;
        el.textContent = String(n);
    }

    function init(opts) {
        var form       = document.querySelector(opts.formSelector);
        var list       = document.querySelector(opts.listSelector);
        var emptyState = opts.emptySelector ? document.querySelector(opts.emptySelector) : null;
        var template   = document.querySelector(opts.bubbleTemplateSelector);
        var csrfToken  = opts.csrfToken || '';

        if (!form || !list || !template) return;

        var listCard = list.closest('.card');
        var counter  = ensureCounter(listCard);
        var textarea = form.querySelector('textarea[name="message"]');
        var submitBtn = form.querySelector('button[type="submit"]');

        // Auto-scroll inicial al final
        list.scrollTop = list.scrollHeight;

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var message = (textarea && textarea.value || '').trim();
            if (!message) return;

            if (submitBtn) submitBtn.disabled = true;

            var url = form.dataset.url || form.action;
            fetch(url, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken,
                    'Accept': 'application/json'
                },
                body: new FormData(form)
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    if (emptyState) emptyState.style.display = 'none';
                    list.appendChild(buildBubble(template, data.observation));
                    list.scrollTop = list.scrollHeight;
                    updateCounter(counter, 1);
                    if (textarea) {
                        textarea.value = '';
                        textarea.style.height = '';
                        textarea.focus();
                    }
                } else {
                    showToast(data.error || 'Error al agregar observación.', 'danger');
                }
            })
            .catch(function () {
                showToast('Error de conexión. Intente nuevamente.', 'danger');
            })
            .finally(function () {
                if (submitBtn) submitBtn.disabled = false;
            });
        });
    }

    global.SgiObservationChat = { init: init };
})(window);
```

- [ ] **Step 4: Verificar sintaxis JS**

Run: `node --check webroot/js/sgi-observation-chat.js`
Expected: salida vacía (sin errores). Si Node no está instalado, abrir el archivo en un navegador con DevTools y confirmar que `SgiObservationChat` queda definido.

- [ ] **Step 5: Verificar sintaxis PHP de los partials**

Run: `php -l templates/element/observation_bubble.php && php -l templates/element/observation_bubble_template.php`
Expected: `No syntax errors detected` para cada archivo.

- [ ] **Step 6: Commit**

```bash
git add webroot/js/sgi-observation-chat.js \
        templates/element/observation_bubble.php \
        templates/element/observation_bubble_template.php
git commit -m "feat(observations): helper SgiObservationChat y partials de chat compartidos"
```

---

## Task 2: Migrar Invoices

**Files:**
- Modify: `src/Controller/InvoicesController.php` (action `addObservation`, líneas ~450-484)
- Modify: `templates/Invoices/edit.php` (bloque chat ~líneas 1029-1091 y JS inline ~líneas 1131-1235)

**Contexto:** Invoices ya tiene rama JSON funcional, pero con un contrato distinto y JS inline duplicado. Esta tarea valida el helper contra el caso más complejo (es la vista con más interacciones y la única que ya tenía AJAX bien probado).

- [ ] **Step 1: Normalizar la rama JSON del controller**

Reemplazar el método `addObservation` completo en `src/Controller/InvoicesController.php` (líneas 450-484) por:

```php
public function addObservation($id = null)
{
    $this->request->allowMethod(['post']);
    $user = $this->_getCurrentUser();
    $message = trim((string)$this->request->getData('message'));

    $observationsTable = $this->fetchTable('InvoiceObservations');
    $observation = $observationsTable->newEntity([
        'invoice_id' => $id,
        'user_id' => $user->id,
        'message' => $message,
    ]);

    $saved = $message !== '' && $observationsTable->save($observation);

    if ($this->_isJsonRequest()) {
        if (!$saved) {
            return $this->_jsonResponse([
                'success' => false,
                'error' => $message === ''
                    ? 'El mensaje no puede estar vacío.'
                    : 'No se pudo agregar la observación.',
            ]);
        }

        return $this->_jsonResponse([
            'success' => true,
            'observation' => [
                'id' => $observation->id,
                'message' => $observation->message,
                'user_name' => $user->full_name,
                'created' => $observation->created->format('d/m/Y H:i'),
            ],
        ]);
    }

    if ($saved) {
        $this->Flash->success('Observación agregada.');
    } else {
        $this->Flash->error(
            $message === ''
                ? 'El mensaje no puede estar vacío.'
                : 'No se pudo agregar la observación.'
        );
    }

    return $this->_redirectForInvoice((int)$id, 'edit', $id);
}
```

- [ ] **Step 2: Reemplazar el render del chat en `templates/Invoices/edit.php`**

Reemplazar todo el bloque que va desde `<!-- Observaciones: chat -->` hasta el `</div>` que cierra `card card-primary` del chat (líneas 1029-1091) por:

```php
<!-- Observaciones: chat -->
<?php $obsCount = count($invoice->invoice_observations ?? []); ?>
<div class="card card-primary sgi-obs-card" style="display:flex;flex-direction:column;">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-chat-left-text" style="font-size:.85rem;color:var(--primary-color);"></i>
        <span style="font-size:.85rem;font-weight:600;">Observaciones</span>
        <span class="sgi-folder-count ms-auto" <?= $obsCount === 0 ? 'style="display:none;"' : '' ?>><?= $obsCount ?></span>
    </div>

    <div id="obs-chat-scroll" class="sgi-obs-list"
         style="min-height:100px;max-height:340px;overflow-y:auto;padding:1rem .875rem;background:#f9fafb;display:flex;flex-direction:column;gap:.875rem;">
        <?php foreach ($invoice->invoice_observations ?? [] as $obs): ?>
            <?= $this->element('observation_bubble', [
                'observation' => $obs,
                'isMine' => $currentUser && $obs->user_id === $currentUser->id,
            ]) ?>
        <?php endforeach; ?>
    </div>

    <div id="obs-empty-state" <?= $obsCount > 0 ? 'style="display:none;"' : '' ?>
         class="sgi-obs-empty">
        <i class="bi bi-chat-square-dots" style="font-size:1.75rem;"></i>
        <span style="font-size:.78rem;">Sin observaciones aún</span>
    </div>

    <div style="border-top:1px solid var(--border-color);padding:.75rem .875rem;background:#fff;">
        <form id="obs-form" data-url="<?= $this->Url->build(['action' => 'addObservation', $invoice->id]) ?>">
        <div class="d-flex gap-2 align-items-end">
            <textarea id="obs-message" name="message" class="form-control auto-resize" rows="1"
                      style="font-size:.82rem;background:#f9fafb;border-color:var(--border-color);"
                      placeholder="Escriba una observación..."></textarea>
            <button type="submit" class="btn btn-primary flex-shrink-0"
                    style="padding:.5rem .75rem;align-self:flex-end;" title="Enviar">
                <i class="bi bi-send" style="font-size:.85rem;"></i>
            </button>
        </div>
        </form>
    </div>
</div>
```

Nota: el empty-state se mueve **fuera** del scroll (debajo de la lista, sin fondo gris) para que el helper pueda mostrarlo/ocultarlo sin lidiar con el padding del scroll. La diferencia visual es mínima (mismo icono, mismo texto, mismo placeholder visual cuando vacío).

- [ ] **Step 3: Sustituir el JS inline por la inicialización del helper**

En `templates/Invoices/edit.php`, dentro del bloque `<?php $this->append('script') ?>` (~línea 1131), reemplazar todo el `(function(){ ... })();` que contiene el manejo del chat de observaciones por:

```php
<?= $this->Html->script('sgi-observation-chat', ['block' => true]) ?>
<?= $this->element('observation_bubble_template') ?>

<?php $this->append('script') ?>
<script>
(function(){
    // Auto-resize textareas
    function syncHeight(el) {
        el.style.height = '0px';
        el.style.height = (el.scrollHeight + 2) + 'px';
    }
    document.querySelectorAll('textarea.auto-resize').forEach(function(el) {
        el.style.overflow  = 'hidden';
        el.style.resize    = 'none';
        el.style.minHeight = '0px';
        syncHeight(el);
        el.addEventListener('input', function() { syncHeight(this); });
    });

    SgiObservationChat.init({
        formSelector:           '#obs-form',
        listSelector:           '#obs-chat-scroll',
        emptySelector:          '#obs-empty-state',
        bubbleTemplateSelector: '#observation-bubble-template',
        csrfToken:              <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
    });

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
})();
</script>
```

Nota: El `counterSelector` del uploader sigue apuntando al contador del `.card` del bloque de documentos. Como ahora el chat de observaciones también tiene un `.sgi-folder-count`, comprobar tras la migración que el contador de documentos no se confunde con el de observaciones (verificación en step 4 caso "doble contador").

- [ ] **Step 4: Validación manual**

Levantar `php bin/cake server` y abrir una factura existente con permiso de edición. Confirmar:

1. Caso feliz: escribir un mensaje en el chat → aparece como burbuja propia (alineada a la derecha, fondo verde) sin recargar; contador del header sube en 1; textarea se vacía y queda enfocado.
2. Render inicial vs incremental: recargar la página → la observación recién agregada se ve idéntica (misma alineación derecha, mismo color de fondo, mismo border-radius).
3. Mensaje vacío: enviar con textarea vacío → no envía; nada se inserta.
4. Caracteres especiales: enviar `<script>alert('xss')</script>` → se renderiza como texto, no se ejecuta.
5. Saltos de línea: enviar mensaje multilínea → se preservan los saltos.
6. Doble contador: el contador de documentos sigue mostrando "N docs" y no cambia al agregar una observación.
7. Fallback no-JS: deshabilitar JavaScript en el navegador, escribir y enviar un mensaje → debe redirigir y mostrar Flash. La observación queda guardada al recargar.
8. Subida de documentos: confirmar que el helper de documentos sigue funcionando (no debería haber regresión).

- [ ] **Step 5: Commit**

```bash
git add src/Controller/InvoicesController.php templates/Invoices/edit.php
git commit -m "feat(observations): migrar chat de Invoices al helper SgiObservationChat"
```

---

## Task 3: Migrar PaymentSchedulings

**Files:**
- Modify: `src/Controller/PaymentSchedulingsController.php` (action `addObservation`, líneas ~457-...)
- Modify: `templates/PaymentSchedulings/edit.php` (bloque chat ~líneas 413-471 y JS inline ~líneas 563-625)

**Contexto:** Idéntico patrón al de Invoices. El chat tiene exactamente el mismo HTML inline.

- [ ] **Step 1: Normalizar la rama JSON del controller**

Leer la implementación actual de `addObservation` en `src/Controller/PaymentSchedulingsController.php` y reemplazarla por:

```php
public function addObservation($id = null)
{
    $this->request->allowMethod(['post']);
    $user = $this->_getCurrentUser();
    $message = trim((string)$this->request->getData('message'));

    $observationsTable = $this->fetchTable('PaymentSchedulingObservations');
    $observation = $observationsTable->newEntity([
        'payment_scheduling_id' => $id,
        'user_id' => $user->id,
        'message' => $message,
    ]);

    $saved = $message !== '' && $observationsTable->save($observation);

    if ($this->_isJsonRequest()) {
        if (!$saved) {
            return $this->_jsonResponse([
                'success' => false,
                'error' => $message === ''
                    ? 'El mensaje no puede estar vacío.'
                    : 'No se pudo agregar la observación.',
            ]);
        }
        return $this->_jsonResponse([
            'success' => true,
            'observation' => [
                'id' => $observation->id,
                'message' => $observation->message,
                'user_name' => $user->full_name,
                'created' => $observation->created->format('d/m/Y H:i'),
            ],
        ]);
    }

    if ($saved) {
        $this->Flash->success('Observación agregada.');
    } else {
        $this->Flash->error(
            $message === ''
                ? 'El mensaje no puede estar vacío.'
                : 'No se pudo agregar la observación.'
        );
    }

    return $this->redirect(['action' => 'edit', $id]);
}
```

Si el método actual usa una forma distinta de obtener el usuario (no `_getCurrentUser`), conservar esa forma y solo asegurar que `$user->full_name` esté disponible.

- [ ] **Step 2: Reemplazar el render del chat en el template**

Reemplazar el bloque del chat (entre el comentario `<!-- ── Columna derecha: soportes + observaciones ── -->` y el cierre del `card` de observaciones, ~líneas 413-471) usando exactamente la misma estructura que en Task 2 step 2, pero cambiando:
- `$invoice->invoice_observations` → `$record->payment_scheduling_observations`
- `$invoice->id` → `$record->id`

- [ ] **Step 3: Reemplazar el JS inline**

En el bloque `<?php $this->append('script') ?>` del template, eliminar todo el bloque que arranca con `// AJAX observations` (~línea 563-625) y reemplazarlo por la inicialización del helper, idéntica a Task 2 step 3 (sin el bloque de `SgiDocumentUploader` si este template no lo usa — verificar en el archivo actual).

Asegurar que el `<?= $this->Html->script('sgi-observation-chat', ['block' => true]) ?>` y `<?= $this->element('observation_bubble_template') ?>` estén presentes una sola vez.

- [ ] **Step 4: Validación manual**

Repetir los 7 puntos de Task 2 step 4 sobre una programación de pago existente. Punto 6 (doble contador) solo aplica si el template tiene también la sección de documentos.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/PaymentSchedulingsController.php templates/PaymentSchedulings/edit.php
git commit -m "feat(observations): migrar chat de PaymentSchedulings al helper SgiObservationChat"
```

---

## Task 4: Migrar EmployeeNovelties

**Files:**
- Modify: `src/Controller/EmployeeNoveltiesController.php` (action `addObservation`, ~línea 882)
- Modify: `templates/EmployeeNovelties/edit.php` (bloque chat ~líneas 518-575)

**Contexto:** Hoy es POST+redirect (sin AJAX). Esta tarea le añade la rama JSON y migra el render del chat. La validación incluye verificar que el fallback no-JS sigue funcionando.

- [ ] **Step 1: Añadir rama JSON al controller**

Leer la implementación actual de `addObservation` y reemplazarla por la misma estructura que Task 3 step 1, pero usando:
- Tabla: `NoveltyObservations`
- FK: `novelty_id` (verificar en `src/Model/Table/NoveltyObservationsTable.php` si la columna se llama distinto; ajustar si hace falta)
- Redirect fallback: `['action' => 'edit', $id]`

Si el método actual usa `NoveltyObservationService` (única tabla con servicio existente), mantener la llamada al servicio en la rama no-JSON y solo agregar el camino JSON inline. Patrón:

```php
public function addObservation(?string $id = null)
{
    $this->request->allowMethod(['post']);
    $user = $this->_getCurrentUser();
    $message = trim((string)$this->request->getData('message'));

    $observationsTable = $this->fetchTable('NoveltyObservations');
    $observation = $observationsTable->newEntity([
        'novelty_id' => $id,
        'user_id' => $user->id,
        'message' => $message,
    ]);

    $saved = $message !== '' && $observationsTable->save($observation);

    if ($this->_isJsonRequest()) {
        if (!$saved) {
            return $this->_jsonResponse([
                'success' => false,
                'error' => $message === ''
                    ? 'El mensaje no puede estar vacío.'
                    : 'No se pudo agregar la observación.',
            ]);
        }
        return $this->_jsonResponse([
            'success' => true,
            'observation' => [
                'id' => $observation->id,
                'message' => $observation->message,
                'user_name' => $user->full_name,
                'created' => $observation->created->format('d/m/Y H:i'),
            ],
        ]);
    }

    if ($saved) {
        $this->Flash->success('Observación agregada.');
    } else {
        $this->Flash->error(
            $message === ''
                ? 'El mensaje no puede estar vacío.'
                : 'No se pudo agregar la observación.'
        );
    }

    return $this->redirect(['action' => 'edit', $id]);
}
```

Si `_getCurrentUser` no existe en este controller, usar el patrón equivalente del archivo (probablemente `(int)$this->Authentication->getIdentity()->getIdentifier()` y luego cargar el usuario desde Users).

- [ ] **Step 2: Reemplazar el render del chat en el template**

Mismo bloque que Task 2 step 2, ajustando:
- `$invoice->invoice_observations` → `$novelty->novelty_observations`
- `$invoice->id` → `$novelty->id`

- [ ] **Step 3: Reemplazar el JS inline**

Si el template tiene un bloque inline relacionado con observaciones (revisar entre líneas 600-650), eliminarlo. Añadir el bloque idéntico al de Task 2 step 3 (incluyendo `Html->script` y el template element). Si el template ya inicializa `SgiDocumentUploader`, mantener esa llamada.

- [ ] **Step 4: Validación manual**

Repetir los 7 puntos de Task 2 step 4 sobre una novedad existente. Énfasis especial en el punto 7 (fallback no-JS) ya que este módulo era POST+redirect: el comportamiento sin JS debe ser idéntico al actual (POST → redirect a `edit` → Flash de éxito).

- [ ] **Step 5: Commit**

```bash
git add src/Controller/EmployeeNoveltiesController.php templates/EmployeeNovelties/edit.php
git commit -m "feat(observations): migrar chat de EmployeeNovelties al helper SgiObservationChat"
```

---

## Task 5: Migrar PettyCashRecords

**Files:**
- Modify: `src/Controller/PettyCashRecordsController.php` (action `addObservation`, ~línea 570)
- Modify: `templates/PettyCashRecords/edit.php` (bloque chat ~líneas 522-580)

**Contexto:** Igual a Task 4. El template ya usa la misma estructura de chat-bubble que los demás, así que la migración del render es directa.

- [ ] **Step 1: Añadir rama JSON al controller**

Mismo patrón de Task 3 step 1, ajustando:
- Tabla: `PettyCashObservations`
- FK: `petty_cash_record_id` (verificar nombre exacto)
- Redirect fallback: `['action' => 'edit', $id]`

- [ ] **Step 2: Reemplazar el render del chat en el template**

Mismo bloque que Task 2 step 2, ajustando:
- `$invoice->invoice_observations` → `$record->petty_cash_observations`
- `$invoice->id` → `$record->id`

- [ ] **Step 3: Añadir inicialización del helper**

En el bloque de scripts del template, añadir lo de Task 2 step 3 (helper script + template element + init). Mantener la inicialización existente de `SgiDocumentUploader`.

- [ ] **Step 4: Validación manual**

Repetir los 7 puntos de Task 2 step 4 sobre un registro de caja menor existente.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/PettyCashRecordsController.php templates/PettyCashRecords/edit.php
git commit -m "feat(observations): migrar chat de PettyCashRecords al helper SgiObservationChat"
```

---

## Task 6: Migrar Refunds

**Files:**
- Modify: `src/Controller/RefundsController.php` (action `addObservation`, ~línea 551)
- Modify: `templates/Refunds/edit.php` (bloque chat ~líneas 532-590)

**Contexto:** Mismo patrón que Task 5. El template usa exactamente la misma estructura de chat-bubble.

- [ ] **Step 1: Añadir rama JSON al controller**

Mismo patrón de Task 3 step 1, ajustando:
- Tabla: `RefundObservations`
- FK: `refund_id` (verificar)
- Redirect fallback: `['action' => 'edit', $id]`

- [ ] **Step 2: Reemplazar el render del chat en el template**

Mismo bloque que Task 2 step 2, ajustando:
- `$invoice->invoice_observations` → `$record->refund_observations`
- `$invoice->id` → `$record->id`

- [ ] **Step 3: Añadir inicialización del helper**

Mismo patrón que Task 2 step 3.

- [ ] **Step 4: Validación manual**

Repetir los 7 puntos de Task 2 step 4 sobre un refund existente.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/RefundsController.php templates/Refunds/edit.php
git commit -m "feat(observations): migrar chat de Refunds al helper SgiObservationChat"
```

---

## Task 7: Migrar Employees

**Files:**
- Modify: `src/Controller/EmployeesController.php` (action `addObservation`, líneas 176-194)
- Modify: `templates/Employees/view.php` (bloque chat ~líneas 253-305)

**Contexto:** Caso especial — el chat vive en `view.php`, no en `edit.php`, y el render actual usa un layout distinto (avatar + nombre + mensaje en línea, no burbujas). Migrarlo al partial unificado implica un **cambio visual deliberado**: el chat de Employees pasa al estilo burbuja del resto del sistema. Esta decisión se tomó en brainstorm (opción A: tratar los 6 igual).

- [ ] **Step 1: Añadir rama JSON al controller**

Reemplazar el método `addObservation` actual (líneas 176-194) por:

```php
public function addObservation($id = null)
{
    $this->request->allowMethod(['post']);
    $userId = (int)$this->Authentication->getIdentity()->getIdentifier();
    $user = $this->fetchTable('Users')->get($userId);
    $message = trim((string)$this->request->getData('message'));

    $observationsTable = $this->fetchTable('EmployeeObservations');
    $observation = $observationsTable->newEntity([
        'employee_id' => $id,
        'user_id' => $userId,
        'message' => $message,
    ]);

    $saved = $message !== '' && $observationsTable->save($observation);

    if ($this->_isJsonRequest()) {
        if (!$saved) {
            return $this->_jsonResponse([
                'success' => false,
                'error' => $message === ''
                    ? 'El mensaje no puede estar vacío.'
                    : 'No se pudo agregar la observación.',
            ]);
        }
        return $this->_jsonResponse([
            'success' => true,
            'observation' => [
                'id' => $observation->id,
                'message' => $observation->message,
                'user_name' => $user->full_name,
                'created' => $observation->created->format('d/m/Y H:i'),
            ],
        ]);
    }

    if ($saved) {
        $this->Flash->success('Observación agregada.');
    } else {
        $this->Flash->error(
            $message === ''
                ? 'El mensaje no puede estar vacío.'
                : 'No se pudo agregar la observación.'
        );
    }

    return $this->redirect(['action' => 'view', $id]);
}
```

Nota: si el `EmployeesController` ya tiene `_isJsonRequest`/`_jsonResponse` heredados del `AppController`, esto compila sin más. Si no, verificar y usar la forma alternativa equivalente del controller.

- [ ] **Step 2: Reemplazar el render del chat en `view.php`**

Reemplazar el bloque `<!-- Observaciones (chat) -->` completo (líneas 253-305) por:

```php
<!-- Observaciones (chat) -->
<div class="card card-primary sgi-obs-card" style="display:flex;flex-direction:column;border-top:1px solid var(--border-color);">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-chat-left-text" style="font-size:.85rem;color:var(--primary-color);"></i>
        <span style="font-size:.85rem;font-weight:600;">Observaciones</span>
        <?php $obsCount = count($employee->employee_observations ?? []); ?>
        <span class="sgi-folder-count ms-auto" <?= $obsCount === 0 ? 'style="display:none;"' : '' ?>><?= $obsCount ?></span>
    </div>

    <div id="obs-chat-scroll" class="sgi-obs-list"
         style="min-height:100px;max-height:400px;overflow-y:auto;padding:1rem .875rem;background:#f9fafb;display:flex;flex-direction:column;gap:.875rem;">
        <?php
        $currentUser = $this->getRequest()->getAttribute('identity');
        foreach ($employee->employee_observations ?? [] as $obs):
        ?>
            <?= $this->element('observation_bubble', [
                'observation' => $obs,
                'isMine' => $currentUser && $obs->user_id === $currentUser->id,
            ]) ?>
        <?php endforeach; ?>
    </div>

    <div id="obs-empty-state" <?= $obsCount > 0 ? 'style="display:none;"' : '' ?>
         class="sgi-obs-empty">
        <i class="bi bi-chat-square-dots" style="font-size:1.75rem;"></i>
        <span style="font-size:.78rem;">Sin observaciones aún</span>
    </div>

    <?php if (!empty($userPermissions['employees']['can_edit'])): ?>
    <div style="border-top:1px solid var(--border-color);padding:.75rem .875rem;background:#fff;">
        <form id="obs-form" data-url="<?= $this->Url->build(['action' => 'addObservation', $employee->id]) ?>">
        <div class="d-flex gap-2 align-items-end">
            <textarea name="message" class="form-control auto-resize" rows="1"
                      style="font-size:.82rem;background:#f9fafb;border-color:var(--border-color);"
                      placeholder="Escriba una observación..."></textarea>
            <button type="submit" class="btn btn-primary flex-shrink-0"
                    style="padding:.5rem .75rem;align-self:flex-end;" title="Enviar">
                <i class="bi bi-send" style="font-size:.85rem;"></i>
            </button>
        </div>
        </form>
    </div>
    <?php endif; ?>
</div>
```

- [ ] **Step 3: Añadir scripts e inicialización del helper**

Al final de `templates/Employees/view.php`, añadir:

```php
<?= $this->Html->script('sgi-observation-chat', ['block' => true]) ?>
<?= $this->element('observation_bubble_template') ?>

<?php $this->append('script') ?>
<script>
(function(){
    function syncHeight(el) {
        el.style.height = '0px';
        el.style.height = (el.scrollHeight + 2) + 'px';
    }
    document.querySelectorAll('textarea.auto-resize').forEach(function(el) {
        el.style.overflow  = 'hidden';
        el.style.resize    = 'none';
        el.style.minHeight = '0px';
        syncHeight(el);
        el.addEventListener('input', function() { syncHeight(this); });
    });

    SgiObservationChat.init({
        formSelector:           '#obs-form',
        listSelector:           '#obs-chat-scroll',
        emptySelector:          '#obs-empty-state',
        bubbleTemplateSelector: '#observation-bubble-template',
        csrfToken:              <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
    });
})();
</script>
<?php $this->end() ?>
```

Si el template ya tiene un `$this->append('script')` abierto, anidar la inicialización dentro del bloque existente en vez de crear uno nuevo.

- [ ] **Step 4: Validación manual**

Levantar `php bin/cake server`, abrir un empleado existente. Confirmar:

1. Visual: el chat se ve igual al de Invoices (burbujas, mismo padding, fondo gris). Es un cambio visual respecto a antes.
2. Caso feliz: enviar mensaje → aparece como burbuja propia sin recargar; contador sube; textarea se vacía y queda enfocado.
3. Render inicial vs incremental: recargar → burbuja idéntica.
4. Mensaje vacío: no se envía.
5. Caracteres especiales y saltos de línea: como en Task 2.
6. Permisos: usuario sin `can_edit` sobre Employees no ve el form (sin regresión).
7. Fallback no-JS: deshabilitar JS → POST clásico redirige a `view` con Flash; observación queda guardada.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/EmployeesController.php templates/Employees/view.php
git commit -m "feat(observations): migrar chat de Employees al helper SgiObservationChat (cambio visual a estilo burbuja)"
```

---

## Task 8: cs-fix y revisión final

**Files:**
- Sin cambios manuales esperados (solo formato).

**Contexto:** Asegurar que todo el conjunto pasa el estilo CakePHP estándar y revisar los 6 controllers + 6 templates en bloque.

- [ ] **Step 1: Pasar `composer cs-check`**

Run: `composer cs-check`
Expected: 0 violaciones. Si hay, aplicar `composer cs-fix` y commitear.

- [ ] **Step 2: Verificar que no hay AJAX inline duplicado residual**

Run: `grep -rn "obs-form\|obs-chat-scroll" templates/ | grep -v 'observation_bubble' | grep -i 'fetch\|xmlhttprequest\|addEventListener'`
Expected: salida vacía (todo el JS de observaciones vive en `sgi-observation-chat.js`).

- [ ] **Step 3: Verificar cobertura del helper**

Run: `grep -rln "SgiObservationChat.init" templates/`
Expected: 6 archivos listados (Invoices/edit.php, PaymentSchedulings/edit.php, EmployeeNovelties/edit.php, Employees/view.php, PettyCashRecords/edit.php, Refunds/edit.php).

- [ ] **Step 4: Verificar contrato JSON uniforme**

Run: `grep -A2 "'observation' =>" src/Controller/{Invoices,PaymentSchedulings,EmployeeNovelties,Employees,PettyCashRecords,Refunds}Controller.php`
Expected: las 6 entradas devuelven exactamente `id`, `message`, `user_name`, `created`. Si alguna difiere, ajustar.

- [ ] **Step 5: Si hubo cs-fix, commit**

```bash
git add -A
git commit -m "style: cs-fix tras unificación del chat de observaciones"
```

- [ ] **Step 6: Smoke test final**

Por cada uno de los 6 módulos, abrir una pantalla con observaciones existentes y confirmar a ojo:
- El chat se renderiza igual (burbujas, alineación, colores).
- Agregar una observación funciona vía AJAX.
- El contador del header se sincroniza.
- El JS de los demás features de la pantalla (uploader, pickers, etc.) sigue funcionando sin errores en consola.

---

## Notas para el implementador

- **Política de tests**: cero archivos en `tests/`, ninguna fixture, ningún PHPUnit. Validación es manual con `php bin/cake server` y los puntos listados en cada step 4.
- **No alterar permisos**: la migración no toca `_enforcePermission`, `controllerModuleMap` ni la tabla `permissions`. Si en algún módulo el form de observación se renderizaba bajo un check de permiso (ej. Employees con `userPermissions['employees']['can_edit']`), conservar ese check exacto.
- **Si encuentras un controller que difiere significativamente** (ej. valida más cosas o trabaja con un service): preserva esa lógica adicional y solo añade la rama JSON, no la elimines.
- **Carga única del script JS**: si dos elementos en una vista (chat + uploader) ambos pidieran el mismo script, CakePHP lo deduplica con `block => true`. No hay riesgo.
- **El usuario puede revisar entre tareas**. Cada commit es atómico y reversible. Si algo se rompe en una tarea, las anteriores siguen funcionando porque el fallback no-JSON nunca se elimina.

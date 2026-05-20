<?php
/**
 * Drawer flotante de Observaciones para Invoices/edit.
 *
 * Disparador fijo al borde derecho del viewport + Bootstrap Offcanvas con el
 * chat de observaciones, renderizado con el componente `.chat` del sistema de
 * diseño (timeline de comentarios).
 *
 * Autocontenido: emite su propio <template> y inicializa SgiObservationChat —
 * NO depende del element compartido observation_chat_init.php (las otras vistas
 * con chat siguen usando ese y las burbujas). Conserva los IDs estándar
 * (#obs-form, #obs-chat-scroll, #obs-empty-state, #obs-count) del contrato de
 * webroot/js/sgi-observation-chat.js.
 *
 * Restricciones de uso: incluir este element FUERA del formulario principal de
 * la vista (#invoiceEditForm) para no anidar <form>; y la vista anfitriona no
 * debe renderizar además la tarjeta inline de Observaciones — los IDs #obs-form
 * y #obs-count deben existir una sola vez en el DOM.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 * @var \App\Model\Entity\User|null $currentUser
 */
$obsCount = count($invoice->invoice_observations ?? []);
// Nombre del usuario actual: el avatar del <template> es siempre suyo porque
// SgiObservationChat marca cada mensaje recién publicado como propio.
$currentUserName = $currentUser->full_name
    ?? ($currentUser->username ?? 'Usuario');
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
            <i class="bi bi-chat-left-text" aria-hidden="true"></i>
            Observaciones
            <span id="obs-head-count" class="chat-head-count"><?= $obsCount ?></span>
        </h2>
        <button type="button" class="btn-icon" data-bs-dismiss="offcanvas" aria-label="Cerrar">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>
    <div class="offcanvas-body">
        <div id="obs-chat-scroll" class="chat-list">
            <?php foreach ($invoice->invoice_observations ?? [] as $obs): ?>
                <?= $this->element('invoice_edit/observation_chat_item', ['observation' => $obs]) ?>
            <?php endforeach; ?>
        </div>

        <div id="obs-empty-state" class="empty-state" <?= $obsCount > 0 ? 'hidden' : '' ?>>
            <div class="es-icon es-icon-neutral">
                <i class="bi bi-chat-square-dots" aria-hidden="true"></i>
            </div>
            <div class="es-msg">Sin observaciones aún</div>
        </div>

        <div class="chat-composer">
            <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $invoice->id], 'id' => 'obs-form']) ?>
            <div class="chat-composer-box">
                <textarea id="obs-message" name="message" class="auto-resize chat-composer-input"
                          rows="1" placeholder="Escriba una observación..."></textarea>
                <div class="chat-composer-toolbar">
                    <button type="submit" class="btn btn-primary btn-sm">Publicar</button>
                </div>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>

<?php /* Gemelo estructural de observation_chat_item.php; el avatar es del
         usuario actual (SgiObservationChat marca cada mensaje nuevo como propio). */ ?>
<template id="invoice-obs-chat-item">
    <div class="chat-item" data-obs-id="">
        <?= $this->element('invoice_edit/_chat_avatar', ['name' => $currentUserName]) ?>
        <div class="chat-body">
            <div class="chat-meta">
                <span class="chat-meta-author" data-slot="user_name"></span>
                <span class="chat-meta-time" data-slot="created"></span>
            </div>
            <div class="chat-text" data-slot="message"></div>
        </div>
    </div>
</template>

<?= $this->Html->script('sgi-observation-chat', ['block' => true]) ?>

<?php $this->append('script') ?>
<script>
(function () {
    var drawer = document.getElementById('obsDrawer');
    var scroll = document.getElementById('obs-chat-scroll');

    // Auto-resize del textarea del composer.
    document.querySelectorAll('#obsDrawer textarea.auto-resize').forEach(function (el) {
        function sync() { el.style.height = '0px'; el.style.height = (el.scrollHeight + 2) + 'px'; }
        el.style.overflow = 'hidden';
        el.style.resize = 'none';
        sync();
        el.addEventListener('input', sync);
    });

    // .chat-composer-box toma .focus mientras el textarea está enfocado.
    var box = document.querySelector('#obsDrawer .chat-composer-box');
    var ta = document.getElementById('obs-message');
    if (box && ta) {
        ta.addEventListener('focus', function () { box.classList.add('focus'); });
        ta.addEventListener('blur', function () { box.classList.remove('focus'); });
    }

    // SgiObservationChat actualiza #obs-count (badge del disparador); reflejamos
    // su valor en el contador del header del drawer (.chat-head-count).
    var triggerCount = document.getElementById('obs-count');
    var headCount = document.getElementById('obs-head-count');
    if (triggerCount && headCount && window.MutationObserver) {
        new MutationObserver(function () {
            var m = triggerCount.textContent.match(/(\d+)/);
            headCount.textContent = m ? m[1] : '0';
        }).observe(triggerCount, { childList: true, characterData: true, subtree: true });
    }

    if (window.SgiObservationChat) {
        SgiObservationChat.init({
            formSelector:           '#obs-form',
            listSelector:           '#obs-chat-scroll',
            emptySelector:          '#obs-empty-state',
            counterSelector:        '#obs-count',
            bubbleTemplateSelector: '#invoice-obs-chat-item',
            csrfToken:              <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>,
        });
    }

    // El panel está oculto al cargar; al mostrarse, ir al último mensaje.
    if (drawer && scroll) {
        drawer.addEventListener('shown.bs.offcanvas', function () {
            scroll.scrollTop = scroll.scrollHeight;
        });
    }
})();
</script>
<?php $this->end() ?>

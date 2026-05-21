<?php
/**
 * Drawer flotante de Observaciones — element compartido para módulos con chat
 * de observaciones (Facturas, Anticipos, Novedades, Caja Menor, Reintegros,
 * Programaciones de Pago).
 *
 * Disparador fijo al borde derecho del viewport + Bootstrap Offcanvas con el
 * chat renderizado con el componente `.chat` del sistema de diseño.
 *
 * Autocontenido: emite su propio <template> e inicializa SgiObservationChat.
 * Conserva los IDs estándar (#obs-form, #obs-chat-scroll, #obs-empty-state,
 * #obs-count) del contrato de webroot/js/sgi-observation-chat.js.
 *
 * Restricciones de uso: incluir este element FUERA del formulario principal de
 * la vista (tiene su propio <form>); la vista anfitriona no debe renderizar
 * además otra tarjeta de Observaciones — los IDs #obs-form y #obs-count deben
 * existir una sola vez en el DOM.
 *
 * @var \App\View\AppView $this
 * @var iterable $observations  Entidades de observación (id, message, created, user).
 * @var int      $count         Número de observaciones.
 * @var array|string $formUrl   URL del POST de addObservation del módulo.
 * @var string   $currentUserName  Nombre del usuario actual (avatar del <template>).
 * @var ?string  $emptyMessage  Texto del empty state. Default: "Sin observaciones aún".
 */
$emptyMessage = $emptyMessage ?? 'Sin observaciones aún';
$observations = $observations ?? [];
$count        = $count ?? 0;
?>
<button type="button" class="sgi-obs-trigger"
        data-bs-toggle="offcanvas" data-bs-target="#obsDrawer"
        aria-label="Abrir observaciones">
    <i class="bi bi-chat-left-text" aria-hidden="true"></i>
    <span id="obs-count" class="sgi-obs-trigger-badge"
          <?= $count === 0 ? 'style="display:none;"' : '' ?>><?= $count ?></span>
</button>

<div class="offcanvas offcanvas-end sgi-obs-drawer" id="obsDrawer" tabindex="-1"
     aria-labelledby="obsDrawerTitle">
    <div class="offcanvas-header">
        <h2 class="offcanvas-title" id="obsDrawerTitle">
            <i class="bi bi-chat-left-text" aria-hidden="true"></i>
            Observaciones
            <span id="obs-head-count" class="chat-head-count"><?= $count ?></span>
        </h2>
        <button type="button" class="btn-icon" data-bs-dismiss="offcanvas" aria-label="Cerrar">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>
    <div class="offcanvas-body">
        <div id="obs-chat-scroll" class="chat-list">
            <?php foreach ($observations as $obs): ?>
                <?= $this->element('observations/chat_item', ['observation' => $obs]) ?>
            <?php endforeach; ?>
        </div>

        <div id="obs-empty-state" class="empty-state" <?= $count > 0 ? 'hidden' : '' ?>>
            <div class="es-icon es-icon-neutral">
                <i class="bi bi-chat-square-dots" aria-hidden="true"></i>
            </div>
            <div class="es-msg"><?= h($emptyMessage) ?></div>
        </div>

        <div class="chat-composer">
            <?= $this->Form->create(null, ['url' => $formUrl, 'id' => 'obs-form']) ?>
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

<?php /* Gemelo estructural de observations/chat_item.php; el avatar es del
         usuario actual (SgiObservationChat marca cada mensaje nuevo como propio). */ ?>
<template id="sgi-obs-chat-item">
    <div class="chat-item" data-obs-id="">
        <?= $this->element('observations/chat_avatar', ['name' => $currentUserName]) ?>
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

    document.querySelectorAll('#obsDrawer textarea.auto-resize').forEach(function (el) {
        function sync() { el.style.height = '0px'; el.style.height = (el.scrollHeight + 2) + 'px'; }
        el.style.overflow = 'hidden';
        el.style.resize = 'none';
        sync();
        el.addEventListener('input', sync);
    });

    var box = document.querySelector('#obsDrawer .chat-composer-box');
    var ta = document.getElementById('obs-message');
    if (box && ta) {
        ta.addEventListener('focus', function () { box.classList.add('focus'); });
        ta.addEventListener('blur', function () { box.classList.remove('focus'); });
    }

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
            bubbleTemplateSelector: '#sgi-obs-chat-item',
            csrfToken:              <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>,
        });
    }

    if (drawer && scroll) {
        drawer.addEventListener('shown.bs.offcanvas', function () {
            scroll.scrollTop = scroll.scrollHeight;
        });
    }
})();
</script>
<?php $this->end() ?>

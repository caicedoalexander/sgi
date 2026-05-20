<?php
/**
 * Drawer flotante de Observaciones para Invoices/edit.
 *
 * Disparador fijo al borde derecho del viewport + Bootstrap Offcanvas con el
 * chat de observaciones. Conserva los IDs estándar (#obs-form, #obs-chat-scroll,
 * #obs-empty-state, #obs-count) que consume observation_chat_init.php, de modo
 * que SgiObservationChat funciona sin cambios.
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

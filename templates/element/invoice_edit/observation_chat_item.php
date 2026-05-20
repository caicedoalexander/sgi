<?php
/**
 * Un `.chat-item` del timeline de observaciones (drawer de Invoices/edit).
 *
 * Render server-side de una observación existente con el componente `.chat`
 * del sistema de diseño. Gemelo estructural del <template id="invoice-obs-chat-item">
 * en observations_drawer.php — los `data-slot` (user_name, message, created) y
 * las clases deben mantenerse sincronizados con ese template y con el contrato
 * de webroot/js/sgi-observation-chat.js.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\InvoiceObservation $observation Entidad con id, message,
 *      created, user (full_name / username).
 */
$authorName = $observation->user->full_name
    ?? ($observation->user->username ?? 'Usuario');
?>
<div class="chat-item" data-obs-id="<?= h($observation->id) ?>">
    <?= $this->element('invoice_edit/_chat_avatar', ['name' => $authorName]) ?>
    <div class="chat-body">
        <div class="chat-meta">
            <span class="chat-meta-author" data-slot="user_name"><?= h($authorName) ?></span>
            <span class="chat-meta-time" data-slot="created"><?= h($observation->created?->format('d/m/Y H:i')) ?></span>
        </div>
        <div class="chat-text" data-slot="message"><?= nl2br(h($observation->message)) ?></div>
    </div>
</div>

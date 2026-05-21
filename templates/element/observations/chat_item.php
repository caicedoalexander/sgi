<?php
/**
 * Un `.chat-item` del timeline de observaciones (drawer compartido).
 *
 * Gemelo estructural del <template id="sgi-obs-chat-item"> en
 * observations/drawer.php — los `data-slot` (user_name, message, created) y las
 * clases deben mantenerse sincronizados con ese template y con el contrato de
 * webroot/js/sgi-observation-chat.js.
 *
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\EntityInterface $observation Entidad con id, message,
 *      created, user (full_name / username).
 */
$authorName = $observation->user->full_name
    ?? ($observation->user->username ?? 'Usuario');
?>
<div class="chat-item" data-obs-id="<?= h($observation->id) ?>">
    <?= $this->element('observations/chat_avatar', ['name' => $authorName]) ?>
    <div class="chat-body">
        <div class="chat-meta">
            <span class="chat-meta-author" data-slot="user_name"><?= h($authorName) ?></span>
            <span class="chat-meta-time" data-slot="created"><?= h($observation->created?->format('d/m/Y H:i')) ?></span>
        </div>
        <div class="chat-text" data-slot="message"><?= nl2br(h($observation->message)) ?></div>
    </div>
</div>

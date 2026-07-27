<?php
/**
 * Observation chat bubble — usado por Invoices, PaymentSchedulings,
 * EmployeeNovelties, Employees, PettyCashRecords, Refunds para el render
 * inicial server-side, y como gemelo estructural de
 * `templates/element/observation_bubble_template.php` (consumido por
 * `webroot/js/spi-observation-chat.js`).
 *
 * IMPORTANTE: mantener markup, clases y `data-slot` sincronizados entre:
 *   - templates/element/observation_bubble.php           (server render)
 *   - templates/element/observation_bubble_template.php  (<template> JS)
 *   - webroot/js/spi-observation-chat.js                 (slot consumer)
 *
 * Required: $observation (entity con id, message, created, user_id, user->full_name)
 * Required: $isMine (bool) — true si la observación es del usuario actual
 */
$displayName = $isMine ? 'Tú' : h($observation->user->full_name ?? '');
?>
<div class="spi-obs-bubble <?= $isMine ? 'is-mine' : 'is-other' ?>"
     data-obs-id="<?= h($observation->id) ?>">
    <div class="spi-obs-bubble-name" data-slot="user_name"><?= $displayName ?></div>
    <div class="spi-obs-bubble-body" data-slot="message"><?= nl2br(h($observation->message)) ?></div>
    <div class="spi-obs-bubble-time" data-slot="created"><?= h($observation->created?->format('d/m/Y H:i')) ?></div>
</div>

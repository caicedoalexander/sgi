<?php
/**
 * Toast compartido para los mensajes flash. Usa el componente `.toast` del
 * Sistema de Diseño (icon-box + título + mensaje + cierre + barra de progreso).
 *
 * @var \App\View\AppView $this
 * @var string $message     Ya escapado por el elemento flash llamante.
 * @var string $variant     success|danger|warning|info
 * @var string $title       Título corto por tipo.
 * @var string $icon        Clase bootstrap-icons (p.ej. bi-check-lg).
 * @var bool $autoDismiss   Si false, no se autocierra ni muestra barra (danger).
 */
$variant = $variant ?? 'info';
$title = $title ?? 'Aviso';
$icon = $icon ?? 'bi-info-circle';
$autoDismiss = $autoDismiss ?? true;
?>
<div class="toast <?= h($variant) ?>" role="alert" aria-live="assertive">
    <div class="toast-icon"><i class="bi <?= h($icon) ?>" aria-hidden="true"></i></div>
    <div class="toast-body">
        <div class="toast-title"><?= h($title) ?></div>
        <div class="toast-msg"><?= $message ?></div>
    </div>
    <button type="button" class="toast-close" aria-label="Cerrar">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
    </button>
    <?php if ($autoDismiss): ?>
    <div class="toast-progress" style="width:100%"></div>
    <?php endif; ?>
</div>

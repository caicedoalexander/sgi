<?php
/**
 * Aviso de que la factura pertenece a un registro padre, que es quien gobierna
 * su pipeline. Reemplaza los avisos sueltos de legalización y de caja menor.
 *
 * @var \App\View\AppView $this
 * @var \App\View\Presentation\InvoiceLinkBadge $badge Registro padre.
 * @var bool $locked Si además la factura está bloqueada para edición.
 */
$class = $locked ? 'alert-warning' : 'alert-info';
$icon = $locked ? 'bi-lock-fill' : 'bi-link-45deg';
?>
<div class="alert <?= $class ?> d-flex align-items-center gap-2 mb-4">
    <i class="bi <?= h($icon) ?> fs-5" aria-hidden="true"></i>
    <div>
        <?php if ($locked): ?>Factura bloqueada: pertenece<?php else: ?>Esta factura pertenece<?php endif; ?>
        al registro de <strong><?= h($badge->label) ?></strong>
        <strong><?= $this->Html->link(
            h($badge->code),
            ['controller' => $badge->controller, 'action' => 'view', $badge->parentId],
            ['class' => 'alert-link'],
        ) ?></strong>. Los cambios se gestionan desde allí.
    </div>
</div>

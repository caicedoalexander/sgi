<?php
/**
 * Sidebar — sección Otros.
 *
 * @var \App\View\AppView $this
 * @var \Closure $canView    fn(string $module): bool
 * @var \Closure $navLink    fn(string $controller, ?string $action = null): string
 */

if (!$canView('approvals_inbox') && !$canView('payment_registry')) {
    return;
}
?>
<li class="sb-section-head">Otros</li>
    <?php if ($canView('approvals_inbox')) : ?>
<li>
    <?= $this->Html->link(
        '<span class="ic"><i class="bi bi-check2-square" aria-hidden="true"></i></span><span class="grow">Mis Aprobaciones</span>',
        ['controller' => 'Approvals', 'action' => 'index'],
        ['class' => $navLink('Approvals', 'index'), 'escape' => false],
    ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('payment_registry')) : ?>
<li>
    <?= $this->Html->link(
        '<span class="ic"><i class="bi bi-cash-stack" aria-hidden="true"></i></span><span class="grow">Registro de Pagos</span>',
        ['controller' => 'PaymentRegistry', 'action' => 'index'],
        ['class' => $navLink('PaymentRegistry'), 'escape' => false],
    ) ?>
</li>
    <?php endif; ?>

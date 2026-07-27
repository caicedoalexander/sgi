<?php
/**
 * Sidebar — sección Inventario TI (ITAM).
 *
 * @var \App\View\AppView $this
 * @var \Closure $canView
 * @var \Closure $navLink
 */
$itamItems = array_filter([
    $canView('assets') ? 'assets' : null,
    $canView('consumables') ? 'consumables' : null,
    $canView('asset_categories') ? 'asset_categories' : null,
    $canView('asset_alerts') ? 'asset_alerts' : null,
]);
if (empty($itamItems)) {
    return;
}
$openAlertsCount = $openAlertsCount ?? 0;
?>
<li class="sb-section-head">Inventario TI</li>
    <?php if ($canView('assets')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-pc-display" aria-hidden="true"></i></span><span class="grow">Activos</span>',
            ['controller' => 'Assets', 'action' => 'index'],
            ['class' => $navLink('Assets'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('consumables')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-droplet-half" aria-hidden="true"></i></span><span class="grow">Consumibles</span>',
            ['controller' => 'Consumables', 'action' => 'index'],
            ['class' => $navLink('Consumables'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('asset_alerts')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span><span class="grow">Alertas</span>' .
            ($openAlertsCount > 0 ? '<span class="sb-badge is-danger">' . (int)$openAlertsCount . '</span>' : ''),
            ['controller' => 'AssetAlerts', 'action' => 'index'],
            ['class' => $navLink('AssetAlerts'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('asset_categories')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-tags" aria-hidden="true"></i></span><span class="grow">Categorías de Activos</span>',
            ['controller' => 'AssetCategories', 'action' => 'index'],
            ['class' => $navLink('AssetCategories'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>

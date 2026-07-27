<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Consumable> $consumables
 * @var bool $lowStockOnly
 */
use App\View\Presentation\ConsumablePresentation;

$this->assign('title', 'Consumibles');

$canCreate = !empty($userPermissions['consumables']['can_create']);
$gridCols = '130px 1fr 90px 90px 1fr 110px';
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Consumibles</span>
    <?php if ($canCreate): ?>
    <?= $this->Html->link('<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Consumible',
        ['action' => 'add'], ['class' => 'btn btn-primary', 'escape' => false]) ?>
    <?php endif; ?>
</div>

<div class="d-flex flex-wrap" style="gap:8px;margin-bottom:14px;" role="tablist">
    <?= $this->Html->link('Todos', ['action' => 'index'],
        ['class' => 'chip' . ($lowStockOnly ? '' : ' is-active'), 'role' => 'tab']) ?>
    <?= $this->Html->link('Stock bajo', ['action' => 'index', '?' => ['low_stock' => 1]],
        ['class' => 'chip' . ($lowStockOnly ? ' is-active' : ''), 'role' => 'tab']) ?>
</div>

<div class="spi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span>Referencia</span>
        <span>Descripción</span>
        <span>Stock</span>
        <span>Mínimo</span>
        <span>Sede</span>
        <span>Estado</span>
    </div>

    <?php $rowCount = 0; foreach ($consumables as $consumable): $rowCount++; ?>
        <?php $row = ConsumablePresentation::forRow($consumable); ?>
        <div class="row-fact clickable-row" style="grid-template-columns:<?= $gridCols ?>;"
             data-href="<?= $this->Url->build(['action' => 'view', $consumable->id]) ?>" role="row">
            <span class="mono" style="color:var(--text-muted);"><?= h($consumable->reference) ?></span>
            <span style="font-weight:600;color:var(--text-strong);"><?= h($consumable->description) ?></span>
            <span class="mono"><?= $this->Number->format($consumable->current_stock) ?></span>
            <span class="mono" style="color:var(--text-faint);"><?= $this->Number->format($consumable->minimum_stock) ?></span>
            <span><?= h($row->operationCenterName) ?></span>
            <span><span class="pill <?= h($row->stockBadgeClass) ?>"><?= h($row->stockLabel) ?></span></span>
        </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-droplet" aria-hidden="true"></i></div>
        <div class="es-title">Sin consumibles</div>
        <div class="es-msg">No hay registros para mostrar.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>

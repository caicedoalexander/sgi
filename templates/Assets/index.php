<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Asset> $assets
 * @var array<int, string> $categories
 * @var array<int, string> $operationCenters
 * @var array<string, string> $statusLabels
 * @var array<string, mixed> $filters
 */
use App\View\Presentation\AssetPresentation;

$this->assign('title', 'Activos');

$canCreate = !empty($userPermissions['assets']['can_create']);
$gridCols = '120px 1fr 130px 1fr 1fr 90px';
$q = (string)($filters['q'] ?? '');
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Activos</span>
    <?php if ($canCreate): ?>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Activo',
        ['action' => 'add'],
        ['class' => 'btn btn-primary', 'escape' => false]
    ) ?>
    <?php endif; ?>
</div>

<?= $this->Form->create(null, ['type' => 'get', 'valueSources' => ['query']]) ?>
<div class="d-flex flex-wrap align-items-end" style="gap:8px;margin-bottom:14px;">
    <input type="text" name="q" class="form-control form-control-sm" style="max-width:220px;"
           placeholder="Código, serie, marca…" value="<?= h($q) ?>">
    <?= $this->Form->control('status', [
        'options' => $statusLabels, 'empty' => 'Todos los estados',
        'class' => 'form-select form-select-sm', 'label' => false,
        'value' => $filters['status'] ?? null, 'style' => 'max-width:180px;',
    ]) ?>
    <?= $this->Form->control('category_id', [
        'options' => $categories, 'empty' => 'Todas las categorías',
        'class' => 'form-select form-select-sm', 'label' => false,
        'value' => $filters['category_id'] ?? null, 'style' => 'max-width:200px;',
    ]) ?>
    <?= $this->Form->control('operation_center_id', [
        'options' => $operationCenters, 'empty' => 'Todas las sedes',
        'class' => 'form-select form-select-sm', 'label' => false,
        'value' => $filters['operation_center_id'] ?? null, 'style' => 'max-width:200px;',
    ]) ?>
    <button type="submit" class="btn btn-default btn-sm">Filtrar</button>
</div>
<?= $this->Form->end() ?>

<div class="spi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span>Código</span>
        <span>Descripción</span>
        <span>Categoría</span>
        <span>Responsable</span>
        <span>Sede</span>
        <span>Estado</span>
    </div>

    <?php $rowCount = 0; foreach ($assets as $asset): $rowCount++; ?>
        <?php $row = AssetPresentation::forRow($asset); ?>
        <div class="row-fact clickable-row" style="grid-template-columns:<?= $gridCols ?>;"
             data-href="<?= $this->Url->build(['action' => 'view', $asset->id]) ?>" role="row">
            <span class="mono" style="color:var(--text-muted);"><?= h($asset->code) ?></span>
            <span style="font-weight:600;color:var(--text-strong);"><?= h($asset->description ?: ($asset->brand . ' ' . $asset->model)) ?: '—' ?></span>
            <span><?= h($row->categoryName) ?></span>
            <span><?= h($row->responsibleName) ?></span>
            <span><?= h($row->operationCenterName) ?></span>
            <span><span class="pill <?= h($row->statusBadgeClass) ?>"><?= h($row->statusLabel) ?></span></span>
        </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-pc-display" aria-hidden="true"></i></div>
        <div class="es-title">Sin activos</div>
        <div class="es-msg">No hay activos que coincidan con los filtros.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>

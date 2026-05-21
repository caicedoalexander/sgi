<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Provider> $providers
 */
$this->assign('title', 'Proveedores');

$canEdit   = !empty($userPermissions['providers']['can_edit']);
$canDelete = !empty($userPermissions['providers']['can_delete']);
$gridCols  = '60px 130px 160px 1fr 110px 170px 96px';
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Proveedores</span>
    <div class="d-flex gap-2">
        <?= $this->element('excel_wizard/buttons', [
            'module' => 'Providers',
            'importable' => true,
            'canCreate' => !empty($userPermissions['providers']['can_create']),
        ]) ?>
        <?php if (!empty($userPermissions['providers']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Proveedor',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<div class="sgi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span><?= $this->Paginator->sort('id', '#') ?></span>
        <span><?= $this->Paginator->sort('document_type', 'Tipo Doc.') ?></span>
        <span><?= $this->Paginator->sort('document_number', 'Número Doc.') ?></span>
        <span><?= $this->Paginator->sort('name', 'Nombre') ?></span>
        <span><?= $this->Paginator->sort('active', 'Estado') ?></span>
        <span><?= $this->Paginator->sort('created', 'Creado') ?></span>
        <span style="text-align:right;">Acciones</span>
    </div>

    <?php $rowCount = 0; foreach ($providers as $provider): $rowCount++; ?>
    <div class="row-fact clickable-row" style="grid-template-columns:<?= $gridCols ?>;"
         data-href="<?= $this->Url->build(['action' => 'view', $provider->id]) ?>" role="row">
        <span class="mono" style="color:var(--text-faint);"><?= $this->Number->format($provider->id) ?></span>
        <span><span class="pill pill-secondary-soft"><?= h($provider->document_type) ?></span></span>
        <span class="mono"><?= h($provider->document_number) ?></span>
        <span style="font-weight:600;color:var(--text-strong);"><?= h($provider->name) ?></span>
        <span><?= $provider->active ? '<span class="pill pill-primary-soft">Activo</span>' : '<span class="pill pill-secondary-soft">Inactivo</span>' ?></span>
        <span class="mono" style="color:var(--text-muted);"><?= $provider->created?->format('d/m/Y H:i') ?></span>
        <span class="d-flex justify-content-end" style="gap:4px;">
            <?= $this->Html->link('<i class="bi bi-eye" aria-hidden="true"></i>',
                ['action' => 'view', $provider->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Ver']) ?>
            <?php if ($canEdit): ?>
            <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>',
                ['action' => 'edit', $provider->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Editar']) ?>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>',
                ['action' => 'delete', $provider->id],
                ['confirm' => '¿Está seguro de eliminar este proveedor?',
                 'class' => 'btn-icon', 'escape' => false, 'title' => 'Eliminar']) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-truck" aria-hidden="true"></i></div>
        <div class="es-title">Sin proveedores</div>
        <div class="es-msg">No hay registros para mostrar todavía.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>

<?= $this->element('excel_wizard/modals', [
    'module' => 'Providers',
    'entityName' => 'Proveedores',
    'downloadSlug' => 'proveedores',
    'importable' => true,
]) ?>

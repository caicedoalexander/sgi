<?php
$this->assign('title', 'Carpeta por Defecto: ' . $defaultFolder->name);
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Detalle de Carpeta por Defecto</span>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['default_folders']['can_edit'])): ?>
        <?= $this->Html->link('<i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $defaultFolder->id],
            ['class' => 'btn btn-primary btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
        <?php if (!empty($userPermissions['default_folders']['can_delete'])): ?>
        <?= $this->Form->postLink('<i class="bi bi-trash me-1" aria-hidden="true"></i>Eliminar',
            ['action' => 'delete', $defaultFolder->id],
            ['confirm' => '¿Está seguro?', 'class' => 'btn btn-danger btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
        <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
    </div>
</div>

<div class="spi-card">
    <div class="field-row">
        <span class="k">ID</span>
        <span class="v mono"><?= $this->Number->format($defaultFolder->id) ?></span>
    </div>
    <div class="field-row">
        <span class="k">Nombre</span>
        <span class="v"><?= h($defaultFolder->name) ?></span>
    </div>
    <div class="field-row">
        <span class="k">Orden</span>
        <span class="v mono"><?= $this->Number->format($defaultFolder->sort_order) ?></span>
    </div>
    <div class="field-row">
        <span class="k">Creado</span>
        <span class="v mono"><?= $defaultFolder->created?->format('d/m/Y H:i') ?></span>
    </div>
    <div class="field-row is-last">
        <span class="k">Modificado</span>
        <span class="v mono"><?= $defaultFolder->modified?->format('d/m/Y H:i') ?></span>
    </div>
</div>

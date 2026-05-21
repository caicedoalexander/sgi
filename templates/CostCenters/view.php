<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\CostCenter $costCenter
 */
$this->assign('title', 'Centro de Costos: ' . $costCenter->name);
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Detalle del Centro de Costos</span>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['cost_centers']['can_edit'])): ?>
        <?= $this->Html->link('<i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $costCenter->id],
            ['class' => 'btn btn-primary btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
        <?php if (!empty($userPermissions['cost_centers']['can_delete'])): ?>
        <?= $this->Form->postLink('<i class="bi bi-trash me-1" aria-hidden="true"></i>Eliminar',
            ['action' => 'delete', $costCenter->id],
            ['confirm' => '¿Está seguro?', 'class' => 'btn btn-danger btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
        <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
    </div>
</div>

<div class="sgi-card">
    <div class="field-row">
        <span class="k">ID</span>
        <span class="v mono"><?= $this->Number->format($costCenter->id) ?></span>
    </div>
    <div class="field-row">
        <span class="k">Código</span>
        <span class="v mono"><?= h($costCenter->code ?? '-') ?></span>
    </div>
    <div class="field-row">
        <span class="k">Nombre</span>
        <span class="v"><?= h($costCenter->name) ?></span>
    </div>
    <div class="field-row">
        <span class="k">Creado</span>
        <span class="v mono"><?= $costCenter->created?->format('d/m/Y H:i') ?></span>
    </div>
    <div class="field-row is-last">
        <span class="k">Modificado</span>
        <span class="v mono"><?= $costCenter->modified?->format('d/m/Y H:i') ?></span>
    </div>
</div>

<?php
$this->assign('title', 'Estado Civil: ' . $maritalStatus->name);
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Detalle</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
</div>

<div class="card card-primary">
<div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">ID</dt>
            <dd class="col-sm-9"><?= $this->Number->format($maritalStatus->id) ?></dd>
            <dt class="col-sm-3">Nombre</dt>
            <dd class="col-sm-9"><?= h($maritalStatus->name) ?></dd>
            <dt class="col-sm-3">Creado</dt>
            <dd class="col-sm-9"><?= $maritalStatus->created?->format('d/m/Y H:i') ?></dd>
            <dt class="col-sm-3">Modificado</dt>
            <dd class="col-sm-9"><?= $maritalStatus->modified?->format('d/m/Y H:i') ?></dd>
        </dl>
    </div>
    <div class="card-footer">
        <?php if (!empty($userPermissions['marital_statuses']['can_edit'])): ?>
        <?= $this->Html->link('<i class="bi bi-pencil me-1"></i>Editar', ['action' => 'edit', $maritalStatus->id], ['class' => 'btn btn-warning btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
        <?php if (!empty($userPermissions['marital_statuses']['can_delete'])): ?>
        <?= $this->Form->postLink('<i class="bi bi-trash me-1"></i>Eliminar', ['action' => 'delete', $maritalStatus->id], ['confirm' => '¿Está seguro?', 'class' => 'btn btn-danger btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
    </div>
</div>

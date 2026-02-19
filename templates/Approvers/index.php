<?php
/**
 * @var \App\View\AppView $this
 */
$this->assign('title', 'Aprobadores');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Aprobadores</h1>
    <?= $this->Html->link('<i class="bi bi-plus-lg me-1"></i>Nuevo Aprobador', ['action' => 'add'], ['class' => 'btn btn-primary', 'escape' => false]) ?>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Usuario</th>
                    <th>Centro de Operación</th>
                    <th>Activo</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($approvers as $approver): ?>
                <tr>
                    <td><?= $approver->id ?></td>
                    <td><?= $approver->hasValue('user') ? h($approver->user->full_name) : '' ?></td>
                    <td><?= $approver->hasValue('operation_center') ? h($approver->operation_center->name) : '<span class="text-muted">Todos</span>' ?></td>
                    <td>
                        <?php if ($approver->active): ?>
                            <span class="badge bg-success">Activo</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?= $this->Html->link('<i class="bi bi-pencil"></i>', ['action' => 'edit', $approver->id], ['class' => 'btn btn-sm btn-outline-warning', 'escape' => false]) ?>
                        <?= $this->Form->postLink('<i class="bi bi-trash"></i>', ['action' => 'delete', $approver->id], ['confirm' => '¿Eliminar este aprobador?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false]) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer">
        <small class="text-muted"><?= $this->Paginator->counter('Mostrando {{start}}-{{end}} de {{count}}') ?></small>
    </div>
</div>

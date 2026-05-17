<?php
/**
 * @var \App\View\AppView $this
 */
$this->assign('title', 'Aprobadores');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Aprobadores</span>
    <?php if (!empty($userPermissions['approvers']['can_create'])): ?>
    <?= $this->Html->link('<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Aprobador', ['action' => 'add'], ['class' => 'btn btn-primary', 'escape' => false]) ?>
    <?php endif; ?>
</div>

<div class="card card-primary">
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
                            <span class="pill pill-primary-soft">Activo</span>
                        <?php else: ?>
                            <span class="pill pill-secondary-soft">Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if (!empty($userPermissions['approvers']['can_edit'])): ?>
                        <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>', ['action' => 'edit', $approver->id], ['class' => 'btn btn-sm btn-outline-warning', 'escape' => false]) ?>
                        <?php endif; ?>
                        <?php if (!empty($userPermissions['approvers']['can_delete'])): ?>
                        <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>', ['action' => 'delete', $approver->id], ['confirm' => '¿Eliminar este aprobador?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false]) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $this->element('pagination') ?>
</div>

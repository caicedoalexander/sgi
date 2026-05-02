<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Role $role
 * @var array<string, array<string, bool>> $pipelineMatrix
 * @var array<string, string> $pipelineLabels
 * @var array<string, array<string, string>> $stepLabels
 */
$this->assign('title', 'Rol: ' . $role->name);
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Detalle del Rol</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
</div>

<div class="card card-primary mb-4">
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">ID</dt>
            <dd class="col-sm-9"><?= $this->Number->format($role->id) ?></dd>
            <dt class="col-sm-3">Nombre</dt>
            <dd class="col-sm-9"><?= h($role->name) ?></dd>
            <dt class="col-sm-3">Descripción</dt>
            <dd class="col-sm-9"><?= h($role->description) ?: '<span class="text-muted">—</span>' ?></dd>
            <dt class="col-sm-3">Creado</dt>
            <dd class="col-sm-9"><?= $role->created?->format('d/m/Y H:i') ?></dd>
            <dt class="col-sm-3">Modificado</dt>
            <dd class="col-sm-9"><?= $role->modified?->format('d/m/Y H:i') ?></dd>
        </dl>
    </div>
    <div class="card-footer">
        <?php if (!empty($userPermissions['roles']['can_edit'])): ?>
        <?= $this->Html->link('<i class="bi bi-pencil me-1"></i>Editar', ['action' => 'edit', $role->id], ['class' => 'btn btn-warning btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
        <?php if (!empty($userPermissions['roles']['can_delete'])): ?>
        <?= $this->Form->postLink('<i class="bi bi-trash me-1"></i>Eliminar', ['action' => 'delete', $role->id], ['confirm' => '¿Está seguro?', 'class' => 'btn btn-danger btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="card card-primary mb-4">
    <div class="card-body">
        <h6 class="text-muted mb-3"><i class="bi bi-diagram-3 me-1"></i>Permisos de Pipeline</h6>
        <?php foreach ($pipelineLabels as $pipeline => $pipelineLabel): ?>
            <div class="mb-3">
                <div class="fw-semibold mb-2"><?= h($pipelineLabel) ?></div>
                <ul class="list-unstyled small mb-0">
                <?php foreach ($stepLabels[$pipeline] ?? [] as $step => $stepLabel): ?>
                    <?php $allowed = !empty($pipelineMatrix[$pipeline][$step]); ?>
                    <li>
                        <i class="bi <?= $allowed ? 'bi-check-circle text-success' : 'bi-x-circle text-muted' ?> me-1"></i>
                        <?= h($stepLabel) ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!empty($role->users)): ?>
<div class="card card-primary">
    <div class="card-header"><h5 class="mb-0">Usuarios con este rol</h5></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Usuario</th>
                    <th>Nombre Completo</th>
                    <th>Email</th>
                    <th>Activo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($role->users as $user): ?>
                <tr>
                    <td><?= $this->Html->link(h($user->username), ['controller' => 'Users', 'action' => 'view', $user->id]) ?></td>
                    <td><?= h($user->full_name) ?></td>
                    <td><?= h($user->email) ?></td>
                    <td><?= $user->active ? '<span class="badge bg-success">Sí</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

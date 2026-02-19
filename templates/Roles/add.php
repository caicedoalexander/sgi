<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Role $role
 * @var array $modules
 */
$this->assign('title', 'Nuevo Rol');
?>
<div class="mb-4">
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>

<div class="card shadow-sm">
    <div class="card-header"><h5 class="mb-0">Nuevo Rol</h5></div>
    <div class="card-body">
        <?= $this->Form->create($role) ?>

        <div class="row mb-3">
            <div class="col-md-6">
                <?= $this->Form->control('name', ['class' => 'form-control', 'label' => ['text' => 'Nombre', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('description', ['class' => 'form-control', 'label' => ['text' => 'Descripción', 'class' => 'form-label']]) ?>
            </div>
        </div>

        <hr>
        <h6 class="text-muted mb-3"><i class="bi bi-shield-check me-1"></i>Permisos por Módulo</h6>

        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:35%">Módulo</th>
                        <th class="text-center">Ver</th>
                        <th class="text-center">Crear</th>
                        <th class="text-center">Editar</th>
                        <th class="text-center">Eliminar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($modules as $module => $label): ?>
                    <tr>
                        <td class="fw-semibold"><?= h($label) ?></td>
                        <?php foreach (['can_view', 'can_create', 'can_edit', 'can_delete'] as $key): ?>
                        <td class="text-center">
                            <input type="checkbox"
                                   class="form-check-input"
                                   name="permissions[<?= $module ?>][<?= $key ?>]"
                                   value="1">
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <button type="submit" class="btn btn-primary mt-2"><i class="bi bi-save me-1"></i>Guardar</button>
        <?= $this->Form->end() ?>
    </div>
</div>

<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Role $role
 * @var array $modules
 * @var array<string, string> $pipelineLabels
 * @var array<string, array<string, string>> $stepLabels
 */
$this->assign('title', 'Nuevo Rol');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nuevo Rol</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
</div>

<?= $this->Form->create($role) ?>

<div class="card card-primary">
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-6">
                <?= $this->Form->control('name', ['class' => 'form-control', 'label' => ['text' => 'Nombre', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('description', ['class' => 'form-control', 'label' => ['text' => 'Descripción', 'class' => 'form-label']]) ?>
            </div>
        </div>

        <hr>
        <h6 class="text-muted mb-3"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Permisos por Módulo</h6>

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
    </div>
</div>

<div class="card card-primary mt-3">
    <div class="card-body">
        <h6 class="text-muted mb-3"><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Permisos de Pipeline</h6>
        <p class="text-muted small mb-3">
            Cada checkbox autoriza al rol a operar el paso indicado: avanzar/regresar la pieza,
            editar los campos definidos para ese paso y ver la sección correspondiente del formulario.
        </p>

        <?php foreach ($pipelineLabels as $pipeline => $pipelineLabel): ?>
            <div class="mb-4">
                <div class="fw-semibold mb-2"><?= h($pipelineLabel) ?></div>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:70%">Paso</th>
                                <th class="text-center">Puede operar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stepLabels[$pipeline] ?? [] as $step => $stepLabel): ?>
                            <tr>
                                <td><?= h($stepLabel) ?></td>
                                <td class="text-center">
                                    <input type="checkbox"
                                           class="form-check-input"
                                           name="pipeline_permissions[<?= h($pipeline) ?>][<?= h($step) ?>]"
                                           value="1">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

        <button type="submit" class="btn btn-primary mt-2"><i class="bi bi-save me-1" aria-hidden="true"></i>Guardar</button>
    </div>
</div>

<?= $this->Form->end() ?>

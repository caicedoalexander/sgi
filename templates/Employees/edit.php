<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Employee $employee
 * @var array $employeeStatuses
 * @var array $positions
 * @var array $temporaryOrganizations
 */
$this->assign('title', 'Editar Empleado: ' . $employee->full_name);
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Empleado: <?= h($employee->full_name) ?></span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver', ['action' => 'view', $employee->id], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
</div>

<?= $this->Form->create($employee, ['type' => 'file']) ?>

<?= $this->element('Employees/form', ['mode' => 'edit']) ?>

<!-- Imagen de Perfil -->
<div class="card card-primary mb-4">
    <div class="card-body">
        <div class="col-md-3 mb-3">
            <label class="form-label">Imagen de Perfil</label>
            <?php if ($employee->profile_image): ?>
                <div class="mb-2">
                    <img src="<?= $this->Url->build('/' . $employee->profile_image) ?>"
                         alt="Perfil" style="width:80px;height:80px;object-fit:cover;">
                </div>
            <?php endif; ?>
            <input type="file" name="profile_image_file" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
            <small class="text-muted">Max 2MB. JPEG, PNG, GIF o WebP</small>
        </div>
    </div>
</div>

<button type="submit" class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Actualizar</button>
<?= $this->Form->end() ?>

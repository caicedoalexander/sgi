<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Employee $employee
 * @var array $employeeStatuses
 * @var array $positions
 * @var array $temporaryOrganizations
 */
$this->assign('title', 'Nuevo Empleado');
?>
<?= $this->element('cdn_autonumeric') ?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nuevo Empleado</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
</div>

<?= $this->Form->create($employee, ['type' => 'file']) ?>

<?= $this->element('Employees/form', ['mode' => 'add']) ?>

<!-- Imagen de Perfil -->
<div class="card card-primary mb-4">
    <div class="card-body">
        <div class="col-md-3 mb-3">
            <label class="form-label">Imagen de Perfil</label>
            <input type="file" name="profile_image_file" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
            <small class="text-muted">Max 2MB. JPEG, PNG, GIF o WebP</small>
        </div>
    </div>
</div>

<button type="submit" class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Guardar</button>
<?= $this->Form->end() ?>

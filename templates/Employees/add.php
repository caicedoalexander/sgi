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
<?= $this->element('cdn_select2') ?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Nuevo Empleado</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>

<?= $this->Form->create($employee, ['type' => 'file']) ?>

<?= $this->element('Employees/form', ['mode' => 'add']) ?>

<!-- Imagen de Perfil -->
<div class="spi-card mb-3">
    <div class="spi-label" style="margin-bottom:12px;">IMAGEN DE PERFIL</div>
    <div class="col-md-3 mb-3">
        <input type="file" name="profile_image_file" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
        <small class="text-muted">Max 2MB. JPEG, PNG, GIF o WebP</small>
    </div>
</div>

<button type="submit" class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Guardar</button>
<?= $this->Form->end() ?>

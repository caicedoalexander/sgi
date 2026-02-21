<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Employee> $employees
 */
$this->assign('title', 'Empleados');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Empleados</span>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg me-1"></i>Nuevo Empleado',
        ['action' => 'add'],
        ['class' => 'btn btn-primary', 'escape' => false]
    ) ?>
</div>

<?php $employeeList = iterator_to_array($employees); ?>

<?php if (empty($employeeList)): ?>
<div class="card">
    <div class="sgi-doc-empty">
        <i class="bi bi-people sgi-doc-empty-icon"></i>
        <div style="font-size:.875rem;font-weight:500;color:#999">Sin empleados registrados</div>
        <div style="font-size:.8rem;margin-top:.3rem">
            <?= $this->Html->link('Crear el primer empleado', ['action' => 'add'], ['class' => 'text-decoration-none', 'style' => 'color:var(--primary-color)']) ?>
        </div>
    </div>
</div>
<?php else: ?>

<div class="row g-3 mb-3">
    <?php foreach ($employeeList as $employee):
        $initials = mb_strtoupper(
            mb_substr($employee->first_name ?? '', 0, 1) .
            mb_substr($employee->last_name  ?? '', 0, 1)
        );
    ?>
    <div class="col-12 col-sm-6 col-md-4 col-xl-3">
        <div class="card sgi-employee-card clickable-row h-100"
             data-href="<?= $this->Url->build(['action' => 'view', $employee->id]) ?>">

            <!-- Cabecera: avatar + nombre + documento -->
            <div class="card-body d-flex align-items-start gap-3 pb-2">
                <div class="sgi-emp-avatar"><?= h($initials) ?></div>
                <div style="min-width:0">
                    <div class="sgi-emp-name"><?= h($employee->full_name) ?></div>
                    <div class="sgi-emp-doc"><?= h($employee->document_type . ' ' . $employee->document_number) ?></div>
                </div>
            </div>

            <!-- Meta: cargo + centro de operación + email -->
            <div class="card-body pt-0 pb-2" style="border-top:1px solid var(--border-color)">
                <?php if ($employee->has('position') && $employee->position): ?>
                <div class="mb-2">
                    <div class="sgi-emp-meta-label">Cargo</div>
                    <div class="sgi-emp-meta-value"><?= h($employee->position->name) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($employee->has('operation_center') && $employee->operation_center): ?>
                <div class="mb-2">
                    <div class="sgi-emp-meta-label">Centro de Operación</div>
                    <div class="sgi-emp-meta-value"><?= h($employee->operation_center->name) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($employee->email): ?>
                <div>
                    <div class="sgi-emp-meta-label">Correo</div>
                    <div class="sgi-emp-meta-value"><?= h($employee->email) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Footer: estado + acciones -->
            <div class="card-footer d-flex justify-content-between align-items-center px-3 py-2">
                <div class="d-flex gap-1 flex-wrap">
                    <?php if ($employee->has('employee_status') && $employee->employee_status): ?>
                        <span class="badge bg-info"><?= h($employee->employee_status->name) ?></span>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-1">
                    <?= $this->Html->link(
                        '<i class="bi bi-pencil"></i>',
                        ['action' => 'edit', $employee->id],
                        ['class' => 'btn btn-sm btn-outline-dark', 'escape' => false, 'title' => 'Editar']
                    ) ?>
                    <?= $this->Form->postLink(
                        '<i class="bi bi-trash"></i>',
                        ['action' => 'delete', $employee->id],
                        ['confirm' => '¿Está seguro de eliminar este empleado?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar']
                    ) ?>
                </div>
            </div>

        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Paginación -->
<div class="d-flex justify-content-between align-items-center">
    <small class="text-muted"><?= $this->Paginator->counter('Mostrando {{start}}–{{end}} de {{count}} empleados') ?></small>
    <nav>
        <ul class="pagination pagination-sm mb-0">
            <?= $this->Paginator->first('«', ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
            <?= $this->Paginator->prev('‹',  ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
            <?= $this->Paginator->numbers(   ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
            <?= $this->Paginator->next('›',  ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
            <?= $this->Paginator->last('»',  ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
        </ul>
    </nav>
</div>

<?php endif; ?>

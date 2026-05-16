<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Employee> $employees
 * @var \Cake\ORM\ResultSet $positions
 * @var \Cake\ORM\ResultSet $operationCenters
 * @var \Cake\ORM\ResultSet $employeeStatuses
 */
$this->assign('title', 'Empleados');

$query = $this->request->getQueryParams();
$hasFilters = !empty(array_filter($query, fn($v) => $v !== '' && $v !== null));
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Empleados</span>
    <div class="d-flex gap-2">
        <?= $this->element('excel_wizard/buttons', [
            'module' => 'Employees',
            'importable' => true,
            'canCreate' => !empty($userPermissions['employees']['can_create']),
        ]) ?>
        <?php if (!empty($userPermissions['employees']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Empleado',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Filters -->
<div class="sgi-search-bar mb-3">
    <?= $this->Form->create(null, ['type' => 'get', 'valueSources' => ['query']]) ?>
    <div class="d-flex gap-2">
        <div class="flex-grow-1">
            <?= $this->Form->control('search', [
                'label' => false,
                'type' => 'text',
                'class' => 'form-control',
                'placeholder' => 'Buscar por nombre, documento o correo…',
                'value' => $this->request->getQuery('search', ''),
            ]) ?>
        </div>
        <button type="submit" class="btn btn-primary" aria-label="Buscar"><i class="bi bi-search" aria-hidden="true"></i></button>
        <button type="button" class="btn btn-outline-dark" data-bs-toggle="collapse" data-bs-target="#employeeFilters" title="Filtros avanzados">
            <i class="bi bi-funnel" aria-hidden="true"></i>
        </button>
        <?php if ($hasFilters): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x-lg" aria-hidden="true"></i> Limpiar',
                ['action' => 'index'],
                ['class' => 'btn btn-outline-danger', 'escape' => false]
            ) ?>
        <?php endif; ?>
    </div>

    <div class="collapse <?= $hasFilters ? 'show' : '' ?>" id="employeeFilters">
        <div class="sgi-filters-section mt-2">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="sgi-filter-label" for="filter-position">Cargo</label>
                    <?= $this->Form->select('position_id', $positions, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('position_id', ''),
                        'id'    => 'filter-position',
                    ]) ?>
                </div>
                <div class="col-md-4">
                    <label class="sgi-filter-label" for="filter-opcenter">Centro de Operación</label>
                    <?= $this->Form->select('operation_center_id', $operationCenters, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('operation_center_id', ''),
                        'id'    => 'filter-opcenter',
                    ]) ?>
                </div>
                <div class="col-md-4">
                    <label class="sgi-filter-label" for="filter-status">Estado</label>
                    <?= $this->Form->select('status', [
                        \App\Constants\EmployeeStatusConstants::ACTIVO   => 'Activo',
                        \App\Constants\EmployeeStatusConstants::RETIRADO => 'Retirado',
                        'all'                                            => 'Todos',
                    ], [
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('status') ?: \App\Constants\EmployeeStatusConstants::ACTIVO,
                        'id'    => 'filter-status',
                    ]) ?>
                </div>
            </div>
        </div>
    </div>
    <?= $this->Form->end() ?>
</div>

<?php $employeeList = $employees->toArray(); ?>

<?php if (empty($employeeList)): ?>
<div class="card">
    <div class="sgi-doc-empty">
        <i class="bi bi-people sgi-doc-empty-icon" aria-hidden="true"></i>
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
            mb_substr($employee->last_name1  ?? '', 0, 1)
        );
    ?>
    <div class="col-12 col-sm-6 col-md-4 col-xl-3">
        <div class="card sgi-employee-card clickable-row h-100"
             data-href="<?= $this->Url->build(['action' => 'view', $employee->id]) ?>">

            <!-- Cabecera: avatar + nombre + documento -->
            <div class="card-body d-flex align-items-start gap-3 pb-2">
                <?php if ($employee->profile_image): ?>
                    <img src="<?= $this->Url->build('/' . $employee->profile_image) ?>"
                         alt="<?= h($employee->full_name) ?>"
                         class="sgi-emp-avatar"
                         style="object-fit:cover;">
                <?php else: ?>
                    <div class="sgi-emp-avatar" aria-hidden="true"><?= h($initials) ?></div>
                <?php endif; ?>
                <div style="min-width:0">
                    <div class="sgi-emp-name"><?= h($employee->full_name) ?></div>
                    <div class="sgi-emp-doc"><?= h($employee->document_type . ' ' . $employee->document_number) ?></div>
                </div>
            </div>

            <!-- Meta: cargo + centro de operación + email -->
            <div class="card-body pt-0 pb-2" style="border-top:1px solid var(--border-color)">
                <?php if ($employee->has('position') && $employee->position): ?>
                <div class="mb-2">
                    <div class="sgi-label">Cargo</div>
                    <div class="sgi-emp-meta-value"><?= h($employee->position->name) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($employee->has('operation_center') && $employee->operation_center): ?>
                <div class="mb-2">
                    <div class="sgi-label">Centro de Operación</div>
                    <div class="sgi-emp-meta-value"><?= h($employee->operation_center->name) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($employee->email): ?>
                <div>
                    <div class="sgi-label">Correo</div>
                    <div class="sgi-emp-meta-value"><?= h($employee->email) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Footer: estado + acciones -->
            <div class="card-footer d-flex justify-content-between align-items-center px-3 py-2">
                <div class="d-flex gap-1 flex-wrap">
                    <?php if (!empty($employee->status)): ?>
                        <span class="badge <?= $employee->isRetired() ? 'bg-danger' : 'bg-info' ?>">
                            <?= h(\App\Constants\EmployeeStatusConstants::STATUS_LABELS[$employee->status] ?? $employee->status) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($employee->current_novelty): ?>
                        <span class="badge bg-warning text-dark">
                            <i class="bi bi-journal-text me-1" aria-hidden="true"></i><?= h($employee->current_novelty->novelty_type->name ?? '') ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-1">
                    <?php if (!empty($userPermissions['employees']['can_edit'])): ?>
                    <?= $this->Html->link(
                        '<i class="bi bi-pencil" aria-hidden="true"></i>',
                        ['action' => 'edit', $employee->id],
                        ['class' => 'btn btn-sm btn-outline-dark', 'escape' => false, 'title' => 'Editar']
                    ) ?>
                    <?php endif; ?>
                    <?php if (!empty($userPermissions['employees']['can_delete'])): ?>
                    <?= $this->Form->postLink(
                        '<i class="bi bi-trash" aria-hidden="true"></i>',
                        ['action' => 'delete', $employee->id],
                        ['confirm' => '¿Está seguro de eliminar este empleado?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar']
                    ) ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <?= $this->element('pagination') ?>
</div>

<?php endif; ?>

<?php $this->Html->script($this->Url->build('/vendor/sortablejs/Sortable.min.js'), ['block' => true]) ?>

<?= $this->element('excel_wizard/modals', [
    'module' => 'Employees',
    'entityName' => 'Empleados',
    'downloadSlug' => 'empleados',
    'importable' => true,
]) ?>

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
        <button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#exportExcelModal">
            <i class="bi bi-upload me-1"></i>Exportar
        </button>
        <?php if (!empty($userPermissions['employees']['can_create'])): ?>
        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importExcelModal">
            <i class="bi bi-download me-1"></i>Importar
        </button>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1"></i>Nuevo Empleado',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- Export Modal -->
<div class="modal fade" id="exportExcelModal" tabindex="-1" data-module="Employees">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Exportar Empleados</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center mb-2">
                    <input type="checkbox" class="form-check-input me-2" id="exportSelectAll" checked>
                    <label class="form-check-label" for="exportSelectAll" style="font-size:.875rem;font-weight:600">Seleccionar todos</label>
                </div>
                <div id="exportLoading" style="display:none" class="text-center py-3">
                    <span class="spinner-border spinner-border-sm me-1"></span>Cargando campos...
                </div>
                <div id="exportFieldList" style="max-height:400px;overflow-y:auto;border:1px solid var(--border-color);border-radius:4px"></div>
                <div id="exportError" class="text-danger mt-2" style="display:none;font-size:.825rem"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="exportBtn">
                    <i class="bi bi-download me-1"></i>Exportar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importExcelModal" tabindex="-1" data-module="Employees">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Importar Empleados desde Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Step 1: Upload -->
                <div id="importStep1">
                    <p style="font-size:.85rem;color:#666;">
                        Seleccione un archivo <code>.xlsx</code> para importar. El sistema detectará las columnas automáticamente y le permitirá configurar el mapeo.
                    </p>
                    <p style="font-size:.8rem;color:#999;">
                        <i class="bi bi-info-circle me-1"></i>Tip: Exporte primero para obtener la plantilla con las columnas correctas.
                    </p>
                    <input type="file" id="importFileInput" class="form-control" accept=".xlsx">
                </div>

                <!-- Step 2: Mapping -->
                <div id="importStep2" style="display:none">
                    <p style="font-size:.85rem;color:#666;margin-bottom:.75rem">
                        Configure el mapeo de columnas. Las columnas reconocidas se asignan automáticamente.
                    </p>
                    <div style="max-height:400px;overflow-y:auto">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th style="width:40px"></th>
                                    <th style="font-size:.8rem">Columna del archivo</th>
                                    <th style="font-size:.8rem">Campo del sistema</th>
                                    <th style="width:40px"></th>
                                </tr>
                            </thead>
                            <tbody id="importMappingBody"></tbody>
                        </table>
                    </div>
                    <div id="importRequiredIndicator" style="display:none"></div>
                    <div id="importMappingError" class="alert alert-danger py-2 px-3 mt-2" style="display:none;font-size:.825rem"></div>
                </div>

                <!-- Step 3: Results -->
                <div id="importStep3" style="display:none">
                    <div id="importResults"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-outline-secondary" id="importBackBtn" style="display:none">
                    <i class="bi bi-arrow-left me-1"></i>Volver
                </button>
                <button type="button" class="btn btn-primary" id="importUploadBtn">
                    <i class="bi bi-upload me-1"></i>Subir
                </button>
                <button type="button" class="btn btn-primary" id="importProcessBtn" style="display:none">
                    <i class="bi bi-check-lg me-1"></i>Importar
                </button>
                <button type="button" class="btn btn-primary" id="importCloseBtn" style="display:none">
                    Cerrar
                </button>
            </div>
        </div>
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
        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
        <button type="button" class="btn btn-outline-dark" data-bs-toggle="collapse" data-bs-target="#employeeFilters" title="Filtros avanzados">
            <i class="bi bi-funnel"></i>
        </button>
        <?php if ($hasFilters): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x-lg"></i> Limpiar',
                ['action' => 'index'],
                ['class' => 'btn btn-outline-danger', 'escape' => false]
            ) ?>
        <?php endif; ?>
    </div>

    <div class="collapse <?= $hasFilters ? 'show' : '' ?>" id="employeeFilters">
        <div class="sgi-filters-section mt-2">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="sgi-filter-label">Cargo</label>
                    <?= $this->Form->select('position_id', $positions, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('position_id', ''),
                    ]) ?>
                </div>
                <div class="col-md-4">
                    <label class="sgi-filter-label">Centro de Operación</label>
                    <?= $this->Form->select('operation_center_id', $operationCenters, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('operation_center_id', ''),
                    ]) ?>
                </div>
                <div class="col-md-4">
                    <label class="sgi-filter-label">Estado</label>
                    <?= $this->Form->select('employee_status_id', $employeeStatuses, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('employee_status_id', ''),
                    ]) ?>
                </div>
            </div>
        </div>
    </div>
    <?= $this->Form->end() ?>
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
                    <div class="sgi-emp-avatar"><?= h($initials) ?></div>
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
                    <?php if ($employee->current_novelty): ?>
                        <span class="badge bg-warning text-dark">
                            <i class="bi bi-journal-text me-1"></i><?= h($employee->current_novelty->novelty_type->name ?? '') ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-1">
                    <?php if (!empty($userPermissions['employees']['can_edit'])): ?>
                    <?= $this->Html->link(
                        '<i class="bi bi-pencil"></i>',
                        ['action' => 'edit', $employee->id],
                        ['class' => 'btn btn-sm btn-outline-dark', 'escape' => false, 'title' => 'Editar']
                    ) ?>
                    <?php endif; ?>
                    <?php if (!empty($userPermissions['employees']['can_delete'])): ?>
                    <?= $this->Form->postLink(
                        '<i class="bi bi-trash"></i>',
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

<?php $this->Html->script('https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js', ['block' => true]) ?>
<?php $this->Html->script('excel-mapper', ['block' => true]) ?>

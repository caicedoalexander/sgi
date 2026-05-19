<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Employee $employee
 * @var iterable $folders
 * @var \App\Model\Entity\User|null $currentUser
 * @var array<\App\Model\Entity\Employee> $navEmployees
 * @var string $navStatus
 * @var string $navSearch
 * @var string $navPositionId
 * @var string $navOperationCenterId
 * @var iterable $positions
 * @var iterable $operationCenters
 */

use App\Constants\EmployeeStatusConstants;
use App\Constants\NoveltyConstants;

$this->assign('title', 'Empleado: ' . $employee->full_name);

$initials = mb_strtoupper(
    mb_substr($employee->first_name ?? '', 0, 1) .
    mb_substr($employee->last_name1  ?? '', 0, 1)
);

$totalDocs = 0;
foreach ($folders as $folder) {
    $totalDocs += count($folder->employee_documents);
    foreach ($folder->child_folders as $sf) {
        $totalDocs += count($sf->employee_documents);
    }
}

$novedades = $employee->employee_novelties ?? [];
$noveltyCount = count($novedades);
$historyCount = count($employee->employee_histories ?? []);
$obsCount = count($employee->employee_observations ?? []);
$currentUser = $this->getRequest()->getAttribute('identity');

$statusPills = [
    EmployeeStatusConstants::ACTIVO   => ['class' => 'pill-info-soft', 'dot' => 'var(--info-color)'],
    EmployeeStatusConstants::RETIRADO => ['class' => 'pill-danger-soft', 'dot' => 'var(--danger-color)'],
];
$statusPill = $statusPills[$employee->status] ?? ['class' => 'pill-muted', 'dot' => 'var(--text-faint)'];

$noveltyPills = [
    'pendiente'  => 'pill-warning-soft',
    'aprobado'   => 'pill-primary-soft',
    'rechazado'  => 'pill-danger-soft',
];
$scheduleLabels = NoveltyConstants::SCHEDULE_LABELS;

// Tabs del navegador (mismo set que /employees/index).
$navTabs = [
    [EmployeeStatusConstants::ACTIVO,   'Activos'],
    [EmployeeStatusConstants::RETIRADO, 'Retirados'],
    ['all',                             'Todos'],
];
// Query string base preservado al cambiar tabs/filtros/seleccionar empleado.
$navBaseQuery = array_filter([
    'search' => $navSearch,
    'position_id' => $navPositionId,
    'operation_center_id' => $navOperationCenterId,
], fn($v) => $v !== '' && $v !== null);

// Count de filtros avanzados activos (para el badge del trigger).
$navAdvancedFilterCount = ($navPositionId !== '' ? 1 : 0)
    + ($navOperationCenterId !== '' ? 1 : 0);
$navAdvancedFiltersOpen = $navAdvancedFilterCount > 0;

$navTabUrl = function (string $status) use ($employee, $navBaseQuery) {
    return ['action' => 'view', $employee->id, '?' => $navBaseQuery + ['status' => $status]];
};
$navStatusPills = [
    EmployeeStatusConstants::ACTIVO   => 'pill-info-soft',
    EmployeeStatusConstants::RETIRADO => 'pill-danger-soft',
];
?>

<div class="sgi-master-detail">

<!-- ═════════ LEFT NAV ═════════ -->
<aside class="sgi-md-left">
    <div class="sgi-md-left-head">
        <div class="d-flex justify-content-between align-items-start mb-2 gap-2">
            <div>
                <div class="sgi-title-card">Empleados</div>
                <div class="sgi-body-faint mt-1">
                    <?= $this->Paginator->counter([
                        'model' => 'nav',
                        'format' => '{{count}} mostrados',
                    ]) ?>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-1 justify-content-end">
                <?= $this->element('excel_wizard/buttons', [
                    'module' => 'Employees',
                    'importable' => true,
                    'canCreate' => !empty($userPermissions['employees']['can_create']),
                ]) ?>
                <?php if (!empty($userPermissions['employees']['can_create'])): ?>
                <?= $this->Html->link(
                    '<i class="bi bi-plus-lg" aria-hidden="true"></i>Nuevo',
                    ['action' => 'add'],
                    ['class' => 'btn btn-primary btn-sm', 'escape' => false]
                ) ?>
                <?php endif; ?>
            </div>
        </div>

        <form method="get" class="sgi-md-search mb-2" role="search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="text" name="search"
                   value="<?= h($navSearch) ?>"
                   placeholder="Buscar por nombre, CC o correo…"
                   aria-label="Buscar empleados"
                   autocomplete="off">
            <input type="hidden" name="status" value="<?= h($navStatus) ?>">
        </form>

        <form method="get" class="mb-2">
            <input type="hidden" name="search" value="<?= h($navSearch) ?>">
            <input type="hidden" name="status" value="<?= h($navStatus) ?>">

            <button type="button"
                    class="btn btn-ghost-card btn-sm w-100 d-flex justify-content-between align-items-center"
                    data-bs-toggle="collapse"
                    data-bs-target="#empNavFilters"
                    aria-expanded="<?= $navAdvancedFiltersOpen ? 'true' : 'false' ?>"
                    aria-controls="empNavFilters">
                <span>
                    <i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtros
                    <?php if ($navAdvancedFilterCount > 0): ?>
                        · <span class="sgi-fg-primary"><?= $navAdvancedFilterCount ?></span>
                    <?php endif; ?>
                </span>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </button>

            <div class="collapse <?= $navAdvancedFiltersOpen ? 'show' : '' ?> mt-2" id="empNavFilters">
                <div class="mb-2">
                    <label class="sgi-label" for="emp-nav-position">Cargo</label>
                    <?= $this->Form->select('position_id', $positions, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $navPositionId,
                        'id' => 'emp-nav-position',
                        'onchange' => 'this.form.submit()',
                    ]) ?>
                </div>
                <div class="mb-1">
                    <label class="sgi-label" for="emp-nav-opcenter">Centro de Operación</label>
                    <?= $this->Form->select('operation_center_id', $operationCenters, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $navOperationCenterId,
                        'id' => 'emp-nav-opcenter',
                        'onchange' => 'this.form.submit()',
                    ]) ?>
                </div>
            </div>
        </form>

        <div class="sgi-status-tabs" role="tablist" aria-label="Filtrar por estado">
            <?php foreach ($navTabs as [$status, $label]):
                $isActive = ($navStatus === $status);
                $color = match ($status) {
                    EmployeeStatusConstants::RETIRADO => 'var(--danger-color)',
                    EmployeeStatusConstants::ACTIVO   => 'var(--info-text)',
                    default                            => 'var(--primary-color)',
                };
            ?>
                <?= $this->Html->link(
                    ($isActive ? '<span class="sgi-status-tab-dot" style="background:' . $color . ';"></span>' : '') . h($label),
                    $navTabUrl($status),
                    [
                        'class' => 'sgi-status-tab' . ($isActive ? ' is-active' : ''),
                        'escape' => false,
                        'role' => 'tab',
                        'aria-selected' => $isActive ? 'true' : 'false',
                        'style' => $isActive ? 'color:' . $color : '',
                    ]
                ) ?>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="sgi-md-left-list">
        <?php foreach ($navEmployees as $nav):
            $navInitials = mb_strtoupper(
                mb_substr($nav->first_name ?? '', 0, 1) .
                mb_substr($nav->last_name1  ?? '', 0, 1)
            );
            $isSelected = $nav->id === $employee->id;
            $navStatusClass = $navStatusPills[$nav->status] ?? 'pill-muted';
        ?>
        <a class="sgi-md-row<?= $isSelected ? ' is-selected' : '' ?>"
           href="<?= $this->Url->build(['action' => 'view', $nav->id, '?' => $navBaseQuery + ['status' => $navStatus]]) ?>">
            <?php if ($nav->profile_image): ?>
                <img src="<?= $this->Url->build('/' . $nav->profile_image) ?>" alt="" class="sgi-emp-avatar" style="object-fit:cover;width:34px;height:34px;">
            <?php else: ?>
                <div class="sgi-emp-avatar" style="width:34px;height:34px;font-size:.8rem;" aria-hidden="true"><?= h($navInitials) ?></div>
            <?php endif; ?>
            <div class="sgi-md-row-main">
                <div class="sgi-md-row-name"><?= h(strtoupper($nav->full_name)) ?></div>
                <div class="sgi-md-row-sub">
                    <span class="mono">CC <?= h($nav->document_number) ?></span>
                    <?php if ($nav->has('operation_center') && $nav->operation_center): ?>
                        <span class="sgi-emp-sep">·</span>
                        <span><?= h(strtoupper($nav->operation_center->name)) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($nav->current_novelty): ?>
                <span class="sgi-md-row-flag" title="Con novedad activa">1</span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>

        <?php if (count($navEmployees) === 0): ?>
        <div class="sgi-doc-empty">
            <i class="bi bi-search sgi-doc-empty-icon" aria-hidden="true"></i>
            <div class="sgi-fg-muted">Sin resultados</div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (count($navEmployees) > 0): ?>
    <div class="sgi-md-pagination">
        <?= $this->Paginator->counter([
            'model' => 'nav',
            'format' => '{{start}}–{{end}} de {{count}}',
        ]) ?>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?= $this->Paginator->prev('‹', [
                    'model' => 'nav',
                    'templates' => [
                        'prevActive'   => '<li class="page-item"><a class="page-link" rel="prev" href="{{url}}">{{text}}</a></li>',
                        'prevDisabled' => '<li class="page-item disabled"><span class="page-link">{{text}}</span></li>',
                    ],
                ]) ?>
                <?= $this->Paginator->next('›', [
                    'model' => 'nav',
                    'templates' => [
                        'nextActive'   => '<li class="page-item"><a class="page-link" rel="next" href="{{url}}">{{text}}</a></li>',
                        'nextDisabled' => '<li class="page-item disabled"><span class="page-link">{{text}}</span></li>',
                    ],
                ]) ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</aside>

<!-- ═════════ RIGHT DETAIL ═════════ -->
<section class="sgi-md-right">

<!-- Profile header card -->
<div class="sgi-profile-header card mb-3">
    <?php if ($employee->profile_image): ?>
        <img src="<?= $this->Url->build('/' . $employee->profile_image) ?>"
             alt=""
             class="sgi-profile-avatar-lg"
             style="object-fit:cover;">
    <?php else: ?>
        <div class="sgi-profile-avatar-lg" aria-hidden="true"><?= h($initials) ?></div>
    <?php endif; ?>

    <div class="sgi-profile-identity">
        <div class="sgi-profile-name-row">
            <h1 class="sgi-profile-name"><?= h($employee->full_name) ?></h1>
            <?php if (!empty($employee->status)): ?>
                <span class="pill <?= h($statusPill['class']) ?>">
                    <span class="dot" style="background:<?= h($statusPill['dot']) ?>;"></span>
                    <?= h(strtoupper(EmployeeStatusConstants::STATUS_LABELS[$employee->status] ?? $employee->status)) ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($currentNovelty)): ?>
                <span class="pill pill-warning-soft">
                    <i class="bi bi-journal-text" aria-hidden="true"></i>
                    <?= h(strtoupper($currentNovelty->novelty_type->name ?? 'NOVEDAD')) ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="sgi-profile-meta">
            <?php if ($employee->document_number): ?>
                <span><i class="bi bi-person" aria-hidden="true"></i><span class="mono"><?= h($employee->document_type . ' ' . $employee->document_number) ?></span></span>
            <?php endif; ?>
            <?php if ($employee->has('position') && $employee->position): ?>
                <span><i class="bi bi-building" aria-hidden="true"></i><?= h(strtoupper($employee->position->name)) ?></span>
            <?php endif; ?>
            <?php if ($employee->has('operation_center') && $employee->operation_center): ?>
                <span><i class="bi bi-geo-alt" aria-hidden="true"></i><?= h(strtoupper($employee->operation_center->name)) ?></span>
            <?php endif; ?>
            <?php if ($employee->email): ?>
                <span><i class="bi bi-envelope" aria-hidden="true"></i><?= h($employee->email) ?></span>
            <?php endif; ?>
            <?php if ($employee->hire_date): ?>
                <span><i class="bi bi-calendar3" aria-hidden="true"></i>Ingreso: <span class="mono"><?= $employee->hire_date->format('d/m/Y') ?></span></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="sgi-profile-actions">
        <?php if (!empty($userPermissions['employee_novelties']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg" aria-hidden="true"></i>Novedad',
            ['controller' => 'EmployeeNovelties', 'action' => 'add', '?' => ['employee_id' => $employee->id]],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
        <?php endif; ?>
        <?php if (!empty($userPermissions['employees']['can_create'])): ?>
        <button type="button" class="btn btn-ghost-card" data-bs-toggle="modal" data-bs-target="#newFolderModal">
            <i class="bi bi-folder-plus" aria-hidden="true"></i>Carpeta
        </button>
        <?php endif; ?>
        <?php if (!empty($userPermissions['employees']['can_edit'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $employee->id],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- Tabs -->
<ul class="nav sgi-tabs-underline mb-3" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-docs" type="button" role="tab">
            <i class="bi bi-file-earmark-text" aria-hidden="true"></i> Documentos
            <?php if ($totalDocs > 0): ?>
                <span class="sgi-tab-badge"><?= $totalDocs ?></span>
            <?php endif; ?>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-perfil" type="button" role="tab">
            <i class="bi bi-person" aria-hidden="true"></i> Perfil
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-contrato" type="button" role="tab">
            <i class="bi bi-file-text" aria-hidden="true"></i> Contrato
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-novedades" type="button" role="tab">
            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Novedades
            <?php if ($noveltyCount > 0): ?>
                <span class="sgi-tab-badge"><?= $noveltyCount ?></span>
            <?php endif; ?>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-historial" type="button" role="tab">
            <i class="bi bi-clock-history" aria-hidden="true"></i> Historial
            <?php if ($historyCount > 0): ?>
                <span class="sgi-tab-badge"><?= $historyCount ?></span>
            <?php endif; ?>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-observaciones" type="button" role="tab">
            <i class="bi bi-chat-square-dots" aria-hidden="true"></i> Observaciones
            <?php if ($obsCount > 0): ?>
                <span class="sgi-tab-badge"><?= $obsCount ?></span>
            <?php endif; ?>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ───── Tab: Documentos ───── -->
    <div class="tab-pane fade show active" id="tab-docs" role="tabpanel">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <div class="sgi-title-card">Documentos</div>
                    <div class="sgi-body-faint mt-1"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?> en <?= count($folders) ?> carpetas</div>
                </div>
                <?php if (!empty($userPermissions['employees']['can_create'])): ?>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocModal">
                    <i class="bi bi-upload" aria-hidden="true"></i>Subir documento
                </button>
                <?php endif; ?>
            </div>

            <?php if ($folders->isEmpty()): ?>
                <div class="sgi-doc-empty">
                    <i class="bi bi-folder-x sgi-doc-empty-icon" aria-hidden="true"></i>
                    <div class="sgi-fg-muted">Sin carpetas ni documentos</div>
                    <div class="sgi-body-faint mt-1">Crea una carpeta o sube un documento para comenzar</div>
                </div>
            <?php else: ?>
                <div class="accordion accordion-flush" id="foldersAccordion">
                    <?php foreach ($folders as $i => $folder): ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button <?= $i > 0 ? 'collapsed' : '' ?>" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#folder-<?= $folder->id ?>">
                                <i class="bi bi-folder-fill me-2 sgi-fg-secondary" aria-hidden="true"></i>
                                <span><?= h($folder->name) ?></span>
                                <span class="sgi-folder-count ms-2"><?= count($folder->employee_documents) ?></span>
                            </button>
                        </h2>
                        <div id="folder-<?= $folder->id ?>"
                             class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>"
                             data-bs-parent="#foldersAccordion">
                            <div class="accordion-body">
                                <?php if (!empty($folder->employee_documents)): ?>
                                <?= $this->element('employee_documents_table', [
                                    'docs'       => $folder->employee_documents,
                                    'employeeId' => $employee->id,
                                    'canDelete'  => !empty($userPermissions['employees']['can_delete']),
                                    'showHeader' => true,
                                ]) ?>
                                <?php else: ?>
                                <div class="sgi-doc-empty-sm"><i class="bi bi-file-earmark me-1" aria-hidden="true"></i>Carpeta vacía</div>
                                <?php endif; ?>

                                <?php if (!empty($folder->child_folders)): ?>
                                <div class="sgi-subfolder-section">
                                    <?php foreach ($folder->child_folders as $subfolder): ?>
                                    <div class="sgi-subfolder-item">
                                        <div class="sgi-subfolder-header">
                                            <i class="bi bi-folder-fill sgi-fg-secondary" aria-hidden="true"></i>
                                            <?= h($subfolder->name) ?>
                                            <span class="sgi-folder-count ms-1"><?= count($subfolder->employee_documents) ?></span>
                                        </div>
                                        <?php if (!empty($subfolder->employee_documents)): ?>
                                        <?= $this->element('employee_documents_table', [
                                            'docs'       => $subfolder->employee_documents,
                                            'employeeId' => $employee->id,
                                            'canDelete'  => !empty($userPermissions['employees']['can_delete']),
                                            'showHeader' => false,
                                        ]) ?>
                                        <?php else: ?>
                                        <div class="sgi-doc-empty-sm"><i class="bi bi-file-earmark me-1" aria-hidden="true"></i>Subcarpeta vacía</div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ───── Tab: Perfil ───── -->
    <div class="tab-pane fade" id="tab-perfil" role="tabpanel">
        <div class="card p-4">
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="sgi-label mb-3">Datos personales</div>
                    <?php if ($employee->birth_date): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Fecha Nacimiento</span>
                        <span class="sgi-data-value mono"><?= $employee->birth_date->format('d/m/Y') ?><?php if ($employee->age !== null): ?> <span class="sgi-fg-faint">(<?= $employee->age ?> años)</span><?php endif; ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($employee->gender): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Género</span>
                        <span class="sgi-data-value"><?= h($employee->gender) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($employee->has('marital_status') && $employee->marital_status): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Estado Civil</span>
                        <span class="sgi-data-value"><?= h($employee->marital_status->name) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($employee->has('education_level') && $employee->education_level): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Nivel Educativo</span>
                        <span class="sgi-data-value"><?= h($employee->education_level->name) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="col-md-6">
                    <div class="sgi-label mb-3">Contacto</div>
                    <?php if ($employee->email): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Correo</span>
                        <span class="sgi-data-value"><?= h($employee->email) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($employee->phone): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Teléfono</span>
                        <span class="sgi-data-value mono"><?= h($employee->phone) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($employee->city): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Ciudad</span>
                        <span class="sgi-data-value"><?= h($employee->city) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($employee->address): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Dirección</span>
                        <span class="sgi-data-value"><?= h($employee->address) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ───── Tab: Contrato ───── -->
    <div class="tab-pane fade" id="tab-contrato" role="tabpanel">
        <div class="card p-4">
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="sgi-label mb-3">Datos Laborales</div>
                    <?php if ($employee->has('position') && $employee->position): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Cargo</span>
                        <span class="sgi-data-value"><?= h($employee->position->name) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($employee->has('supervisor_position') && $employee->supervisor_position): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Jefe Inmediato</span>
                        <span class="sgi-data-value"><?= h($employee->supervisor_position->name) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($employee->has('cost_center') && $employee->cost_center): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Centro de Costos</span>
                        <span class="sgi-data-value"><?= h($employee->cost_center->name) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($employee->has('temporary_organization') && $employee->temporary_organization): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Organización Temporal</span>
                        <span class="sgi-data-value"><?= h($employee->temporary_organization->name) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($employee->vest_number): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">N. Chaleco</span>
                        <span class="sgi-data-value mono"><?= h($employee->vest_number) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="col-md-6">
                    <div class="sgi-label mb-3">Vinculación</div>
                    <?php if ($employee->contract_type): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Tipo de Contrato</span>
                        <span class="sgi-data-value"><?= h($employee->contract_type) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($employee->hire_date): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Fecha Ingreso</span>
                        <span class="sgi-data-value mono"><?= $employee->hire_date->format('d/m/Y') ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($employee->termination_date): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Fecha Retiro</span>
                        <span class="sgi-data-value mono"><?= $employee->termination_date->format('d/m/Y') ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($employee->salary): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Salario</span>
                        <span class="sgi-data-value mono">$ <?= $this->Number->format($employee->salary, ['places' => 0]) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ───── Tab: Novedades ───── -->
    <div class="tab-pane fade" id="tab-novedades" role="tabpanel">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <div class="sgi-title-card">Novedades</div>
                    <div class="sgi-body-faint mt-1"><?= $noveltyCount ?> registro<?= $noveltyCount !== 1 ? 's' : '' ?></div>
                </div>
                <?php if (!empty($userPermissions['employee_novelties']['can_create'])): ?>
                <?= $this->Html->link(
                    '<i class="bi bi-plus-lg" aria-hidden="true"></i>Nueva Novedad',
                    ['controller' => 'EmployeeNovelties', 'action' => 'add', '?' => ['employee_id' => $employee->id]],
                    ['class' => 'btn btn-primary', 'escape' => false]
                ) ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($novedades)): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Fecha Permiso</th>
                            <th>Horario</th>
                            <th>Remunerado</th>
                            <th>Estado</th>
                            <th>Registrado por</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($novedades as $nov):
                            $pillClass = $noveltyPills[$nov->status] ?? 'pill-muted';
                        ?>
                        <tr class="clickable-row" data-href="<?= $this->Url->build(['controller' => 'EmployeeNovelties', 'action' => 'edit', $nov->id]) ?>">
                            <td><?= h($nov->novelty_type->name ?? '—') ?></td>
                            <td class="mono"><?= $nov->permission_date?->format('d/m/Y') ?: '—' ?></td>
                            <td><?= h($scheduleLabels[$nov->schedule_type] ?? $nov->schedule_type) ?></td>
                            <td>
                                <?php if ($nov->is_paid): ?>
                                    <span class="pill pill-primary-soft">SÍ</span>
                                <?php else: ?>
                                    <span class="pill pill-muted">NO</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="pill <?= h($pillClass) ?>"><?= h(strtoupper($nov->status)) ?></span></td>
                            <td class="sgi-fg-muted"><?= h($nov->registered_by_user->full_name ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="sgi-doc-empty">
                <i class="bi bi-check-circle sgi-doc-empty-icon" aria-hidden="true"></i>
                <div class="sgi-fg-muted">Sin novedades registradas</div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ───── Tab: Historial ───── -->
    <div class="tab-pane fade" id="tab-historial" role="tabpanel">
        <div class="card">
            <div class="card-header">
                <div class="sgi-title-card">Historial de Cambios</div>
                <div class="sgi-body-faint mt-1"><?= $historyCount ?> evento<?= $historyCount !== 1 ? 's' : '' ?></div>
            </div>
            <?php if (!empty($employee->employee_histories)): ?>
            <div class="table-responsive" style="max-height:480px;overflow-y:auto;">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Usuario</th>
                            <th>Campo</th>
                            <th>Valor Anterior</th>
                            <th>Valor Nuevo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employee->employee_histories as $history): ?>
                        <tr>
                            <td class="mono sgi-fg-muted" style="white-space:nowrap;"><?= $history->created ? $history->created->format('d/m/Y H:i') : '' ?></td>
                            <td class="sgi-fg-muted"><?= $history->hasValue('user') ? h($history->user->full_name) : '' ?></td>
                            <td><?= h($fieldLabels[$history->field_changed] ?? $history->field_changed) ?></td>
                            <td class="sgi-fg-faint"><?= h($history->old_value) ?: '—' ?></td>
                            <td class="fw-semibold"><?= h($history->new_value) ?: '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="sgi-doc-empty">
                <i class="bi bi-clock-history sgi-doc-empty-icon" aria-hidden="true"></i>
                <div class="sgi-fg-muted">Sin cambios registrados</div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ───── Tab: Observaciones ───── -->
    <div class="tab-pane fade" id="tab-observaciones" role="tabpanel">
        <div class="card sgi-obs-card">
            <div class="card-header">
                <div class="sgi-title-card">Observaciones</div>
                <div class="sgi-body-faint mt-1">
                    <span id="obs-count-label"><?= $obsCount ?> comentario<?= $obsCount !== 1 ? 's' : '' ?></span>
                </div>
            </div>

            <div id="obs-chat-scroll" class="sgi-obs-list">
                <?php foreach ($employee->employee_observations ?? [] as $obs): ?>
                    <?= $this->element('observation_bubble', [
                        'observation' => $obs,
                        'isMine' => $currentUser && $obs->user_id === $currentUser->id,
                    ]) ?>
                <?php endforeach; ?>
            </div>

            <div id="obs-empty-state" class="sgi-obs-empty" <?= $obsCount > 0 ? 'hidden' : '' ?>>
                <i class="bi bi-chat-square-dots" aria-hidden="true"></i>
                <span>Sin observaciones aún</span>
            </div>

            <?php if (!empty($userPermissions['employees']['can_edit'])): ?>
            <div class="sgi-obs-input-bar">
                <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $employee->id], 'id' => 'obs-form']) ?>
                <div class="sgi-obs-compose">
                    <textarea name="message" class="auto-resize" rows="1"
                              placeholder="Escriba una observación..."></textarea>
                    <button type="submit" class="sgi-obs-compose-send" title="Enviar">
                        <i class="bi bi-send" aria-hidden="true"></i>
                    </button>
                </div>
                <?= $this->Form->end() ?>
            </div>
            <?php endif; ?>
        </div>
        <span id="obs-count" hidden><?= $obsCount ?></span>
    </div>

</div>

<!-- Modal: Nueva Carpeta -->
<div class="modal fade" id="newFolderModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <?= $this->Form->create(null, ['url' => ['action' => 'addFolder', $employee->id]]) ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-folder-plus me-2" aria-hidden="true"></i>Nueva Carpeta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <?= $this->Form->control('name', ['class' => 'form-control', 'label' => ['text' => 'Nombre de la Carpeta', 'class' => 'form-label'], 'required' => true, 'placeholder' => 'Ej. Contratos, Certificados...']) ?>
                </div>
                <div class="mb-3">
                    <?php
                    $folderOptions = [];
                    foreach ($folders as $f) { $folderOptions[$f->id] = $f->name; }
                    ?>
                    <?= $this->Form->control('parent_id', ['class' => 'form-select', 'label' => ['text' => 'Carpeta Padre (opcional)', 'class' => 'form-label'], 'options' => $folderOptions, 'empty' => '— Raíz —']) ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-folder-plus" aria-hidden="true"></i>Crear Carpeta</button>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>

<!-- Modal: Subir Documento -->
<div class="modal fade" id="uploadDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <?= $this->Form->create(null, ['url' => ['action' => 'uploadDocument', $employee->id], 'type' => 'file']) ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2" aria-hidden="true"></i>Subir Documento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <?php
                    $allFolderOptions = [];
                    foreach ($folders as $f) {
                        $allFolderOptions[$f->id] = $f->name;
                        foreach ($f->child_folders as $cf) {
                            $allFolderOptions[$cf->id] = '— ' . $cf->name;
                        }
                    }
                    ?>
                    <?= $this->Form->control('employee_folder_id', ['class' => 'form-select', 'label' => ['text' => 'Carpeta de Destino', 'class' => 'form-label'], 'options' => $allFolderOptions, 'required' => true, 'id' => 'upload-folder-select']) ?>
                </div>
                <div class="mb-3">
                    <?= $this->Form->control('file', ['type' => 'file', 'class' => 'form-control', 'label' => ['text' => 'Archivo', 'class' => 'form-label'], 'required' => true, 'accept' => '.pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.txt']) ?>
                    <div class="form-text">Máximo <?= h(\App\Constants\UploadConstants::MAX_BYTES_LABEL) ?> — PDF, imágenes, Word, Excel o texto.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-upload" aria-hidden="true"></i>Subir</button>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>

</section>
</div>

<?= $this->element('excel_wizard/modals', [
    'module' => 'Employees',
    'entityName' => 'Empleados',
    'downloadSlug' => 'empleados',
    'importable' => true,
]) ?>

<?= $this->element('observation_chat_init') ?>

<script>
// Auto-submit search del navegador izquierdo con debounce
(function(){
    var form = document.querySelector('.sgi-md-search');
    if (!form) return;
    var input = form.querySelector('input[name="search"]');
    var t;
    input.addEventListener('input', function(){
        clearTimeout(t);
        t = setTimeout(function(){ form.submit(); }, 400);
    });
})();
</script>

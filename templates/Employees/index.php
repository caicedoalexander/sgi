<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Employee> $employees
 * @var \Cake\ORM\ResultSet $positions
 * @var \Cake\ORM\ResultSet $operationCenters
 * @var \Cake\ORM\ResultSet $employeeStatuses
 */

use App\Constants\EmployeeStatusConstants;

$this->assign('title', 'Empleados');

$query = $this->request->getQueryParams();
$activeStatus = $this->request->getQuery('status') ?: EmployeeStatusConstants::ACTIVO;
$hasFilters = !empty(array_filter(
    array_diff_key($query, ['status' => true]),
    fn($v) => $v !== '' && $v !== null
));
$filterCount = count(array_filter(array_diff_key($query, ['status' => true, 'page' => true]), fn($v) => $v !== '' && $v !== null));

$employeeList = $employees->toArray();
$totalShown = count($employeeList);
$withNovelty = 0;
foreach ($employeeList as $e) {
    if ($e->current_novelty) { $withNovelty++; }
}

$baseQuery = array_diff_key($query, ['status' => true, 'page' => true]);
$tabUrl = function (string $status) use ($baseQuery) {
    return ['action' => 'index', '?' => $baseQuery + ['status' => $status]];
};
$tabs = [
    [EmployeeStatusConstants::ACTIVO,   'Activos'],
    [EmployeeStatusConstants::RETIRADO, 'Retirados'],
    ['all',                             'Todos'],
];

$statusPills = [
    EmployeeStatusConstants::ACTIVO   => 'pill-info-soft',
    EmployeeStatusConstants::RETIRADO => 'pill-danger-soft',
];
?>

<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div>
        <span class="sgi-page-title">Empleados</span>
        <div class="sgi-body-faint mt-1" style="font-size:var(--fs-body-sm);">
            <span class="sgi-fg-muted"><?= $this->Paginator->counter('{{count}} empleados') ?></span>
            <?php if ($withNovelty > 0): ?>
                · <span class="sgi-fg-secondary"><?= $withNovelty ?> con novedad activa</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <?= $this->element('excel_wizard/buttons', [
            'module' => 'Employees',
            'importable' => true,
            'canCreate' => !empty($userPermissions['employees']['can_create']),
        ]) ?>
        <?php if (!empty($userPermissions['employees']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg" aria-hidden="true"></i>Nuevo Empleado',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Filters -->
<?= $this->Form->create(null, ['type' => 'get', 'valueSources' => ['query']]) ?>
<input type="hidden" name="status" value="<?= h($activeStatus) ?>">
<div class="d-flex gap-2 align-items-stretch mb-3">
    <div class="sgi-search-bar flex-grow-1">
        <div class="sgi-input-icon w-100">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="text" name="search"
                   class="form-control"
                   placeholder="Buscar por nombre, documento o correo…"
                   value="<?= h($this->request->getQuery('search', '')) ?>"
                   aria-label="Buscar empleados">
        </div>
    </div>
    <button type="button" class="btn btn-ghost-card sgi-filters-trigger"
            data-bs-toggle="collapse" data-bs-target="#employeeFilters" aria-label="Filtros avanzados">
        <i class="bi bi-funnel" aria-hidden="true"></i>
        <span>Filtros<?php if ($filterCount > 0): ?> · <span class="sgi-fg-primary"><?= $filterCount ?></span><?php endif; ?></span>
    </button>
    <?php if ($hasFilters): ?>
        <?= $this->Html->link(
            '<i class="bi bi-x-lg" aria-hidden="true"></i> Limpiar',
            ['action' => 'index', '?' => ['status' => $activeStatus]],
            ['class' => 'btn btn-ghost-card sgi-fg-danger', 'escape' => false]
        ) ?>
    <?php endif; ?>
</div>

<div class="collapse <?= $hasFilters ? 'show' : '' ?> mb-3" id="employeeFilters">
    <div class="card p-3">
        <div class="row g-2">
            <div class="col-md-6">
                <label class="sgi-filter-label" for="filter-position">Cargo</label>
                <?= $this->Form->select('position_id', $positions, [
                    'empty' => 'Todos',
                    'class' => 'form-select form-select-sm',
                    'value' => $this->request->getQuery('position_id', ''),
                    'id'    => 'filter-position',
                ]) ?>
            </div>
            <div class="col-md-6">
                <label class="sgi-filter-label" for="filter-opcenter">Centro de Operación</label>
                <?= $this->Form->select('operation_center_id', $operationCenters, [
                    'empty' => 'Todos',
                    'class' => 'form-select form-select-sm',
                    'value' => $this->request->getQuery('operation_center_id', ''),
                    'id'    => 'filter-opcenter',
                ]) ?>
            </div>
        </div>
    </div>
</div>
<?= $this->Form->end() ?>

<!-- Tabs por estado -->
<div class="sgi-status-tabs mb-3" role="tablist" aria-label="Filtrar por estado">
    <?php foreach ($tabs as [$status, $label]):
        $isActive = ($activeStatus === $status);
        $color = match ($status) {
            EmployeeStatusConstants::RETIRADO => 'var(--danger-color)',
            EmployeeStatusConstants::ACTIVO   => 'var(--info-text)',
            default                            => 'var(--primary-color)',
        };
    ?>
        <?= $this->Html->link(
            ($isActive ? '<span class="sgi-status-tab-dot" style="background:' . $color . ';"></span>' : '') . h($label),
            $tabUrl($status),
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

<?php if (empty($employeeList)): ?>
<div class="card">
    <div class="sgi-doc-empty">
        <i class="bi bi-people sgi-doc-empty-icon" aria-hidden="true"></i>
        <div class="sgi-fg-muted">Sin empleados que coincidan con los filtros</div>
        <?php if (!empty($userPermissions['employees']['can_create'])): ?>
        <div class="mt-2">
            <?= $this->Html->link('Crear un empleado', ['action' => 'add'], ['class' => 'sgi-fg-primary text-decoration-none fw-semibold']) ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<div class="card">
    <div class="sgi-emp-list">
        <?php foreach ($employeeList as $employee):
            $initials = mb_strtoupper(
                mb_substr($employee->first_name ?? '', 0, 1) .
                mb_substr($employee->last_name1  ?? '', 0, 1)
            );
            $pillClass = $statusPills[$employee->status] ?? 'pill-muted';
        ?>
        <a class="sgi-emp-row clickable-row"
           href="<?= $this->Url->build(['action' => 'view', $employee->id]) ?>">

            <?php if ($employee->profile_image): ?>
                <img src="<?= $this->Url->build('/' . $employee->profile_image) ?>"
                     alt=""
                     class="sgi-emp-avatar"
                     style="object-fit:cover;">
            <?php else: ?>
                <div class="sgi-emp-avatar" aria-hidden="true"><?= h($initials) ?></div>
            <?php endif; ?>

            <div class="sgi-emp-row-main">
                <div class="sgi-emp-name"><?= h($employee->full_name) ?></div>
                <div class="sgi-emp-row-sub">
                    <span class="mono"><?= h($employee->document_type . ' ' . $employee->document_number) ?></span>
                    <?php if ($employee->has('position') && $employee->position): ?>
                        <span class="sgi-emp-sep">·</span>
                        <span><?= h($employee->position->name) ?></span>
                    <?php endif; ?>
                    <?php if ($employee->has('operation_center') && $employee->operation_center): ?>
                        <span class="sgi-emp-sep">·</span>
                        <span class="d-inline-flex align-items-center gap-1">
                            <i class="bi bi-geo-alt" aria-hidden="true"></i><?= h($employee->operation_center->name) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sgi-emp-row-status">
                <?php if (!empty($employee->status)): ?>
                    <span class="pill <?= h($pillClass) ?>">
                        <?= h(strtoupper(EmployeeStatusConstants::STATUS_LABELS[$employee->status] ?? $employee->status)) ?>
                    </span>
                <?php endif; ?>
                <?php if ($employee->current_novelty): ?>
                    <span class="pill pill-warning-soft" title="<?= h($employee->current_novelty->novelty_type->name ?? 'Novedad') ?>">
                        <i class="bi bi-journal-text" aria-hidden="true"></i>
                        <?= h(strtoupper($employee->current_novelty->novelty_type->name ?? 'NOVEDAD')) ?>
                    </span>
                <?php endif; ?>
            </div>

            <i class="bi bi-chevron-right sgi-fg-faint sgi-emp-row-chevron" aria-hidden="true"></i>
        </a>
        <?php endforeach; ?>
    </div>

    <?= $this->element('pagination') ?>
</div>

<?php endif; ?>

<?= $this->element('excel_wizard/modals', [
    'module' => 'Employees',
    'entityName' => 'Empleados',
    'downloadSlug' => 'empleados',
    'importable' => true,
]) ?>

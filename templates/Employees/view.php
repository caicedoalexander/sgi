<?php
/**
 * Empleados — Vista master-detail (directorio izquierdo + perfil/gestor docs).
 * Rediseño v2: grid 320px 1fr, clases canónicas del sistema de diseño.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Employee $employee
 * @var iterable $folders
 * @var \App\Model\Entity\Employee|null $currentNovelty
 * @var array<\App\Model\Entity\Employee> $navEmployees
 * @var string $navStatus
 * @var string $navSearch
 * @var string $navPositionId
 * @var string $navOperationCenterId
 * @var iterable $positions
 * @var iterable $operationCenters
 * @var array<string,string> $fieldLabels
 */

use App\Constants\EmployeeStatusConstants;
use App\Constants\NoveltyConstants;

$this->assign('title', 'Empleado: ' . $employee->full_name);

$initials = mb_strtoupper(
    mb_substr($employee->first_name ?? '', 0, 1) .
    mb_substr($employee->last_name1 ?? '', 0, 1)
);

// ─── Conteo de documentos (carpetas raíz + subcarpetas) ─────────────
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

// ─── Pill del estado del empleado ───────────────────────────────────
$statusPills = [
    EmployeeStatusConstants::ACTIVO   => ['class' => 'pill-info-soft', 'dot' => 'var(--info-color)'],
    EmployeeStatusConstants::RETIRADO => ['class' => 'pill-danger-soft', 'dot' => 'var(--danger-color)'],
];
$statusPill = $statusPills[$employee->status] ?? ['class' => 'pill-muted', 'dot' => 'var(--text-faint)'];

$noveltyPills = [
    'pendiente' => 'pill-warning-soft',
    'aprobado'  => 'pill-primary-soft',
    'rechazado' => 'pill-danger-soft',
];
$scheduleLabels = NoveltyConstants::SCHEDULE_LABELS;

// ─── Tabs de estado del directorio (mismo set que /employees/index) ─
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
$navTabColor = fn(string $status) => match ($status) {
    EmployeeStatusConstants::RETIRADO => 'var(--danger-color)',
    EmployeeStatusConstants::ACTIVO   => 'var(--info-text)',
    default                           => 'var(--primary-color)',
};
?>

<div style="height:calc(100vh - 3rem);min-width:0;display:grid;grid-template-columns:320px 1fr;gap:0;overflow:hidden;align-items:stretch;">

<!-- ═════════ COLUMNA IZQUIERDA · DIRECTORIO ═════════ -->
<aside style="display:flex;flex-direction:column;overflow:hidden;background:#fff;">

    <div style="padding:16px 16px 10px;">
        <div class="d-flex justify-content-between align-items-start gap-2" style="margin-bottom:12px;">
            <div class="min-w-0">
                <div style="font-size:15px;font-weight:700;color:var(--text-strong);">Empleados</div>
                <div style="font-size:10.5px;color:var(--text-faint);margin-top:2px;">
                    <?= $this->Paginator->counter('{{count}} en el directorio', ['model' => 'nav']) ?>
                </div>
            </div>
            <?php if (!empty($userPermissions['employees']['can_create'])): ?>
            <?= $this->Html->link(
                '<i class="bi bi-plus-lg" aria-hidden="true"></i>Nuevo',
                ['action' => 'add'],
                ['class' => 'btn btn-primary btn-sm', 'escape' => false]
            ) ?>
            <?php endif; ?>
        </div>

        <!-- Search box -->
        <form method="get" class="emp-nav-search input" role="search"
              style="height:34px;padding:0 12px;background:var(--bg-subtle);outline:none;">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="text" name="search"
                   value="<?= h($navSearch) ?>"
                   placeholder="Buscar por nombre, CC o correo…"
                   aria-label="Buscar empleados"
                   autocomplete="off">
            <?php if ($navSearch !== ''): ?>
            <a href="<?= $this->Url->build(['action' => 'view', $employee->id, '?' => array_filter([
                    'status' => $navStatus,
                    'position_id' => $navPositionId,
                    'operation_center_id' => $navOperationCenterId,
                ], fn($v) => $v !== '' && $v !== null)]) ?>"
               style="display:flex;color:var(--text-faint);" title="Limpiar búsqueda">
                <i class="bi bi-x" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
            <input type="hidden" name="status" value="<?= h($navStatus) ?>">
        </form>
    </div>

    <!-- Chips de estado -->
    <div style="display:flex;gap:4px;padding:0 16px 8px;">
        <?php foreach ($navTabs as [$status, $label]):
            $isActive = ($navStatus === $status);
        ?>
        <?= $this->Html->link(
            ($isActive ? '<span class="dot" style="background:' . $navTabColor($status) . ';"></span>' : '') . h($label),
            $navTabUrl($status),
            [
                'class' => 'chip' . ($isActive ? ' is-active' : ''),
                'escape' => false,
                'role' => 'tab',
                'aria-selected' => $isActive ? 'true' : 'false',
                'style' => 'padding:4px 10px;font-size:10.5px;'
                    . ($isActive ? 'color:' . $navTabColor($status) . ';' : ''),
            ]
        ) ?>
        <?php endforeach; ?>
    </div>

    <!-- Filtros avanzados (cargo / centro) -->
    <div style="padding:0 16px 8px;">
        <form method="get">
            <input type="hidden" name="search" value="<?= h($navSearch) ?>">
            <input type="hidden" name="status" value="<?= h($navStatus) ?>">

            <button type="button"
                    class="btn btn-default btn-sm w-100 d-flex justify-content-between align-items-center"
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
    </div>

    <!-- Lista scrollable de empleados -->
    <div style="flex:1;min-height:0;overflow:auto;border-top:1px solid var(--rule);">
        <?php foreach ($navEmployees as $nav):
            $navInitials = mb_strtoupper(
                mb_substr($nav->first_name ?? '', 0, 1) .
                mb_substr($nav->last_name1 ?? '', 0, 1)
            );
            $isSelected = $nav->id === $employee->id;
            $hasNovelty = (bool)$nav->current_novelty;
        ?>
        <a href="<?= $this->Url->build(['action' => 'view', $nav->id, '?' => $navBaseQuery + ['status' => $navStatus]]) ?>"
           style="display:flex;align-items:center;gap:10px;padding:10px 16px;text-decoration:none;
                  border-left:3px solid <?= $isSelected ? 'var(--primary-color)' : 'transparent' ?>;
                  background:<?= $isSelected ? 'var(--primary-soft)' : 'transparent' ?>;
                  transition:background var(--t-fast) ease;">
            <?php if ($nav->profile_image): ?>
                <img src="<?= $this->Url->build('/' . $nav->profile_image) ?>" alt=""
                     class="av av-md is-circle" style="object-fit:cover;">
            <?php else: ?>
                <span class="av av-md" aria-hidden="true"><?= h($navInitials) ?></span>
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
                <div style="font-size:12px;font-weight:700;color:var(--text-strong);
                            overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= h(strtoupper($nav->full_name)) ?>
                </div>
                <div style="font-size:10px;color:var(--text-faint);margin-top:2px;display:flex;
                            align-items:center;gap:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <span class="mono">CC <?= h($nav->document_number) ?></span>
                    <?php if ($nav->has('operation_center') && $nav->operation_center): ?>
                        <span>·</span>
                        <span style="overflow:hidden;text-overflow:ellipsis;"><?= h(strtoupper($nav->operation_center->name)) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($hasNovelty): ?>
            <span title="Con novedad activa"
                  style="min-width:22px;height:22px;background:var(--warning-color);color:#5c4a08;
                         font-size:9.5px;font-weight:700;display:flex;align-items:center;justify-content:center;
                         padding:0 6px;border-radius:var(--radius-sm);flex-shrink:0;">1</span>
            <?php else: ?>
            <span title="Sin novedades activas"
                  style="min-width:22px;height:22px;background:var(--primary-color);color:#fff;
                         display:flex;align-items:center;justify-content:center;
                         border-radius:var(--radius-sm);flex-shrink:0;">
                <i class="bi bi-check" style="font-size:13px;" aria-hidden="true"></i>
            </span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>

        <?php if (count($navEmployees) === 0): ?>
        <div class="empty-state" style="padding:48px 16px;">
            <div class="es-icon es-icon-neutral">
                <i class="bi bi-search" aria-hidden="true"></i>
            </div>
            <div class="es-title">Sin resultados</div>
            <div class="es-msg">Ajusta la búsqueda o los filtros del directorio.</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Footer del directorio: contador + paginación + import/export -->
    <?php if (count($navEmployees) > 0): ?>
    <div style="padding:10px 16px;background:var(--bg-subtle);display:flex;
                justify-content:space-between;align-items:center;gap:8px;">
        <span style="font-size:11px;color:var(--text-muted);">
            <?= $this->Paginator->counter('{{start}}–{{end}} de {{count}}', ['model' => 'nav']) ?>
        </span>
        <nav class="d-flex align-items-center gap-1">
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
            <button type="button" class="btn-icon"
                    data-bs-toggle="modal" data-bs-target="#exportExcelModal"
                    title="Exportar a Excel">
                <i class="bi bi-box-arrow-up" aria-hidden="true"></i>
            </button>
            <?php if (!empty($userPermissions['employees']['can_create'])): ?>
            <button type="button" class="btn-icon"
                    data-bs-toggle="modal" data-bs-target="#importExcelModal"
                    title="Importar desde Excel">
                <i class="bi bi-download" aria-hidden="true"></i>
            </button>
            <?php endif; ?>
        </nav>
    </div>
    <?php endif; ?>
</aside>

<!-- ═════════ COLUMNA DERECHA · DETALLE ═════════ -->
<section style="overflow:auto;padding:20px 24px;background:var(--background-color);
                display:flex;flex-direction:column;gap:14px;">

    <!-- Header card -->
    <div class="sgi-card" style="display:flex;align-items:center;gap:16px;">
        <?php if ($employee->profile_image): ?>
            <img src="<?= $this->Url->build('/' . $employee->profile_image) ?>" alt=""
                 class="av av-xl is-circle" style="object-fit:cover;">
        <?php else: ?>
            <span class="av av-xl" aria-hidden="true"><?= h($initials) ?></span>
        <?php endif; ?>

        <div style="flex:1;min-width:0;">
            <div class="d-flex align-items-center flex-wrap" style="gap:8px;margin-bottom:6px;">
                <h1 style="font-size:18px;font-weight:700;color:var(--text-strong);letter-spacing:-0.2px;margin:0;">
                    <?= h($employee->full_name) ?>
                </h1>
                <?php if (!empty($employee->status)): ?>
                <span class="pill <?= h($statusPill['class']) ?>">
                    <span class="dot" style="background:<?= h($statusPill['dot']) ?>;"></span>
                    <?= h(strtoupper(EmployeeStatusConstants::STATUS_LABELS[$employee->status] ?? $employee->status)) ?>
                </span>
                <?php endif; ?>
                <?php if (!empty($currentNovelty)): ?>
                <span class="pill pill-warning-soft">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                    <?= h(strtoupper($currentNovelty->novelty_type->name ?? 'NOVEDAD')) ?>
                </span>
                <?php endif; ?>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:14px;font-size:12px;color:var(--text-muted);">
                <?php if ($employee->document_number): ?>
                <span style="display:inline-flex;align-items:center;gap:5px;">
                    <i class="bi bi-person" style="font-size:11px;" aria-hidden="true"></i>
                    <span class="mono"><?= h($employee->document_type . ' ' . $employee->document_number) ?></span>
                </span>
                <?php endif; ?>
                <?php if ($employee->has('position') && $employee->position): ?>
                <span style="display:inline-flex;align-items:center;gap:5px;">
                    <i class="bi bi-building" style="font-size:11px;" aria-hidden="true"></i>
                    <?= h(strtoupper($employee->position->name)) ?>
                </span>
                <?php endif; ?>
                <?php if ($employee->has('operation_center') && $employee->operation_center): ?>
                <span style="display:inline-flex;align-items:center;gap:5px;">
                    <i class="bi bi-geo-alt" style="font-size:11px;" aria-hidden="true"></i>
                    <?= h(strtoupper($employee->operation_center->name)) ?>
                </span>
                <?php endif; ?>
                <?php if ($employee->email): ?>
                <span style="display:inline-flex;align-items:center;gap:5px;">
                    <i class="bi bi-envelope" style="font-size:11px;" aria-hidden="true"></i>
                    <?= h($employee->email) ?>
                </span>
                <?php endif; ?>
                <?php if ($employee->hire_date): ?>
                <span style="display:inline-flex;align-items:center;gap:5px;">
                    <i class="bi bi-calendar3" style="font-size:11px;" aria-hidden="true"></i>
                    Ingreso: <span class="mono"><?= $employee->hire_date->format('d/m/Y') ?></span>
                </span>
                <?php endif; ?>
            </div>
        </div>

        <div style="display:flex;gap:6px;flex-shrink:0;">
            <?php if (!empty($userPermissions['employee_novelties']['can_create'])): ?>
            <?= $this->Html->link(
                '<i class="bi bi-plus-lg" aria-hidden="true"></i>Novedad',
                ['controller' => 'EmployeeNovelties', 'action' => 'add', '?' => ['employee_id' => $employee->id]],
                ['class' => 'btn btn-default btn-sm', 'escape' => false]
            ) ?>
            <?php endif; ?>
            <?php if (!empty($userPermissions['employees']['can_create'])): ?>
            <button type="button" class="btn btn-default btn-sm"
                    data-bs-toggle="modal" data-bs-target="#newFolderModal">
                <i class="bi bi-download" aria-hidden="true"></i>Carpeta
            </button>
            <?php endif; ?>
            <?php if (!empty($userPermissions['employees']['can_edit'])): ?>
            <?= $this->Html->link(
                '<i class="bi bi-pencil" aria-hidden="true"></i>Editar',
                ['action' => 'edit', $employee->id],
                ['class' => 'btn btn-secondary btn-sm', 'escape' => false]
            ) ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav tabs" role="tablist" style="padding-left:4px;">
        <li class="nav-item" role="presentation">
            <button class="tab is-active" data-bs-toggle="tab" data-bs-target="#tab-docs" type="button" role="tab">
                <i class="bi bi-file-earmark-text" aria-hidden="true"></i> Documentos
                <?php if ($totalDocs > 0): ?>
                    <span class="tab-badge"><?= $totalDocs ?></span>
                <?php endif; ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="tab" data-bs-toggle="tab" data-bs-target="#tab-perfil" type="button" role="tab">
                <i class="bi bi-person" aria-hidden="true"></i> Perfil
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="tab" data-bs-toggle="tab" data-bs-target="#tab-contrato" type="button" role="tab">
                <i class="bi bi-file-earmark" aria-hidden="true"></i> Contrato
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="tab" data-bs-toggle="tab" data-bs-target="#tab-novedades" type="button" role="tab">
                <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Novedades
                <?php if ($noveltyCount > 0): ?>
                    <span class="tab-badge"><?= $noveltyCount ?></span>
                <?php endif; ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="tab" data-bs-toggle="tab" data-bs-target="#tab-historial" type="button" role="tab">
                <i class="bi bi-clock-history" aria-hidden="true"></i> Historial
                <?php if ($historyCount > 0): ?>
                    <span class="tab-badge"><?= $historyCount ?></span>
                <?php endif; ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="tab" data-bs-toggle="tab" data-bs-target="#tab-observaciones" type="button" role="tab">
                <i class="bi bi-chat-square-text" aria-hidden="true"></i> Observaciones
                <?php if ($obsCount > 0): ?>
                    <span class="tab-badge"><?= $obsCount ?></span>
                <?php endif; ?>
            </button>
        </li>
    </ul>

    <div class="tab-content">

        <!-- ───── Tab: Documentos ───── -->
        <div class="tab-pane fade show active" id="tab-docs" role="tabpanel">
            <?php if (($folders instanceof \Cake\Datasource\ResultSetInterface ? $folders->isEmpty() : count($folders) === 0)): ?>
                <div class="sgi-card">
                    <div class="empty-state" style="padding:60px 20px;">
                        <div class="es-icon es-icon-neutral">
                            <i class="bi bi-folder" aria-hidden="true"></i>
                        </div>
                        <div class="es-title">Sin carpetas ni documentos</div>
                        <div class="es-msg">Crea una carpeta o sube un documento para comenzar.</div>
                    </div>
                </div>
            <?php else: ?>

                <!-- Card de completitud documental -->
                <div class="sgi-card" style="margin-bottom:14px;">
                    <div class="d-flex justify-content-between align-items-start flex-wrap"
                         style="gap:16px;margin-bottom:12px;">
                        <div>
                            <div class="sgi-label" style="margin-bottom:4px;">Completitud Documental</div>
                            <div style="display:flex;align-items:baseline;gap:6px;">
                                <span class="mono" style="font-size:28px;font-weight:800;color:var(--primary-color);letter-spacing:-0.7px;">
                                    <?= $totalDocs ?><span style="color:var(--text-faint);font-weight:500;">/<?= $totalDocs ?></span>
                                </span>
                                <span style="font-size:13px;color:var(--text-muted);font-weight:600;">· <?= $totalDocs > 0 ? 100 : 0 ?>%</span>
                            </div>
                        </div>
                        <div style="display:flex;gap:18px;font-size:11.5px;color:var(--text-default);">
                            <div style="display:flex;align-items:center;gap:6px;">
                                <span style="width:10px;height:10px;background:var(--primary-color);border-radius:2px;"></span>
                                <span><strong><?= $totalDocs ?></strong> cargados</span>
                            </div>
                            <div style="display:flex;align-items:center;gap:6px;">
                                <span style="width:10px;height:10px;background:var(--warning-color);border-radius:2px;"></span>
                                <span><strong>0</strong> por vencer</span>
                            </div>
                            <div style="display:flex;align-items:center;gap:6px;">
                                <span style="width:10px;height:10px;background:var(--danger-color);border-radius:2px;"></span>
                                <span><strong>0</strong> faltantes</span>
                            </div>
                        </div>
                    </div>
                    <div style="display:flex;height:8px;gap:2px;background:var(--bg-subtle);">
                        <div style="flex:1;background:var(--primary-color);"></div>
                    </div>
                </div>

                <!-- Card árbol + archivos -->
                <div class="sgi-card" style="padding:0;overflow:hidden;">
                    <!-- Toolbar -->
                    <div style="padding:14px 18px;display:flex;justify-content:space-between;
                                align-items:center;gap:12px;border-bottom:1px solid var(--rule);">
                        <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                            <div style="font-size:13px;font-weight:700;color:var(--text-strong);">Documentos</div>
                            <span style="color:var(--text-disabled);">·</span>
                            <div class="mono" style="font-size:11.5px;color:var(--text-faint);">
                                / <?= h($employee->first_name) ?>
                            </div>
                        </div>
                        <?php if (!empty($userPermissions['employees']['can_create'])): ?>
                        <button type="button" class="btn btn-primary btn-sm"
                                data-bs-toggle="modal" data-bs-target="#uploadDocModal">
                            <i class="bi bi-upload" aria-hidden="true"></i>Subir documento
                        </button>
                        <?php endif; ?>
                    </div>

                    <div style="display:grid;grid-template-columns:260px 1fr;min-height:420px;">
                        <!-- ── Árbol de carpetas (izquierda) ── -->
                        <div style="background:var(--bg-subtle);padding:12px 0;
                                    border-right:1px solid var(--rule);font-size:12px;">
                            <!-- Nodo raíz -->
                            <div style="display:flex;align-items:center;gap:8px;padding:7px 14px;
                                        color:var(--primary-color);font-weight:700;background:var(--primary-soft);">
                                <i class="bi bi-chevron-down" style="font-size:11px;" aria-hidden="true"></i>
                                <i class="bi bi-folder2-open" style="font-size:13px;" aria-hidden="true"></i>
                                <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= h($employee->first_name . ' ' . ($employee->last_name1 ?? '')) ?>
                                </span>
                                <span class="mono" style="font-size:9.5px;color:var(--text-faint);font-weight:600;">
                                    <?= $totalDocs ?>
                                </span>
                            </div>

                            <!-- Carpetas -->
                            <?php foreach ($folders as $folder):
                                $folderDocCount = count($folder->employee_documents);
                                foreach ($folder->child_folders as $sf) {
                                    $folderDocCount += count($sf->employee_documents);
                                }
                            ?>
                            <a href="#folder-section-<?= $folder->id ?>"
                               style="display:flex;align-items:center;gap:8px;padding:7px 14px 7px 24px;
                                      text-decoration:none;color:var(--text-default);font-weight:500;
                                      transition:background var(--t-fast) ease;"
                               onmouseover="this.style.background='var(--bg-muted)'"
                               onmouseout="this.style.background='transparent'">
                                <i class="bi bi-folder" style="font-size:13px;color:var(--secondary-color);flex-shrink:0;" aria-hidden="true"></i>
                                <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= h($folder->name) ?>
                                </span>
                                <span class="mono" style="font-size:9.5px;color:var(--text-faint);font-weight:600;flex-shrink:0;">
                                    <?= $folderDocCount ?>
                                </span>
                            </a>
                            <?php endforeach; ?>
                        </div>

                        <!-- ── Lista de archivos (derecha) ── -->
                        <div style="min-width:0;display:flex;flex-direction:column;">
                            <!-- Header de columnas -->
                            <div style="display:grid;grid-template-columns:2fr 1fr 96px;padding:10px 18px;
                                        background:var(--bg-muted);font-size:9.5px;font-weight:700;
                                        color:var(--text-faint);letter-spacing:0.7px;text-transform:uppercase;
                                        gap:12px;align-items:center;border-bottom:1px solid var(--rule);">
                                <span>Documento</span>
                                <span>Cargado</span>
                                <span style="text-align:right;">Acciones</span>
                            </div>

                            <div style="flex:1;overflow:auto;">
                                <?php foreach ($folders as $folder): ?>
                                <div id="folder-section-<?= $folder->id ?>">
                                    <!-- Encabezado de carpeta -->
                                    <div style="display:flex;align-items:center;gap:8px;padding:9px 18px;
                                                background:var(--bg-subtle);border-bottom:1px solid var(--rule);">
                                        <i class="bi bi-folder2-open" style="font-size:13px;color:var(--secondary-color);" aria-hidden="true"></i>
                                        <span style="font-size:12px;font-weight:700;color:var(--text-strong);">
                                            <?= h($folder->name) ?>
                                        </span>
                                        <span class="mono" style="font-size:9.5px;color:var(--text-faint);font-weight:600;">
                                            <?= count($folder->employee_documents) ?>
                                        </span>
                                    </div>

                                    <?php if (!empty($folder->employee_documents)): ?>
                                        <?= $this->element('employee_documents_table', [
                                            'docs'       => $folder->employee_documents,
                                            'employeeId' => $employee->id,
                                            'canDelete'  => !empty($userPermissions['employees']['can_delete']),
                                            'showHeader' => false,
                                        ]) ?>
                                    <?php else: ?>
                                        <div style="padding:14px 18px;font-size:11.5px;color:var(--text-faint);
                                                    font-style:italic;border-bottom:1px solid var(--rule);">
                                            <i class="bi bi-file-earmark me-1" aria-hidden="true"></i>Carpeta vacía
                                        </div>
                                    <?php endif; ?>

                                    <?php foreach ($folder->child_folders as $subfolder): ?>
                                    <div style="display:flex;align-items:center;gap:8px;padding:8px 18px 8px 34px;
                                                background:var(--bg-subtle);border-bottom:1px solid var(--rule);">
                                        <i class="bi bi-folder" style="font-size:12px;color:var(--secondary-color);" aria-hidden="true"></i>
                                        <span style="font-size:11.5px;font-weight:600;color:var(--text-muted);">
                                            <?= h($subfolder->name) ?>
                                        </span>
                                        <span class="mono" style="font-size:9.5px;color:var(--text-faint);font-weight:600;">
                                            <?= count($subfolder->employee_documents) ?>
                                        </span>
                                    </div>
                                    <?php if (!empty($subfolder->employee_documents)): ?>
                                        <?= $this->element('employee_documents_table', [
                                            'docs'       => $subfolder->employee_documents,
                                            'employeeId' => $employee->id,
                                            'canDelete'  => !empty($userPermissions['employees']['can_delete']),
                                            'showHeader' => false,
                                        ]) ?>
                                    <?php else: ?>
                                        <div style="padding:14px 18px 14px 34px;font-size:11.5px;color:var(--text-faint);
                                                    font-style:italic;border-bottom:1px solid var(--rule);">
                                            <i class="bi bi-file-earmark me-1" aria-hidden="true"></i>Subcarpeta vacía
                                        </div>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Footer de estado -->
                            <div style="padding:10px 18px;background:var(--bg-muted);font-size:10.5px;
                                        color:var(--text-faint);border-top:1px solid var(--rule);
                                        display:flex;justify-content:space-between;align-items:center;">
                                <span><?= $totalDocs ?> archivo<?= $totalDocs !== 1 ? 's' : '' ?> en <?= count($folders) ?> carpeta<?= count($folders) !== 1 ? 's' : '' ?></span>
                                <span class="mono">Documentación del empleado</span>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>
        </div>

        <!-- ───── Tab: Perfil ───── -->
        <div class="tab-pane fade" id="tab-perfil" role="tabpanel">
            <div class="sgi-card">
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="sgi-label" style="margin-bottom:8px;">Datos personales</div>
                        <?php if ($employee->birth_date): ?>
                        <div class="field-row">
                            <span class="k">Fecha Nacimiento</span>
                            <span class="v mono"><?= $employee->birth_date->format('d/m/Y') ?><?php if ($employee->age !== null): ?> <span class="sgi-fg-faint">(<?= $employee->age ?> años)</span><?php endif; ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($employee->gender): ?>
                        <div class="field-row">
                            <span class="k">Género</span>
                            <span class="v"><?= h($employee->gender) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($employee->has('marital_status') && $employee->marital_status): ?>
                        <div class="field-row">
                            <span class="k">Estado Civil</span>
                            <span class="v"><?= h($employee->marital_status->name) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($employee->has('education_level') && $employee->education_level): ?>
                        <div class="field-row is-last">
                            <span class="k">Nivel Educativo</span>
                            <span class="v"><?= h($employee->education_level->name) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <div class="sgi-label" style="margin-bottom:8px;">Contacto</div>
                        <?php if ($employee->email): ?>
                        <div class="field-row">
                            <span class="k">Correo</span>
                            <span class="v"><?= h($employee->email) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($employee->phone): ?>
                        <div class="field-row">
                            <span class="k">Teléfono</span>
                            <span class="v mono"><?= h($employee->phone) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($employee->city): ?>
                        <div class="field-row">
                            <span class="k">Ciudad</span>
                            <span class="v"><?= h($employee->city) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($employee->address): ?>
                        <div class="field-row is-last">
                            <span class="k">Dirección</span>
                            <span class="v"><?= h($employee->address) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ───── Tab: Contrato ───── -->
        <div class="tab-pane fade" id="tab-contrato" role="tabpanel">
            <div class="sgi-card">
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="sgi-label" style="margin-bottom:8px;">Datos Laborales</div>
                        <?php if ($employee->has('position') && $employee->position): ?>
                        <div class="field-row">
                            <span class="k">Cargo</span>
                            <span class="v"><?= h($employee->position->name) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($employee->has('supervisor_position') && $employee->supervisor_position): ?>
                        <div class="field-row">
                            <span class="k">Jefe Inmediato</span>
                            <span class="v"><?= h($employee->supervisor_position->name) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($employee->has('cost_center') && $employee->cost_center): ?>
                        <div class="field-row">
                            <span class="k">Centro de Costos</span>
                            <span class="v"><?= h($employee->cost_center->name) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($employee->has('temporary_organization') && $employee->temporary_organization): ?>
                        <div class="field-row">
                            <span class="k">Organización Temporal</span>
                            <span class="v"><?= h($employee->temporary_organization->name) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($employee->vest_number): ?>
                        <div class="field-row is-last">
                            <span class="k">N. Chaleco</span>
                            <span class="v mono"><?= h($employee->vest_number) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <div class="sgi-label" style="margin-bottom:8px;">Vinculación</div>
                        <?php if ($employee->contract_type): ?>
                        <div class="field-row">
                            <span class="k">Tipo de Contrato</span>
                            <span class="v"><?= h($employee->contract_type) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($employee->hire_date): ?>
                        <div class="field-row">
                            <span class="k">Fecha Ingreso</span>
                            <span class="v mono"><?= $employee->hire_date->format('d/m/Y') ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($employee->termination_date): ?>
                        <div class="field-row">
                            <span class="k">Fecha Retiro</span>
                            <span class="v mono"><?= $employee->termination_date->format('d/m/Y') ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($employee->salary): ?>
                        <div class="field-row is-last">
                            <span class="k">Salario</span>
                            <span class="v mono">$ <?= $this->Number->format($employee->salary, ['places' => 0]) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ───── Tab: Novedades ───── -->
        <div class="tab-pane fade" id="tab-novedades" role="tabpanel">
            <div class="sgi-card" style="padding:0;overflow:hidden;">
                <div style="padding:14px 18px;display:flex;justify-content:space-between;
                            align-items:center;gap:12px;border-bottom:1px solid var(--rule);">
                    <div>
                        <div style="font-size:13px;font-weight:700;color:var(--text-strong);">Novedades</div>
                        <div style="font-size:10.5px;color:var(--text-faint);margin-top:2px;">
                            <?= $noveltyCount ?> registro<?= $noveltyCount !== 1 ? 's' : '' ?>
                        </div>
                    </div>
                    <?php if (!empty($userPermissions['employee_novelties']['can_create'])): ?>
                    <?= $this->Html->link(
                        '<i class="bi bi-plus-lg" aria-hidden="true"></i>Nueva Novedad',
                        ['controller' => 'EmployeeNovelties', 'action' => 'add', '?' => ['employee_id' => $employee->id]],
                        ['class' => 'btn btn-primary btn-sm', 'escape' => false]
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
                <div class="empty-state" style="padding:48px 20px;">
                    <div class="es-icon es-icon-primary">
                        <i class="bi bi-check" aria-hidden="true"></i>
                    </div>
                    <div class="es-title">Sin novedades registradas</div>
                    <div class="es-msg">No hay permisos ni novedades para este empleado.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ───── Tab: Historial ───── -->
        <div class="tab-pane fade" id="tab-historial" role="tabpanel">
            <div class="sgi-card" style="padding:0;overflow:hidden;">
                <div style="padding:14px 18px;border-bottom:1px solid var(--rule);">
                    <div style="font-size:13px;font-weight:700;color:var(--text-strong);">Historial de Cambios</div>
                    <div style="font-size:10.5px;color:var(--text-faint);margin-top:2px;">
                        <?= $historyCount ?> evento<?= $historyCount !== 1 ? 's' : '' ?>
                    </div>
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
                <div class="empty-state" style="padding:48px 20px;">
                    <div class="es-icon es-icon-neutral">
                        <i class="bi bi-clock-history" aria-hidden="true"></i>
                    </div>
                    <div class="es-title">Sin cambios registrados</div>
                    <div class="es-msg">El historial de auditoría aún no tiene eventos.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ───── Tab: Observaciones ───── -->
        <div class="tab-pane fade" id="tab-observaciones" role="tabpanel">
            <div class="sgi-card" style="padding:0;overflow:hidden;">
                <div style="padding:14px 18px;border-bottom:1px solid var(--rule);">
                    <div style="font-size:13px;font-weight:700;color:var(--text-strong);">Observaciones</div>
                    <div style="font-size:10.5px;color:var(--text-faint);margin-top:2px;">
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
                    <i class="bi bi-chat-square-text" aria-hidden="true"></i>
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

</section>
</div>

<!-- Modal: Nueva Carpeta -->
<div class="modal fade" id="newFolderModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <?= $this->Form->create(null, ['url' => ['action' => 'addFolder', $employee->id]]) ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-folder me-2" aria-hidden="true"></i>Nueva Carpeta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <?= $this->Form->control('name', ['class' => 'form-control', 'label' => ['text' => 'Nombre de la Carpeta', 'class' => 'form-label'], 'required' => true, 'placeholder' => 'Ej. Contratos, Certificados...']) ?>
                </div>
                <div class="mb-3">
                    <?php
                    $folderOptions = [];
                    foreach ($folders as $f) {
                        $folderOptions[$f->id] = $f->name;
                    }
                    ?>
                    <?= $this->Form->control('parent_id', ['class' => 'form-select', 'label' => ['text' => 'Carpeta Padre (opcional)', 'class' => 'form-label'], 'options' => $folderOptions, 'empty' => '— Raíz —']) ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-folder" aria-hidden="true"></i>Crear Carpeta</button>
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

<?= $this->element('excel_wizard/modals', [
    'module' => 'Employees',
    'entityName' => 'Empleados',
    'downloadSlug' => 'empleados',
    'importable' => true,
]) ?>

<?= $this->element('observation_chat_init') ?>

<script>
// Auto-submit del buscador del directorio con debounce.
(function () {
    var form = document.querySelector('.emp-nav-search');
    if (!form) return;
    var input = form.querySelector('input[name="search"]');
    if (!input) return;
    var t;
    input.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(function () { form.submit(); }, 400);
    });
})();
</script>

<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\EmployeeNovelty> $novelties
 * @var string|null $statusFilter
 * @var string|null $typeFilter
 * @var array $noveltyTypes
 * @var array $visibleStatuses
 */
use App\Constants\NoveltyConstants;
use App\View\Presentation\NoveltyPresentation;

$action = $this->request->getParam('action');

$pageTitles = [
    'all' => 'Todas las Novedades',
    'rejected' => 'Novedades Rechazadas',
    'active' => 'Novedades Vigentes',
];
$pageTitle = $pageTitles[$action] ?? 'Mis Novedades';
$this->assign('title', $pageTitle);

$linkAction = ($action === 'index') ? 'edit' : 'view';

$scheduleLabels = NoveltyConstants::SCHEDULE_LABELS;
$statusLabels   = NoveltyConstants::STATUS_LABELS;
$statusBadges   = NoveltyPresentation::STATUS_BADGES;
$pipelineSteps  = NoveltyConstants::PIPELINE_STATUSES;
$calendarColors = NoveltyPresentation::CALENDAR_COLORS;
$calendarColorCount = count($calendarColors);

$query = $this->request->getQueryParams();
$hasFilters = !empty($statusFilter) || !empty($typeFilter);
$activeStatus = (string)($statusFilter ?? '');

// Tabs por estado (sólo cuando aplica).
$baseQuery = array_diff_key($query, ['pipeline_status' => true, 'page' => true]);
$tabUrl = function (?string $status) use ($action, $baseQuery) {
    $params = ['action' => $action ?: 'index'];
    if ($status !== null) { $params['?'] = $baseQuery + ['pipeline_status' => $status]; }
    elseif (!empty($baseQuery)) { $params['?'] = $baseQuery; }
    return $params;
};
$tabs = [
    [null,                                          'Todas'],
    [NoveltyConstants::STATUS_APROBACION,           'Aprobación'],
    [NoveltyConstants::STATUS_RRHH,                 'RRHH'],
    [NoveltyConstants::STATUS_CONTABILIDAD,         'Contabilidad'],
    [NoveltyConstants::STATUS_REVISION_FIRMAS,      'Firmas'],
    [NoveltyConstants::STATUS_TESORERIA,            'Tesorería'],
    [NoveltyConstants::STATUS_PAGADA,               'Pagadas'],
];
?>
<?= $this->element('cdn_select2') ?>

<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div>
        <span class="sgi-page-title"><?= $pageTitle ?></span>
        <div class="sgi-body-faint mt-1" style="font-size:var(--fs-body-sm);">
            <span class="sgi-fg-muted"><?= $this->Paginator->counter('{{count}} novedades') ?></span>
        </div>
    </div>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['employee_novelties']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva Novedad',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- View navigation -->
<ul class="nav nav-tabs sgi-tabs-underline mb-3" role="tablist">
    <li class="nav-item" role="presentation">
        <?= $this->Html->link('Mis Novedades', ['action' => 'index'],
            ['class' => 'nav-link' . ($action === 'index' ? ' active' : ''), 'role' => 'tab']) ?>
    </li>
    <li class="nav-item" role="presentation">
        <?= $this->Html->link('Todas', ['action' => 'all'],
            ['class' => 'nav-link' . ($action === 'all' ? ' active' : ''), 'role' => 'tab']) ?>
    </li>
    <li class="nav-item" role="presentation">
        <?= $this->Html->link('Rechazadas', ['action' => 'rejected'],
            ['class' => 'nav-link' . ($action === 'rejected' ? ' active' : ''), 'role' => 'tab']) ?>
    </li>
    <li class="nav-item" role="presentation">
        <?= $this->Html->link('Vigentes', ['action' => 'active'],
            ['class' => 'nav-link' . ($action === 'active' ? ' active' : ''), 'role' => 'tab']) ?>
    </li>
    <?php if ($action === 'all'): ?>
    <li class="nav-item ms-auto d-flex align-items-center">
        <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-dark" id="btn-view-list" title="Vista de lista">
                <i class="bi bi-list-ul" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-dark" id="btn-view-calendar" title="Vista de calendario">
                <i class="bi bi-calendar3" aria-hidden="true"></i>
            </button>
        </div>
    </li>
    <?php endif; ?>
</ul>

<!-- List View -->
<div id="view-list" <?= $action === 'all' ? 'style="display:none;"' : '' ?>>
    <!-- Search & Filters -->
    <form method="get" class="d-flex gap-2 align-items-stretch mb-3">
        <?php if ($action !== 'rejected'): ?>
        <select name="pipeline_status" class="form-select form-select-sm" style="max-width:240px;" onchange="this.form.submit()">
            <option value="">Estado: Todos</option>
            <?php foreach (NoveltyConstants::ALL_STATUSES as $s): ?>
            <option value="<?= $s ?>" <?= ($statusFilter ?? '') === $s ? 'selected' : '' ?>><?= $statusLabels[$s] ?? ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select name="novelty_type_id" class="form-select form-select-sm" style="max-width:240px;" onchange="this.form.submit()">
            <option value="">Tipo: Todos</option>
            <?php foreach ($noveltyTypes as $id => $name): ?>
            <option value="<?= $id ?>" <?= ($typeFilter ?? '') == $id ? 'selected' : '' ?>><?= h($name) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($hasFilters): ?>
        <?= $this->Html->link(
            '<i class="bi bi-x-lg" aria-hidden="true"></i> Limpiar',
            ['action' => $action ?: 'index'],
            ['class' => 'btn btn-ghost-card sgi-fg-danger', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </form>

    <?php if ($action !== 'rejected'): ?>
    <!-- Tabs por estado de pipeline -->
    <div class="sgi-status-tabs mb-3" role="tablist" aria-label="Filtrar por estado">
        <?php foreach ($tabs as [$status, $label]):
            $isActive = ($activeStatus === ($status ?? ''));
            $color = match ($status) {
                NoveltyConstants::STATUS_APROBACION, NoveltyConstants::STATUS_REVISION_FIRMAS => 'var(--warning-text)',
                NoveltyConstants::STATUS_RRHH         => 'var(--accent-color)',
                NoveltyConstants::STATUS_CONTABILIDAD => 'var(--primary-color)',
                NoveltyConstants::STATUS_TESORERIA    => 'var(--info-text)',
                NoveltyConstants::STATUS_PAGADA       => 'var(--primary-color)',
                default                               => 'var(--primary-color)',
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
    <?php endif; ?>

    <?php
    // Columnas: Empleado | Tipo | Fecha | Horario | Remunerado | Estado · Pipeline | chevron
    $gridCols = '1.8fr 1.5fr 1fr 1fr 1fr 1.7fr 36px';
    ?>
    <div class="card">
        <div class="sgi-row-fact-grid sgi-row-fact-head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
            <span>Empleado</span>
            <span>Tipo</span>
            <span>Fecha</span>
            <span>Horario</span>
            <span>Remunerado</span>
            <span>Estado · Pipeline</span>
            <span aria-hidden="true"></span>
        </div>

        <?php
        $rowCount = 0;
        foreach ($novelties as $novelty):
            $rowCount++;
            $isRejected = $novelty->isRejected();
            $stageIdx = array_search($novelty->pipeline_status, $pipelineSteps, true);
            if ($stageIdx === false) { $stageIdx = -1; }
            $pillClass = $isRejected ? 'pill-danger-soft' : ($statusBadges[$novelty->pipeline_status] ?? 'pill-muted');

            $typeName = h($novelty->novelty_type->name ?? '—');
            $rawColor = $calendarColors[($novelty->novelty_type_id - 1) % $calendarColorCount];
            // Defensa contra CSS injection: solo aceptar hex (#RGB|#RRGGBB|#RRGGBBAA).
            $typeColor = preg_match('/^#[0-9A-Fa-f]{3,8}$/', (string)$rawColor) ? $rawColor : '#6c757d';
        ?>
            <a class="sgi-row-fact sgi-row-fact-grid<?= $isRejected ? ' is-rejected' : '' ?>"
               style="grid-template-columns:<?= $gridCols ?>;"
               href="<?= $this->Url->build(['action' => $linkAction, $novelty->id]) ?>"
               role="row">

                <!-- Empleado -->
                <div style="min-width:0;">
                    <div class="sgi-row-fact-provider">
                        <?= h($novelty->custom_name ?: $novelty->employee->full_name ?? '—') ?>
                    </div>
                    <?php if ($novelty->registered_by_user): ?>
                    <div class="sgi-row-fact-center">
                        <i class="bi bi-person" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
                        <span><?= h($novelty->registered_by_user->full_name) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Tipo -->
                <div style="min-width:0;">
                    <span class="pill" style="background-color:<?= $typeColor ?>;color:#fff;"><?= $typeName ?></span>
                </div>

                <!-- Fecha -->
                <div class="sgi-row-fact-date sgi-fg-muted">
                    <?= $novelty->permission_date?->format('d/m/Y') ?: '—' ?>
                </div>

                <!-- Horario -->
                <div class="sgi-row-fact-date sgi-fg-muted">
                    <?= h($scheduleLabels[$novelty->schedule_type] ?? '—') ?>
                </div>

                <!-- Remunerado -->
                <div>
                    <?php if ($novelty->is_paid): ?>
                        <span class="pill pill-primary-soft">SÍ</span>
                    <?php else: ?>
                        <span class="pill pill-secondary-soft">NO</span>
                    <?php endif; ?>
                </div>

                <!-- Pipeline mini + pill -->
                <div class="sgi-row-fact-pipeline">
                    <?php if (!$isRejected && $stageIdx >= 0): ?>
                    <div class="sgi-pipeline-mini" aria-hidden="true">
                        <?php for ($i = 0, $n = count($pipelineSteps); $i < $n; $i++): ?>
                            <div class="<?= $i <= $stageIdx ? 'on' : '' ?>"></div>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                    <div class="sgi-row-fact-pills">
                        <span class="pill <?= h($pillClass) ?>">
                            <?= h(strtoupper($statusLabels[$novelty->pipeline_status] ?? $novelty->pipeline_status)) ?>
                        </span>
                    </div>
                </div>

                <!-- Chevron -->
                <div class="sgi-row-fact-chevron">
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </div>
            </a>
        <?php endforeach; ?>

        <?php if ($rowCount === 0): ?>
            <div class="sgi-row-fact-empty">
                <i class="bi bi-inbox sgi-doc-empty-icon" aria-hidden="true"></i>
                No hay novedades en tu bandeja actual.
            </div>
        <?php endif; ?>

        <?= $this->element('pagination') ?>
    </div>
</div>

<?php if ($action === 'all'): ?>
<!-- Calendar View -->
<div id="view-calendar">
    <!-- Calendar Filters -->
    <div class="card mb-3">
        <div class="card-body py-2 px-3">
            <div class="d-flex gap-3 align-items-center flex-wrap">
                <select id="cal-filter-status" class="form-select form-select-sm" style="max-width:200px;">
                    <option value="">Estado: Todos</option>
                    <?php foreach (NoveltyConstants::ALL_STATUSES as $s): ?>
                    <option value="<?= $s ?>"><?= $statusLabels[$s] ?? ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="cal-filter-type" class="form-select form-select-sm select2" style="max-width:200px;" data-placeholder="Tipo: Todos">
                    <option value="">Tipo: Todos</option>
                    <?php foreach ($noveltyTypes as $id => $name): ?>
                    <option value="<?= $id ?>"><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="cal-filter-employee" class="form-select form-select-sm select2" style="max-width:260px;" data-placeholder="Empleado: Todos">
                    <option value="">Empleado: Todos</option>
                    <?php foreach (($employees ?? []) as $id => $name): ?>
                    <option value="<?= $id ?>"><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="cal-btn-clear" class="btn btn-sm btn-outline-secondary" style="display:none;">
                    <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Limpiar
                </button>
            </div>
        </div>
    </div>

    <div class="card card-dark">
        <div class="card-body p-0">
            <div id="all-calendar" class="sgi-calendar"></div>
        </div>
    </div>
</div>

<?= $this->element('fullcalendar_assets') ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var btnList = document.getElementById('btn-view-list');
    var btnCal = document.getElementById('btn-view-calendar');
    var viewList = document.getElementById('view-list');
    var viewCalendar = document.getElementById('view-calendar');

    if (!btnList || !btnCal) return;

    var calendarInstance = null;

    function showList() {
        viewList.style.display = '';
        viewCalendar.style.display = 'none';
        btnList.className = 'btn btn-dark';
        btnCal.className = 'btn btn-outline-dark';
    }

    function showCalendar() {
        viewList.style.display = 'none';
        viewCalendar.style.display = '';
        btnList.className = 'btn btn-outline-dark';
        btnCal.className = 'btn btn-dark';

        if (!calendarInstance) {
            calendarInstance = SGICalendar.init({
                el: '#all-calendar',
                eventsUrl: '/employee-novelties/all-events',
                filters: {
                    pipeline_status: '#cal-filter-status',
                    novelty_type_id: '#cal-filter-type',
                    employee_id:     '#cal-filter-employee'
                },
                clearBtn: '#cal-btn-clear'
            });
        } else {
            calendarInstance.updateSize();
        }
    }

    btnList.addEventListener('click', showList);
    btnCal.addEventListener('click', showCalendar);

    // Calendar is the default view — initialize immediately
    calendarInstance = SGICalendar.init({
        el: '#all-calendar',
        eventsUrl: '/employee-novelties/all-events',
        filters: {
            pipeline_status: '#cal-filter-status',
            novelty_type_id: '#cal-filter-type',
            employee_id:     '#cal-filter-employee'
        },
        clearBtn: '#cal-btn-clear'
    });
});
</script>
<?php endif; ?>

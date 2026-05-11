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
];
$pageTitle = $pageTitles[$action] ?? 'Mis Novedades';
$this->assign('title', $pageTitle);

$linkAction = ($action === 'index') ? 'edit' : 'view';

$scheduleLabels = NoveltyConstants::SCHEDULE_LABELS;
$statusLabels = NoveltyConstants::STATUS_LABELS;
$calendarColors = NoveltyPresentation::CALENDAR_COLORS;
$calendarColorCount = count($calendarColors);
?>
<?= $this->element('cdn_select2') ?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title"><?= $pageTitle ?></span>
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
<div class="d-flex gap-2 mb-3 align-items-center">
    <?= $this->Html->link('Mis Novedades', ['action' => 'index'],
        ['class' => 'btn btn-sm ' . ($action === 'index' ? 'btn-dark' : 'btn-outline-dark')]) ?>
    <?= $this->Html->link('Todas las Novedades', ['action' => 'all'],
        ['class' => 'btn btn-sm ' . ($action === 'all' ? 'btn-dark' : 'btn-outline-dark')]) ?>
    <?= $this->Html->link('Rechazadas', ['action' => 'rejected'],
        ['class' => 'btn btn-sm ' . ($action === 'rejected' ? 'btn-danger' : 'btn-outline-danger')]) ?>
    <?= $this->Html->link('Vigentes', ['action' => 'active'],
        ['class' => 'btn btn-sm btn-outline-dark']) ?>
    <?php if ($action === 'all'): ?>
    <div class="ms-auto btn-group btn-group-sm" role="group">
        <button type="button" class="btn btn-outline-dark" id="btn-view-list" title="Vista de lista">
            <i class="bi bi-list-ul" aria-hidden="true"></i>
        </button>
        <button type="button" class="btn btn-dark" id="btn-view-calendar" title="Vista de calendario">
            <i class="bi bi-calendar3" aria-hidden="true"></i>
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- List View -->
<div id="view-list" <?= $action === 'all' ? 'style="display:none;"' : '' ?>>
    <!-- Filters -->
    <div class="card card-primary mb-3">
        <div class="card-body py-2 px-3">
            <form method="get" class="d-flex gap-3 align-items-center flex-wrap">
                <?php if ($action !== 'rejected'): ?>
                <select name="pipeline_status" class="form-select form-select-sm" style="max-width:200px;" onchange="this.form.submit()">
                    <option value="">Estado: Todos</option>
                    <?php foreach (NoveltyConstants::ALL_STATUSES as $s): ?>
                    <option value="<?= $s ?>" <?= ($statusFilter ?? '') === $s ? 'selected' : '' ?>><?= $statusLabels[$s] ?? ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <select name="novelty_type_id" class="form-select form-select-sm" style="max-width:200px;" onchange="this.form.submit()">
                    <option value="">Tipo: Todos</option>
                    <?php foreach ($noveltyTypes as $id => $name): ?>
                    <option value="<?= $id ?>" <?= ($typeFilter ?? '') == $id ? 'selected' : '' ?>><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($statusFilter || $typeFilter): ?>
                <a href="<?= $this->Url->build(['action' => $action]) ?>" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="card card-primary">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Empleado</th>
                        <th>Tipo de Novedad</th>
                        <th>Fecha Permiso</th>
                        <th>Horario</th>
                        <th>Remunerado</th>
                        <th>Estado</th>
                        <th>Registrado por</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($novelties as $novelty): ?>
                    <tr class="clickable-row <?= $novelty->isRejected() ? 'table-danger' : '' ?>"
                        data-href="<?= $this->Url->build(['action' => $linkAction, $novelty->id]) ?>">
                        <td><?= h($novelty->custom_name ?: $novelty->employee->full_name ?? '—') ?></td>
                        <td><?php
                            $typeName = h($novelty->novelty_type->name ?? '—');
                            $typeColor = $calendarColors[($novelty->novelty_type_id - 1) % $calendarColorCount];
                        ?><span class="badge" style="background-color:<?= $typeColor ?>"><?= $typeName ?></span></td>
                        <td><?= $novelty->permission_date?->format('d/m/Y') ?: '—' ?></td>
                        <td><?= $scheduleLabels[$novelty->schedule_type] ?? '—' ?></td>
                        <td>
                            <?php if ($novelty->is_paid): ?>
                                <span class="badge bg-success">Sí</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">No</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= NoveltyPresentation::STATUS_BADGES[$novelty->pipeline_status] ?? 'bg-secondary' ?>"><?= $statusLabels[$novelty->pipeline_status] ?? ucfirst(h($novelty->pipeline_status)) ?></span></td>
                        <td style="font-size:.8125rem;color:#888"><?= h($novelty->registered_by_user->full_name ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= $this->element('pagination') ?>
    </div>
</div>

<?php if ($action === 'all'): ?>
<!-- Calendar View -->
<div id="view-calendar">
    <!-- Calendar Filters -->
    <div class="card card-primary mb-3">
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

<!-- FullCalendar CDN (v6 includes CSS in the JS bundle) -->
<script src="<?= $this->Url->build('/vendor/fullcalendar/index.global.min.js') ?>"></script>
<script src="<?= $this->Url->build('/vendor/fullcalendar/locales/es.global.min.js') ?>"></script>
<?= $this->Html->css('sgi-fullcalendar-overrides') ?>
<?= $this->Html->css('sgi-calendar') ?>
<script src="<?= $this->Url->build('/js/sgi-calendar.js') ?>"></script>
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

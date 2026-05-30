<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\EmployeeNovelty> $novelties
 * @var string|null $statusFilter
 * @var string|null $typeFilter
 * @var array $noveltyTypes
 * @var array $visibleStatuses
 * @var array<\App\Model\Entity\EmployeeNovelty> $upcomingNovelties
 * @var array<string, int> $typeDistribution
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
$calendarColors = NoveltyPresentation::CALENDAR_COLORS;
$calendarColorCount = count($calendarColors);

$upcomingNovelties = $upcomingNovelties ?? [];
$typeDistribution  = $typeDistribution ?? [];

$hasFilters = !empty($statusFilter) || !empty($typeFilter);

// Defensa contra CSS injection: solo aceptar hex (#RGB|#RRGGBB|#RRGGBBAA).
$safeColor = function (?string $raw): string {
    return preg_match('/^#[0-9A-Fa-f]{3,8}$/', (string)$raw) ? (string)$raw : '#6c757d';
};
// Color por tipo, indexado por novelty_type_id.
$colorForType = function (?int $typeId) use ($calendarColors, $calendarColorCount, $safeColor): string {
    if (!$typeId) {
        return '#6c757d';
    }
    return $safeColor($calendarColors[($typeId - 1) % $calendarColorCount]);
};

// Soft-fill (alpha) derivado de un hex de 6 dígitos.
$softFill = function (string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    return "rgba($r,$g,$b,0.14)";
};

// Fecha/período de presentación de una novedad.
$periodLabel = function (\App\Model\Entity\EmployeeNovelty $n): string {
    if ($n->schedule_type === NoveltyConstants::SCHEDULE_HOURS) {
        return $n->permission_date?->format('d/m/Y') ?: '—';
    }
    $start = $n->start_date?->format('d/m/Y');
    $end = $n->end_date?->format('d/m/Y');
    if (!$start) {
        return '—';
    }
    if ($end && $end !== $start) {
        return $start . ' → ' . $end;
    }

    return $start;
};

// Días de la novedad: por días = diferencia start→end (+1 inclusivo); por horas = null.
$daysCount = function (\App\Model\Entity\EmployeeNovelty $n): ?int {
    if ($n->schedule_type !== NoveltyConstants::SCHEDULE_DAYS) {
        return null;
    }
    if (!$n->start_date || !$n->end_date) {
        return null;
    }

    return (int)$n->start_date->diffInDays($n->end_date) + 1;
};

// Abreviatura de 3 letras a partir del nombre del tipo.
$typeShort = function (?string $name): string {
    $name = trim((string)$name);
    if ($name === '') {
        return '—';
    }

    return mb_strtoupper(mb_substr($name, 0, 3));
};

$query = $this->request->getQueryParams();
$baseQuery = array_diff_key($query, ['page' => true]);

// Sub-tabs de scope (mapean a rutas reales).
$rejectedCount = $action === 'rejected' ? $this->Paginator->counter('{{count}}') : null;
$scopeTabs = [
    ['index',    'Mis Novedades', 'var(--primary-color)'],
    ['all',      'Todas',         'var(--primary-color)'],
    ['rejected', 'Rechazadas',    'var(--danger-color)'],
    ['active',   'Vigentes',      'var(--primary-color)'],
];
?>
<?= $this->element('cdn_select2') ?>

<!-- ═══ Header ═══ -->
<div class="d-flex justify-content-between align-items-start" style="margin-bottom:18px;">
    <div>
        <span class="sgi-title-page"><?= h($pageTitle) ?></span>
        <div class="sgi-body-faint mt-1" style="font-size:var(--fs-body-sm);">
            <span class="sgi-fg-muted"><?= $this->Paginator->counter('{{count}} solicitudes') ?></span>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-default" id="btn-export-novelties">
            <i class="bi bi-download me-1" aria-hidden="true"></i>Exportar
        </button>
        <button type="button" class="btn btn-default" id="btn-toggle-filters"
                data-bs-toggle="collapse" data-bs-target="#noveltyFiltersPanel"
                aria-expanded="<?= $hasFilters ? 'true' : 'false' ?>">
            <i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtros
        </button>
        <?php if (!empty($userPermissions['employee_novelties']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva Novedad',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- ═══ Sub-tabs (scope) + view toggle ═══ -->
<div class="d-flex align-items-center justify-content-between" style="margin-bottom:12px;">
    <div class="d-flex" style="gap:4px;">
        <?php foreach ($scopeTabs as [$tabAction, $tabLabel, $tabColor]):
            $isActive = ($action === $tabAction) || ($action === 'index' && $tabAction === 'index');
        ?>
        <?= $this->Html->link(
            ($isActive ? '<span class="dot" style="background:' . $tabColor . ';"></span>' : '') . h($tabLabel),
            ['action' => $tabAction],
            [
                'class' => 'chip' . ($isActive ? ' is-active' : ''),
                'escape' => false,
                'style' => $isActive ? 'color:' . $tabColor : '',
            ]
        ) ?>
        <?php endforeach; ?>
    </div>

    <?php if ($action === 'all'): ?>
    <div class="segmented" role="group" aria-label="Cambiar vista">
        <button type="button" class="seg" id="btn-view-list" title="Vista de lista">
            <i class="bi bi-list-ul" aria-hidden="true"></i>
        </button>
        <button type="button" class="seg is-active" id="btn-view-calendar" title="Vista de calendario">
            <i class="bi bi-calendar3" aria-hidden="true"></i>
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ Filtros ═══ -->
<div class="collapse <?= $hasFilters ? 'show' : '' ?>" id="noveltyFiltersPanel" style="margin-bottom:14px;">
    <div class="sgi-card compact">
        <form method="get" id="novelty-filters" class="d-flex gap-2 align-items-center">
            <?php if ($action !== 'rejected'): ?>
            <select name="pipeline_status" class="form-select form-select-sm" style="max-width:220px;" onchange="this.form.submit()">
                <option value="">Estado: Todos</option>
                <?php foreach (NoveltyConstants::ALL_STATUSES as $s): ?>
                <option value="<?= h($s) ?>" <?= ($statusFilter ?? '') === $s ? 'selected' : '' ?>>
                    Estado: <?= h($statusLabels[$s] ?? ucfirst($s)) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select name="novelty_type_id" class="form-select form-select-sm" style="max-width:220px;" onchange="this.form.submit()">
                <option value="">Tipo: Todos</option>
                <?php foreach ($noveltyTypes as $id => $name): ?>
                <option value="<?= h($id) ?>" <?= ($typeFilter ?? '') == $id ? 'selected' : '' ?>>
                    Tipo: <?= h($name) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($hasFilters): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x" aria-hidden="true"></i> Limpiar filtros',
                ['action' => $action ?: 'index'],
                ['class' => 'btn btn-ghost btn-sm sgi-fg-secondary', 'escape' => false]
            ) ?>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ═══ Grid principal: contenido + side rail ═══ -->
<div style="display:grid;grid-template-columns:1fr 320px;gap:14px;align-items:flex-start;">

    <!-- ─── Columna izquierda ─── -->
    <div style="min-width:0;">

        <!-- Vista lista -->
        <div id="view-list" <?= $action === 'all' ? 'style="display:none;"' : '' ?>>
            <?php
            // Solicitud · Empleado · Tipo · Período · Días · Estado·Aprobador
            $gridCols = '110px 1.6fr 1fr 1fr 0.6fr 1fr';
            ?>
            <div class="sgi-card" style="padding:0;">
                <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
                    <span>Solicitud</span>
                    <span>Empleado</span>
                    <span>Tipo</span>
                    <span>Período</span>
                    <span style="text-align:right;">Días</span>
                    <span>Estado · Registrado por</span>
                </div>

                <?php
                $rowCount = 0;
                foreach ($novelties as $novelty):
                    $rowCount++;
                    $row = NoveltyPresentation::forEmployeeNoveltyRow($novelty);
                    $isRejected = $row->isRejected;
                    $pillClass = $row->statusBadgeClass;

                    $typeId = (int)$novelty->novelty_type_id;
                    $typeName = $novelty->novelty_type->name ?? '—';
                    $typeColor = $colorForType($typeId);

                    $employeeName = $novelty->custom_name
                        ?: ($novelty->employee->full_name ?? '—');
                    $approverName = $novelty->registered_by_user->full_name ?? '—';
                    $days = $daysCount($novelty);
                ?>
                <a class="row-fact" style="grid-template-columns:<?= $gridCols ?>;<?= $isRejected ? 'opacity:.72;' : '' ?>"
                   href="<?= $this->Url->build(['action' => $linkAction, $novelty->id]) ?>"
                   role="row">

                    <!-- Solicitud -->
                    <div class="mono" style="font-size:11.5px;font-weight:700;color:var(--text-strong);">
                        NV-<?= str_pad((string)$novelty->id, 4, '0', STR_PAD_LEFT) ?>
                    </div>

                    <!-- Empleado -->
                    <div class="d-flex align-items-center" style="gap:10px;min-width:0;">
                        <span class="av av-sm" aria-hidden="true">
                            <?= h(mb_strtoupper(mb_substr(trim($employeeName), 0, 1))) ?>
                        </span>
                        <div style="min-width:0;">
                            <div style="font-size:12.5px;font-weight:600;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?= h($employeeName) ?>
                            </div>
                            <div style="font-size:10.5px;color:var(--text-faint);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?= h($scheduleLabels[$novelty->schedule_type] ?? '—') ?>
                            </div>
                        </div>
                    </div>

                    <!-- Tipo -->
                    <div class="d-inline-flex align-items-center" style="gap:6px;min-width:0;">
                        <span class="mono" aria-hidden="true" style="width:20px;height:20px;flex-shrink:0;background:<?= $typeColor ?>;color:#fff;font-size:9px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;border-radius:3px;letter-spacing:.3px;">
                            <?= h($typeShort($novelty->novelty_type->name ?? null)) ?>
                        </span>
                        <span style="font-size:12px;font-weight:600;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= h($typeName) ?>
                        </span>
                    </div>

                    <!-- Período -->
                    <div class="mono" style="font-size:11.5px;color:var(--text-default);">
                        <?= h($periodLabel($novelty)) ?>
                    </div>

                    <!-- Días -->
                    <div class="mono" style="text-align:right;font-size:13px;font-weight:700;color:var(--text-strong);">
                        <?= ($days !== null && $days > 0) ? h((string)$days) : '—' ?>
                    </div>

                    <!-- Estado · Aprobador -->
                    <div style="min-width:0;">
                        <span class="pill pill-sm <?= h($pillClass) ?>">
                            <?php if ($isRejected): ?>
                            <i class="bi bi-x" aria-hidden="true"></i>
                            <?php elseif ($row->isPaid): ?>
                            <i class="bi bi-check" aria-hidden="true"></i>
                            <?php else: ?>
                            <i class="bi bi-clock" aria-hidden="true"></i>
                            <?php endif; ?>
                            <?= h(mb_strtoupper($row->statusLabel)) ?>
                        </span>
                        <div style="font-size:10px;color:var(--text-faint);margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= h($approverName) ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>

                <?php if ($rowCount === 0): ?>
                <div class="empty-state">
                    <div class="es-icon es-icon-neutral">
                        <i class="bi bi-inbox" aria-hidden="true"></i>
                    </div>
                    <div class="es-title">Sin novedades</div>
                    <div class="es-msg">No hay novedades para los filtros aplicados.</div>
                </div>
                <?php endif; ?>

                <?= $this->element('pagination') ?>
            </div>
        </div>

        <?php if ($action === 'all'): ?>
        <!-- Vista calendario -->
        <div id="view-calendar">
            <!-- Filtros del calendario -->
            <div class="sgi-card compact" style="margin-bottom:14px;">
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <select id="cal-filter-status" class="form-select form-select-sm" style="max-width:200px;">
                        <option value="">Estado: Todos</option>
                        <?php foreach (NoveltyConstants::ALL_STATUSES as $s): ?>
                        <option value="<?= h($s) ?>">Estado: <?= h($statusLabels[$s] ?? ucfirst($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="cal-filter-type" class="form-select form-select-sm select2" style="max-width:200px;" data-placeholder="Tipo: Todos">
                        <option value="">Tipo: Todos</option>
                        <?php foreach ($noveltyTypes as $id => $name): ?>
                        <option value="<?= h($id) ?>"><?= h($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="cal-filter-employee" class="form-select form-select-sm select2" style="max-width:260px;" data-placeholder="Empleado: Todos">
                        <option value="">Empleado: Todos</option>
                        <?php foreach (($employees ?? []) as $id => $name): ?>
                        <option value="<?= h($id) ?>"><?= h($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="cal-btn-clear" class="btn btn-ghost btn-sm" style="display:none;">
                        <i class="bi bi-x me-1" aria-hidden="true"></i>Limpiar
                    </button>
                </div>
            </div>

            <div class="sgi-card" style="padding:0;">
                <div id="all-calendar" class="sgi-calendar"></div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- ─── Side rail ─── -->
    <div style="display:flex;flex-direction:column;gap:12px;">

        <!-- Próximas Novedades -->
        <div class="sgi-card">
            <div class="sgi-label" style="margin-bottom:14px;">Próximas Novedades</div>
            <?php if (empty($upcomingNovelties)): ?>
            <div style="padding:14px 0;text-align:center;color:var(--text-faint);font-size:11.5px;">
                Sin novedades próximas.
            </div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;">
                <?php
                $upCount = count($upcomingNovelties);
                foreach (array_values($upcomingNovelties) as $idx => $up):
                    $upTypeColor = $colorForType((int)$up->novelty_type_id);
                    $upDate = ($up->schedule_type === NoveltyConstants::SCHEDULE_HOURS)
                        ? $up->permission_date
                        : $up->start_date;
                    $upEmployee = $up->custom_name ?: ($up->employee->full_name ?? '—');
                    $upPill = $up->isRejected()
                        ? 'pill-danger-soft'
                        : ($statusBadges[$up->pipeline_status] ?? 'pill-muted');
                    $upDays = $up->days_count ?? null;
                    $monthAbbr = $upDate
                        ? mb_strtoupper(['', 'ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'][(int)$upDate->format('n')])
                        : '—';
                ?>
                <div class="d-flex align-items-center" style="gap:10px;padding:10px 0;<?= $idx < $upCount - 1 ? 'border-bottom:1px solid var(--rule);' : '' ?>">
                    <div style="width:40px;flex-shrink:0;text-align:center;padding:4px 0;background:var(--bg-subtle);border-radius:3px;">
                        <div style="font-size:9px;font-weight:700;color:var(--text-faint);letter-spacing:.8px;">
                            <?= h($monthAbbr) ?>
                        </div>
                        <div class="mono" style="font-size:14px;font-weight:800;color:var(--primary-color);line-height:1;">
                            <?= $upDate ? h($upDate->format('d')) : '—' ?>
                        </div>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:11.5px;font-weight:700;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= h($upEmployee) ?>
                        </div>
                        <div class="d-inline-flex align-items-center" style="gap:5px;margin-top:3px;">
                            <span style="width:7px;height:7px;background:<?= $upTypeColor ?>;border-radius:1px;flex-shrink:0;"></span>
                            <span style="font-size:10.5px;color:var(--text-muted);font-weight:600;">
                                <?= h($up->novelty_type->name ?? '—') ?>
                            </span>
                            <?php if ($upDays !== null && $upDays > 0): ?>
                            <span style="font-size:10px;color:var(--text-faint);">· <?= h((string)$upDays) ?>d</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="pill pill-sm <?= h($upPill) ?>">
                        <?= h(mb_strtoupper(mb_substr($statusLabels[$up->pipeline_status] ?? $up->pipeline_status, 0, 3))) ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Distribución del mes -->
        <div class="sgi-card">
            <div class="sgi-label" style="margin-bottom:14px;">Distribución del mes</div>
            <?php if (empty($typeDistribution)): ?>
            <div style="padding:14px 0;text-align:center;color:var(--text-faint);font-size:11.5px;">
                Sin datos para mostrar.
            </div>
            <?php else: ?>
            <?php
            $distTotal = array_sum($typeDistribution);
            $distIndex = 0;
            ?>
            <div style="display:flex;flex-direction:column;gap:9px;">
                <?php foreach ($typeDistribution as $typeName => $count):
                    $distIndex++;
                    $pct = $distTotal > 0 ? (int)round(($count / $distTotal) * 100) : 0;
                    $distColor = $safeColor($calendarColors[($distIndex - 1) % $calendarColorCount]);
                ?>
                <div>
                    <div class="d-flex justify-content-between align-items-center" style="margin-bottom:4px;">
                        <span style="font-size:11px;color:var(--text-default);font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= h($typeName) ?>
                        </span>
                        <span class="mono" style="font-size:10.5px;color:var(--text-faint);flex-shrink:0;">
                            <strong style="color:var(--text-default);"><?= h((string)$count) ?></strong> · <?= $pct ?>%
                        </span>
                    </div>
                    <div style="height:4px;background:var(--rule);overflow:hidden;border-radius:1px;">
                        <div style="height:100%;width:<?= $pct ?>%;background:<?= $distColor ?>;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php if ($action === 'all'): ?>
<?= $this->element('fullcalendar_assets') ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var btnList = document.getElementById('btn-view-list');
    var btnCal = document.getElementById('btn-view-calendar');
    var viewList = document.getElementById('view-list');
    var viewCalendar = document.getElementById('view-calendar');

    if (!btnList || !btnCal) {
        return;
    }

    var calendarInstance = null;

    function calendarConfig() {
        return {
            el: '#all-calendar',
            eventsUrl: '/employee-novelties/all-events',
            filters: {
                pipeline_status: '#cal-filter-status',
                novelty_type_id: '#cal-filter-type',
                employee_id: '#cal-filter-employee'
            },
            clearBtn: '#cal-btn-clear'
        };
    }

    function showList() {
        viewList.style.display = '';
        viewCalendar.style.display = 'none';
        btnList.classList.add('is-active');
        btnCal.classList.remove('is-active');
    }

    function showCalendar() {
        viewList.style.display = 'none';
        viewCalendar.style.display = '';
        btnList.classList.remove('is-active');
        btnCal.classList.add('is-active');

        if (!calendarInstance) {
            calendarInstance = SGICalendar.init(calendarConfig());
        } else {
            calendarInstance.updateSize();
        }
    }

    btnList.addEventListener('click', showList);
    btnCal.addEventListener('click', showCalendar);

    // El calendario es la vista por defecto — inicializar de inmediato.
    calendarInstance = SGICalendar.init(calendarConfig());
});
</script>
<?php endif; ?>

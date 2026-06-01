<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\EmployeeNovelty> $novelties
 * @var string|null $statusFilter
 * @var string|null $typeFilter
 * @var string|null $employeeFilter
 * @var array $noveltyTypes
 * @var array $employees
 * @var array $visibleStatuses
 * @var array<\App\Model\Entity\EmployeeNovelty> $upcomingNovelties
 * @var array<string, int> $typeDistribution
 * @var array<string, int> $tabCounts
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

$hasFilters = !empty($statusFilter) || !empty($typeFilter) || !empty($employeeFilter);

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

$tabCounts = $tabCounts ?? ['mis' => 0, 'todas' => 0, 'rechazadas' => 0, 'vigentes' => 0, 'pendientes' => 0];

// El scope "Todas" expone lista + calendario; los demás solo lista.
$isCalendarScope = ($action === 'all');
$initialView = ($isCalendarScope && $this->request->getQuery('view') === 'list')
    ? 'list'
    : ($isCalendarScope ? 'calendar' : 'list');

// Sub-tabs de scope (mapean a rutas reales): [action, label, color, count].
$scopeTabs = [
    ['index',    'Mis Novedades',       'var(--primary-color)', $tabCounts['mis']],
    ['all',      'Todas las Novedades', 'var(--primary-color)', $tabCounts['todas']],
    ['rejected', 'Rechazadas',          'var(--danger-color)',  $tabCounts['rechazadas']],
    ['active',   'Vigentes',            'var(--primary-color)', $tabCounts['vigentes']],
];
?>
<?= $this->element('cdn_select2') ?>

<!-- ═══ Header ═══ -->
<div class="d-flex justify-content-between align-items-start" style="margin-bottom:18px;">
    <div>
        <span class="sgi-page-title"><?= h($pageTitle) ?></span>
        <div class="sgi-body-faint mt-1" style="font-size:var(--fs-body-sm);">
            <span class="sgi-fg-muted"><?= $this->Paginator->counter('{{count}} solicitudes') ?>
                · <?= (int)$tabCounts['vigentes'] ?> vigentes
                · <?= (int)$tabCounts['pendientes'] ?> pendientes de aprobación</span>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-default" id="btn-export-novelties">
            <i class="bi bi-download me-1" aria-hidden="true"></i>Exportar
        </button>
        <button type="button" class="btn btn-default" id="btn-toggle-filters"
                data-bs-toggle="collapse" data-bs-target="#noveltyFiltersPanel"
                aria-expanded="true">
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
        <?php foreach ($scopeTabs as [$tabAction, $tabLabel, $tabColor, $tabCount]):
            $isActive = ($action === $tabAction) || ($action === 'index' && $tabAction === 'index');
        ?>
        <?= $this->Html->link(
            ($isActive ? '<span class="dot" style="background:' . $tabColor . ';"></span>' : '')
                . h($tabLabel) . ' · ' . (int)$tabCount,
            ['action' => $tabAction],
            [
                'class' => 'chip' . ($isActive ? ' is-active' : ''),
                'escape' => false,
                'style' => $isActive ? 'color:' . $tabColor : '',
            ]
        ) ?>
        <?php endforeach; ?>
    </div>

    <?php if ($isCalendarScope): ?>
    <div class="segmented" role="group" aria-label="Cambiar vista">
        <button type="button" class="seg<?= $initialView === 'list' ? ' is-active' : '' ?>" id="btn-view-list" title="Vista de lista">
            <i class="bi bi-list-ul" aria-hidden="true"></i>
        </button>
        <button type="button" class="seg<?= $initialView === 'calendar' ? ' is-active' : '' ?>" id="btn-view-calendar" title="Vista de calendario">
            <i class="bi bi-calendar3" aria-hidden="true"></i>
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ Filtros (persistentes inline, sueltos sobre el canvas como el mock) ═══ -->
<div class="collapse show" id="noveltyFiltersPanel" style="margin-bottom:14px;">
    <form method="get" id="novelty-filters" class="d-flex gap-2 align-items-center flex-wrap">
        <?php if ($isCalendarScope): ?>
        <input type="hidden" name="view" id="flt-view" value="<?= h($initialView) ?>">
        <?php endif; ?>
        <?php if ($action !== 'rejected'): ?>
        <select name="pipeline_status" id="flt-status" class="form-select form-select-sm" style="max-width:200px;">
            <option value="">Estado: Todos</option>
            <?php foreach (NoveltyConstants::ALL_STATUSES as $s): ?>
            <option value="<?= h($s) ?>" <?= ($statusFilter ?? '') === $s ? 'selected' : '' ?>>
                Estado: <?= h($statusLabels[$s] ?? ucfirst($s)) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select name="novelty_type_id" id="flt-type" class="form-select form-select-sm" style="max-width:220px;">
            <option value="">Tipo: Todos</option>
            <?php foreach ($noveltyTypes as $id => $name): ?>
            <option value="<?= h($id) ?>" <?= ($typeFilter ?? '') == $id ? 'selected' : '' ?>>
                Tipo: <?= h($name) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="employee_id" id="flt-employee" class="form-select form-select-sm" style="max-width:260px;">
            <option value="">Empleado: Todos</option>
            <?php foreach (($employees ?? []) as $id => $name): ?>
            <option value="<?= h($id) ?>" <?= ($employeeFilter ?? '') == $id ? 'selected' : '' ?>>
                Empleado: <?= h($name) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="button" id="flt-clear" class="btn btn-ghost btn-sm sgi-fg-secondary" style="display:none;">
            <i class="bi bi-x" aria-hidden="true"></i> Limpiar filtros
        </button>
    </form>
</div>

<!-- ═══ Grid principal: contenido + side rail ═══ -->
<div style="display:grid;grid-template-columns:1fr 320px;gap:14px;align-items:flex-start;">

    <!-- ─── Columna izquierda ─── -->
    <div style="min-width:0;">

        <!-- Vista lista -->
        <div id="view-list"<?= $initialView === 'calendar' ? ' style="display:none;"' : '' ?>>
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

        <?php if ($isCalendarScope): ?>
        <!-- Vista calendario -->
        <div id="view-calendar"<?= $initialView === 'list' ? ' style="display:none;"' : '' ?>>
            <div class="sgi-card" style="padding:0;">
                <div id="all-calendar" class="sgi-calendar"></div>
                <?php if (!empty($noveltyTypes)): ?>
                <div class="sgi-cal-legend">
                    <span class="leg-title">Leyenda</span>
                    <?php foreach ($noveltyTypes as $tid => $tname):
                        $legColor = $colorForType((int)$tid);
                    ?>
                    <span class="leg-item">
                        <span class="leg-swatch" style="background:<?= $softFill($legColor) ?>;border-left:3px solid <?= $legColor ?>;"></span>
                        <span><?= h($tname) ?></span>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- ─── Side rail ─── -->
    <div style="display:flex;flex-direction:column;gap:12px;">

        <?php if ($isCalendarScope): ?>
        <!-- Día seleccionado (solo vista calendario) -->
        <div class="sgi-card" id="nov-day-panel"<?= $initialView === 'list' ? ' style="display:none;"' : '' ?>>
            <div class="d-flex align-items-start justify-content-between" style="margin-bottom:10px;">
                <div>
                    <div class="sgi-label">Día seleccionado</div>
                    <div id="nov-day-title" style="font-size:18px;font-weight:800;color:var(--text-strong);margin-top:4px;letter-spacing:-.3px;">—</div>
                </div>
                <span id="nov-day-count" class="mono" style="padding:3px 8px;font-size:10px;font-weight:700;background:var(--bg-subtle);color:var(--text-faint);border-radius:3px;white-space:nowrap;">0 eventos</span>
            </div>
            <div id="nov-day-body"></div>
        </div>
        <?php endif; ?>

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

<?php if ($isCalendarScope): ?>
<?= $this->element('fullcalendar_assets') ?>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('novelty-filters');
    if (!form) return;
    var selects = form.querySelectorAll('select[name]');
    var clearBtn = document.getElementById('flt-clear');

    function anyFilter() {
        var has = false;
        selects.forEach(function (s) { if (s.value) has = true; });
        return has;
    }
    function syncClear() {
        if (clearBtn) clearBtn.style.display = anyFilter() ? '' : 'none';
    }
    var resetting = false;
    function resetSelects() {
        resetting = true;
        selects.forEach(function (s) {
            if (typeof window.jQuery !== 'undefined' && window.jQuery(s).hasClass('select2-hidden-accessible')) {
                window.jQuery(s).val(null).trigger('change');
            } else {
                s.value = '';
            }
        });
        resetting = false;
    }
    // Enlaza 'change' vía jQuery cuando existe (capta el change de select2); si no, nativo.
    function bindChange(handler) {
        if (typeof window.jQuery !== 'undefined') {
            window.jQuery(selects).on('change', handler);
        } else {
            selects.forEach(function (s) { s.addEventListener('change', handler); });
        }
    }
    syncClear();

<?php if (!$isCalendarScope): ?>
    // Listado simple (Mis / Rechazadas): el filtro recarga server-side.
    bindChange(function () { if (resetting) return; syncClear(); form.submit(); });
    if (clearBtn) {
        clearBtn.addEventListener('click', function () { resetSelects(); syncClear(); form.submit(); });
    }
<?php else: ?>
    // Scope "Todas": lista (server-side) + calendario (client-side) comparten filtros.
    var btnList = document.getElementById('btn-view-list');
    var btnCal = document.getElementById('btn-view-calendar');
    var viewList = document.getElementById('view-list');
    var viewCalendar = document.getElementById('view-calendar');
    var dayPanel = document.getElementById('nov-day-panel');
    var viewInput = document.getElementById('flt-view');
    var currentView = '<?= $initialView ?>';
    var calFiltersDirty = false;
    var calendarInstance = null;

    var MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    var selectedStr = null;
    var lastEvents = [];

    function ymd(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    // Un evento cubre el día si: inicio <= día < fin (fin exclusivo), o es el día de inicio.
    function dayInEvent(dateStr, ev) {
        var s = (ev.startStr || '').slice(0, 10);
        var e = ev.endStr ? ev.endStr.slice(0, 10) : s;
        return dateStr >= s && (dateStr < e || dateStr === s);
    }
    function highlightCell(dateStr) {
        var prev = document.querySelector('#all-calendar .fc-day-selected');
        if (prev) prev.classList.remove('fc-day-selected');
        var cell = document.querySelector('#all-calendar td[data-date="' + dateStr + '"]');
        if (cell) cell.classList.add('fc-day-selected');
    }
    function renderDayPanel() {
        if (!dayPanel || !selectedStr) return;
        var titleEl = document.getElementById('nov-day-title');
        var countEl = document.getElementById('nov-day-count');
        var bodyEl = document.getElementById('nov-day-body');
        var parts = selectedStr.split('-');
        var dayNum = parseInt(parts[2], 10);
        var monthName = MESES[parseInt(parts[1], 10) - 1] || '';
        var isToday = selectedStr === ymd(new Date());
        var evs = lastEvents.filter(function (ev) { return dayInEvent(selectedStr, ev); });

        titleEl.innerHTML = (isToday ? 'Hoy · ' : '') + dayNum + ' de ' + monthName
            + ' <span style="font-size:12px;color:var(--text-faint);font-weight:500;">' + parts[0] + '</span>';
        countEl.textContent = evs.length + (evs.length === 1 ? ' evento' : ' eventos');
        countEl.style.background = evs.length ? 'var(--primary-soft)' : 'var(--bg-subtle)';
        countEl.style.color = evs.length ? 'var(--primary-color)' : 'var(--text-faint)';

        if (!evs.length) {
            bodyEl.innerHTML = '<div style="padding:20px 0;text-align:center;color:var(--text-faint);font-size:11.5px;background:var(--bg-muted);border-radius:3px;">Sin novedades programadas</div>';
            return;
        }
        var html = '<div style="display:flex;flex-direction:column;gap:6px;">';
        evs.forEach(function (ev) {
            var p = ev.extendedProps || {};
            var color = p.typeColor || '#6c757d';
            var name = p.empName || ev.title || '—';
            var initial = name.trim().charAt(0).toUpperCase();
            var diasTxt = (p.days && p.days > 0) ? ' · ' + p.days + (p.days === 1 ? ' día' : ' días') : '';
            html += '<a href="' + esc(ev.url) + '" style="display:flex;gap:10px;padding:10px;background:' + color + '22;position:relative;overflow:hidden;border-radius:3px;text-decoration:none;">'
                + '<span style="position:absolute;left:0;top:0;bottom:0;width:3px;background:' + color + ';"></span>'
                + '<span class="av av-sm" style="margin-left:4px;flex-shrink:0;">' + esc(initial) + '</span>'
                + '<div style="flex:1;min-width:0;">'
                + '<div style="font-size:11.5px;font-weight:700;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(name) + '</div>'
                + '<div style="font-size:10.5px;color:' + color + ';font-weight:600;margin-top:2px;">' + esc(p.typeName || '') + diasTxt + '</div>'
                + '<div class="mono" style="font-size:10px;color:var(--text-muted);margin-top:3px;">' + esc(p.code || '') + '</div>'
                + '</div>'
                + '<span class="pill pill-sm ' + esc(p.pill || 'pill-muted') + '" style="align-self:flex-start;">' + esc((p.status || '').toUpperCase()) + '</span>'
                + '</a>';
        });
        html += '</div>';
        bodyEl.innerHTML = html;
    }

    function calendarConfig() {
        return {
            el: '#all-calendar',
            eventsUrl: '/employee-novelties/all-events',
            filters: {
                pipeline_status: '#flt-status',
                novelty_type_id: '#flt-type',
                employee_id: '#flt-employee'
            },
            bindFilterChange: false,
            onEventsSet: function (events) {
                lastEvents = events;
                if (!selectedStr) selectedStr = ymd(new Date());
                highlightCell(selectedStr);
                renderDayPanel();
            }
        };
    }
    function updateViewParam(v) {
        var url = new URL(window.location.href);
        url.searchParams.set('view', v);
        return url.pathname + '?' + url.searchParams.toString();
    }
    function showList() {
        currentView = 'list';
        if (viewInput) viewInput.value = 'list';
        // Si se tocaron filtros en el calendario, recargar para sincronizar la lista.
        if (calFiltersDirty) { form.submit(); return; }
        viewList.style.display = '';
        viewCalendar.style.display = 'none';
        if (dayPanel) dayPanel.style.display = 'none';
        btnList.classList.add('is-active');
        btnCal.classList.remove('is-active');
        history.replaceState(null, '', updateViewParam('list'));
    }
    function showCalendar() {
        currentView = 'calendar';
        if (viewInput) viewInput.value = 'calendar';
        viewList.style.display = 'none';
        viewCalendar.style.display = '';
        if (dayPanel) dayPanel.style.display = '';
        btnList.classList.remove('is-active');
        btnCal.classList.add('is-active');
        history.replaceState(null, '', updateViewParam('calendar'));
        if (!calendarInstance) {
            calendarInstance = SGICalendar.init(calendarConfig());
        } else {
            calendarInstance.updateSize();
        }
    }

    // Selección de día por clic en la celda (delegado — no depende del plugin de
    // interacción de FullCalendar, ausente en el bundle global). Ignora clics sobre
    // eventos (ésos navegan vía eventClick).
    var calContainer = document.getElementById('all-calendar');
    if (calContainer) {
        calContainer.addEventListener('click', function (e) {
            if (e.target.closest('.fc-event')) return;
            var cell = e.target.closest('.fc-daygrid-day');
            if (!cell || !cell.dataset.date) return;
            selectedStr = cell.dataset.date;
            highlightCell(selectedStr);
            renderDayPanel();
        });
    }

    if (btnList) btnList.addEventListener('click', showList);
    if (btnCal) btnCal.addEventListener('click', showCalendar);

    function applyFilters() {
        syncClear();
        if (currentView === 'calendar') {
            calFiltersDirty = true;
            if (calendarInstance) calendarInstance.refetchEvents();
        } else {
            form.submit();
        }
    }
    bindChange(function () { if (resetting) return; applyFilters(); });
    if (clearBtn) {
        clearBtn.addEventListener('click', function () { resetSelects(); applyFilters(); });
    }

    // Inicializa la vista por defecto.
    if (currentView === 'calendar') {
        showCalendar();
    } else {
        showList();
    }
<?php endif; ?>
});
</script>

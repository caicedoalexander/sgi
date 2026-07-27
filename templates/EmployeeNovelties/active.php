<?php
/**
 * @var \App\View\AppView $this
 * @var array $noveltyTypes
 * @var array $employees
 * @var array<string, int> $tabCounts
 */
use App\View\Presentation\NoveltyPresentation;

$this->assign('title', 'Novedades Vigentes');

$tabCounts = $tabCounts ?? ['mis' => 0, 'todas' => 0, 'rechazadas' => 0, 'vigentes' => 0, 'pendientes' => 0];

$calendarColors = NoveltyPresentation::CALENDAR_COLORS;
$calendarColorCount = count($calendarColors);
$safeColor = function (?string $raw): string {
    return preg_match('/^#[0-9A-Fa-f]{3,8}$/', (string)$raw) ? (string)$raw : '#6c757d';
};
$colorForType = function (?int $typeId) use ($calendarColors, $calendarColorCount, $safeColor): string {
    if (!$typeId) {
        return '#6c757d';
    }
    return $safeColor($calendarColors[($typeId - 1) % $calendarColorCount]);
};
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
?>
<?= $this->element('cdn_select2') ?>

<!-- ═══ Header ═══ -->
<div class="d-flex justify-content-between align-items-start" style="margin-bottom:18px;">
    <div>
        <span class="spi-page-title">Novedades Vigentes</span>
    </div>
</div>

<!-- ═══ Sub-tabs (scope) ═══ -->
<div class="d-flex" style="gap:4px;margin-bottom:12px;">
    <?= $this->Html->link('Mis Novedades · ' . (int)$tabCounts['mis'], ['action' => 'index'],
        ['class' => 'chip']) ?>
    <?= $this->Html->link('Todas las Novedades · ' . (int)$tabCounts['todas'], ['action' => 'all'],
        ['class' => 'chip']) ?>
    <?= $this->Html->link('Rechazadas · ' . (int)$tabCounts['rechazadas'], ['action' => 'rejected'],
        ['class' => 'chip']) ?>
    <?= $this->Html->link(
        '<span class="dot" style="background:var(--primary-color);"></span>Vigentes · ' . (int)$tabCounts['vigentes'],
        ['action' => 'active'],
        ['class' => 'chip is-active', 'escape' => false, 'style' => 'color:var(--primary-color)']
    ) ?>
</div>

<!-- Filters -->
<div class="spi-card compact" style="margin-bottom:14px;">
    <div class="d-flex gap-3 align-items-center flex-wrap">
            <select id="filter-novelty-type" class="form-select form-select-sm select2-enable" style="max-width:220px;" data-placeholder="Tipo: Todos">
                <option value="">Tipo: Todos</option>
                <?php foreach ($noveltyTypes as $id => $name): ?>
                    <option value="<?= $id ?>"><?= h($name) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filter-employee" class="form-select form-select-sm select2-enable" style="max-width:260px;" data-placeholder="Empleado: Todos">
                <option value="">Empleado: Todos</option>
                <?php foreach ($employees as $id => $name): ?>
                    <option value="<?= $id ?>"><?= h($name) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" id="btn-clear-filters" class="btn btn-ghost btn-sm" style="display:none;">
                <i class="bi bi-x me-1" aria-hidden="true"></i>Limpiar
            </button>
    </div>
</div>

<!-- Calendar -->
<div class="spi-card" style="padding:0;">
    <div id="calendar" class="spi-calendar"></div>
    <?php if (!empty($noveltyTypes)): ?>
    <div class="spi-cal-legend">
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

<?= $this->element('fullcalendar_assets') ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    SPICalendar.init({
        el: '#calendar',
        eventsUrl: '/employee-novelties/active-events',
        filters: {
            novelty_type_id: '#filter-novelty-type',
            employee_id:     '#filter-employee'
        },
        clearBtn: '#btn-clear-filters'
    });
});
</script>

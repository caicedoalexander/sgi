<?php
/**
 * @var \App\View\AppView $this
 * @var array $noveltyTypes
 * @var array $employees
 */
$this->assign('title', 'Novedades Vigentes');
?>
<?= $this->element('cdn_select2') ?>

<!-- ═══ Header ═══ -->
<div class="d-flex justify-content-between align-items-start" style="margin-bottom:18px;">
    <div>
        <span class="sgi-page-title">Novedades Vigentes</span>
    </div>
</div>

<!-- ═══ Sub-tabs (scope) ═══ -->
<div class="d-flex" style="gap:4px;margin-bottom:12px;">
    <?= $this->Html->link('Mis Novedades', ['action' => 'index'],
        ['class' => 'chip']) ?>
    <?= $this->Html->link('Todas', ['action' => 'all'],
        ['class' => 'chip']) ?>
    <?= $this->Html->link('Rechazadas', ['action' => 'rejected'],
        ['class' => 'chip']) ?>
    <?= $this->Html->link(
        '<span class="dot" style="background:var(--primary-color);"></span>Vigentes',
        ['action' => 'active'],
        ['class' => 'chip is-active', 'escape' => false, 'style' => 'color:var(--primary-color)']
    ) ?>
</div>

<!-- Filters -->
<div class="sgi-card compact" style="margin-bottom:14px;">
    <div class="d-flex gap-3 align-items-center flex-wrap">
            <select id="filter-novelty-type" class="form-select form-select-sm select2" style="max-width:220px;" data-placeholder="Tipo: Todos">
                <option value="">Tipo: Todos</option>
                <?php foreach ($noveltyTypes as $id => $name): ?>
                    <option value="<?= $id ?>"><?= h($name) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filter-employee" class="form-select form-select-sm select2" style="max-width:260px;" data-placeholder="Empleado: Todos">
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
<div class="sgi-card" style="padding:0;">
    <div id="calendar" class="sgi-calendar"></div>
</div>

<?= $this->element('fullcalendar_assets') ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    SGICalendar.init({
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

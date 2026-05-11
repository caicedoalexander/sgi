<?php
/**
 * @var \App\View\AppView $this
 * @var array $noveltyTypes
 * @var array $employees
 */
$this->assign('title', 'Novedades Vigentes');
?>
<?= $this->element('cdn_select2') ?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Novedades Vigentes</span>
</div>

<!-- View navigation (consistent with index.php) -->
<div class="d-flex gap-2 mb-3">
    <?= $this->Html->link('Mis Novedades', ['action' => 'index'],
        ['class' => 'btn btn-sm btn-outline-dark']) ?>
    <?= $this->Html->link('Todas las Novedades', ['action' => 'all'],
        ['class' => 'btn btn-sm btn-outline-dark']) ?>
    <?= $this->Html->link('Rechazadas', ['action' => 'rejected'],
        ['class' => 'btn btn-sm btn-outline-danger']) ?>
    <?= $this->Html->link('Vigentes', ['action' => 'active'],
        ['class' => 'btn btn-sm btn-primary']) ?>
</div>

<!-- Filters -->
<div class="card card-primary mb-3">
    <div class="card-body py-2 px-3">
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
            <button type="button" id="btn-clear-filters" class="btn btn-sm btn-outline-secondary" style="display:none;">
                <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Limpiar
            </button>
        </div>
    </div>
</div>

<!-- Calendar -->
<div class="card card-dark">
    <div class="card-body p-0">
        <div id="calendar" class="sgi-calendar"></div>
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

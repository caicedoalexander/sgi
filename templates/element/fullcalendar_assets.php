<?php
/**
 * Bundle de assets de FullCalendar v6 — incluir en cualquier vista que
 * renderice un `<div class="sgi-calendar">`.
 *
 * Carga:
 * - Core de FullCalendar (v6 incluye su CSS dentro del JS bundle).
 * - Locale español.
 * - CSS del proyecto para overrides visuales del calendario (sgi-calendar).
 * - `sgi-calendar.js` con el wrapper SGICalendar.init() que orquesta filtros.
 *
 * Las dos vistas que actualmente lo consumen:
 * - templates/EmployeeNovelties/index.php   (vista "all" — calendario opcional)
 * - templates/EmployeeNovelties/active.php  (calendario único de vigentes)
 *
 * @var \App\View\AppView $this
 */
?>
<script defer src="<?= $this->Url->build('/vendor/fullcalendar/index.global.min.js') ?>"></script>
<script defer src="<?= $this->Url->build('/vendor/fullcalendar/locales/es.global.min.js') ?>"></script>
<?= $this->Html->css('sgi-calendar') ?>
<script defer src="<?= $this->Url->build('/js/sgi-calendar.js') ?>"></script>

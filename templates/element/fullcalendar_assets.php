<?php
/**
 * Bundle de assets de FullCalendar v6 — incluir en cualquier vista que
 * renderice un `<div class="spi-calendar">`.
 *
 * Carga:
 * - Core de FullCalendar (v6 incluye su CSS dentro del JS bundle).
 * - Locale español.
 * - CSS del proyecto para overrides visuales del calendario (spi-calendar).
 * - `spi-calendar.js` con el wrapper SPICalendar.init() que orquesta filtros.
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
<?= $this->Html->css('spi-calendar') ?>
<script defer src="<?= $this->Url->build('/js/spi-calendar.js') ?>"></script>

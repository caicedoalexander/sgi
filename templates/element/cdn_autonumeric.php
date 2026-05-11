<?php
/**
 * Carga AutoNumeric bajo demanda (formularios con .currency-input).
 *
 * Self-hosted desde webroot/vendor/ — see MJ-005 paso 4.
 * El nombre del element conserva "cdn_" por compatibilidad con includes
 * históricos pero la fuente ya es local.
 *
 * @var \App\View\AppView $this
 */
$this->Html->script(
    $this->Url->build('/vendor/autonumeric/autoNumeric.min.js'),
    ['block' => 'script', 'defer' => true],
);

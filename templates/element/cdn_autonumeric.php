<?php
/**
 * Carga AutoNumeric desde CDN bajo demanda (formularios con .currency-input).
 *
 * Uso:
 *     <?= $this->element('cdn_autonumeric') ?>
 *
 * El script se inyecta en el bloque 'script' del layout (al final del <body>)
 * con defer + SRI. `webroot/js/sgi-common.js` lo detecta en runtime dentro de
 * DOMContentLoaded.
 *
 * @var \App\View\AppView $this
 */
$this->Html->script(
    'https://cdn.jsdelivr.net/npm/autonumeric@4.10.5/dist/autoNumeric.min.js',
    [
        'block' => 'script',
        'defer' => true,
        'integrity' => 'sha384-+xRXcGmExqvIzpl6UBfbrBkXyyxIDFnxQtfyoOiXSx0/ri19w6ifNhXjPLMxLwXM',
        'crossorigin' => 'anonymous',
    ],
);

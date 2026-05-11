<?php
/**
 * Carga Select2 + jQuery + locale es desde CDN bajo demanda.
 *
 * Uso:
 *     <?= $this->element('cdn_select2') ?>
 *
 * Carga 4 recursos:
 *   - select2.min.css   (en bloque 'css' del layout, dentro del <head>)
 *   - jquery.min.js     (defer, bloque 'script')
 *   - select2.min.js    (defer, bloque 'script', depende de jQuery)
 *   - select2/i18n/es.js (defer, bloque 'script')
 *
 * Select2 depende de jQuery: gracias a `defer`, los scripts se ejecutan en el
 * orden de aparición ANTES de DOMContentLoaded, así que jQuery siempre estará
 * disponible cuando Select2 se evalúe. `webroot/js/sgi-common.js` inicializa
 * `.select2-enable` dentro de DOMContentLoaded — para entonces ambos están listos.
 *
 * @var \App\View\AppView $this
 */
$this->Html->css(
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
    [
        'block' => 'css',
        'integrity' => 'sha384-OXVF05DQEe311p6ohU11NwlnX08FzMCsyoXzGOaL+83dKAb3qS17yZJxESl8YrJQ',
        'crossorigin' => 'anonymous',
    ],
);
$this->Html->script(
    'https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js',
    [
        'block' => 'script',
        'defer' => true,
        'integrity' => 'sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs',
        'crossorigin' => 'anonymous',
    ],
);
$this->Html->script(
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
    [
        'block' => 'script',
        'defer' => true,
        'integrity' => 'sha384-d3UHjPdzJkZuk5H3qKYMLRyWLAQBJbby2yr2Q58hXXtAGF8RSNO9jpLDlKKPv5v3',
        'crossorigin' => 'anonymous',
    ],
);
$this->Html->script(
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/es.js',
    [
        'block' => 'script',
        'defer' => true,
        'integrity' => 'sha384-UeeDJLxU9E9sJVeeJ8aoFWAfW2uB0Hggm59b4wtvZl8A1FnbmWnAaH4DlLdlnHKD',
        'crossorigin' => 'anonymous',
    ],
);

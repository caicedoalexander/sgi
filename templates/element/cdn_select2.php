<?php
/**
 * Carga Select2 + jQuery + locale es bajo demanda.
 *
 * Self-hosted desde webroot/vendor/ — see MJ-005 paso 4.
 *
 * Carga 4 recursos:
 *   - select2.min.css   (en bloque 'css' del layout, dentro del <head>)
 *   - jquery.min.js     (defer, bloque 'script')
 *   - select2.min.js    (defer, bloque 'script', depende de jQuery)
 *   - select2/i18n/es.js (defer, bloque 'script')
 *
 * Select2 depende de jQuery: gracias a `defer`, los scripts se ejecutan en el
 * orden de aparición ANTES de DOMContentLoaded, así que jQuery siempre estará
 * disponible cuando Select2 se evalúe. `webroot/js/spi-common.js` inicializa
 * `.select2-enable` dentro de DOMContentLoaded — para entonces ambos están listos.
 *
 * @var \App\View\AppView $this
 */
$this->Html->css(
    $this->Url->build('/vendor/select2/select2.min.css'),
    ['block' => 'css'],
);
$this->Html->css('spi-select2-overrides', ['block' => 'css']);
$this->Html->script(
    $this->Url->build('/vendor/jquery/jquery.min.js'),
    ['block' => 'script', 'defer' => true],
);
$this->Html->script(
    $this->Url->build('/vendor/select2/select2.min.js'),
    ['block' => 'script', 'defer' => true],
);
$this->Html->script(
    $this->Url->build('/vendor/select2/i18n/es.js'),
    ['block' => 'script', 'defer' => true],
);

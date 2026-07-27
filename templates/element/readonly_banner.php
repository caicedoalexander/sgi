<?php
/**
 * Banner genérico de solo lectura para las vistas de trabajo de pipeline.
 * Se muestra cuando el rol puede abrir la vista pero no operar el paso actual.
 *
 * Usa la variante `.banner info` del sistema de diseño (ver docs/design/overlays.md).
 * El espaciado lo aporta el contenedor flex del consumidor, no este element.
 *
 * @var \App\View\AppView $this
 * @var string $stepLabel Etiqueta en español del paso actual (ej. "Contabilidad").
 */
?>
<div class="banner info">
    <div class="banner-icon"><i class="bi bi-info-circle-fill" aria-hidden="true"></i></div>
    <div class="banner-body">
        <div class="banner-title">Solo lectura</div>
        <div class="banner-msg">
            Sin permisos para operar el paso <strong><?= h($stepLabel) ?></strong>.
        </div>
    </div>
</div>

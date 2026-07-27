<?php
/**
 * Shell del modal de CRUD de catálogo. Se incluye UNA vez en el index del
 * catálogo. El contenido (form de add/edit) lo inyecta SpiCatalogModal vía AJAX
 * desde los triggers con [data-catalog-modal].
 *
 * @var \App\View\AppView $this
 * @var bool $withSelect2 Si el form del modal usa selects buscables (select2-enable),
 *   carga jQuery+Select2 en el index (el modal se inyecta por AJAX, así que la lib
 *   debe estar presente antes de abrirlo). Default false (regla select2 selectiva).
 */
$withSelect2 = $withSelect2 ?? false;
$this->Html->script('spi-catalog-modal', ['block' => 'script', 'defer' => true]);
if ($withSelect2) {
    echo $this->element('cdn_select2');
}
?>
<div class="modal fade" id="catalogModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content" id="catalogModalContent">
            <!-- inyectado por SpiCatalogModal -->
        </div>
    </div>
</div>

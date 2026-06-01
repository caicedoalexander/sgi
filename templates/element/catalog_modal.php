<?php
/**
 * Shell del modal de CRUD de catálogo. Se incluye UNA vez en el index del
 * catálogo. El contenido (form de add/edit) lo inyecta SgiCatalogModal vía AJAX
 * desde los triggers con [data-catalog-modal].
 *
 * @var \App\View\AppView $this
 */
$this->Html->script('sgi-catalog-modal', ['block' => 'script', 'defer' => true]);
?>
<div class="modal fade" id="catalogModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content" id="catalogModalContent">
            <!-- inyectado por SgiCatalogModal -->
        </div>
    </div>
</div>

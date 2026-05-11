<?php
/**
 * Encabezado de página de Invoices/edit: título + botones Volver/Ver.
 * Cambia rutas y label según si es Anticipo o Factura.
 *
 * @var \App\View\AppView $this
 * @var \App\ViewModel\InvoiceEditViewModel $viewModel
 * @var bool $isAdvance
 */
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title"><?= $isAdvance ? 'Editar Anticipo' : 'Editar Factura' ?></span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
            $isAdvance ? ['controller' => 'Advances', 'action' => 'index'] : ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-eye me-1" aria-hidden="true"></i>Ver',
            $isAdvance ? ['controller' => 'Advances', 'action' => 'view', $viewModel->invoice->id] : ['action' => 'view', $viewModel->invoice->id],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
    </div>
</div>

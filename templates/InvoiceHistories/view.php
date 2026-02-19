<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\InvoiceHistory $invoiceHistory
 */
$this->assign('title', 'Detalle de Cambio #' . $invoiceHistory->id);
?>
<div class="mb-4">
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>

<div class="card shadow-sm">
    <div class="card-header"><h5 class="mb-0">Detalle del Cambio</h5></div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">ID</dt>
            <dd class="col-sm-9"><?= $this->Number->format($invoiceHistory->id) ?></dd>
            <dt class="col-sm-3">Factura</dt>
            <dd class="col-sm-9"><?= $this->Html->link('#' . $invoiceHistory->invoice_id, ['controller' => 'Invoices', 'action' => 'view', $invoiceHistory->invoice_id]) ?></dd>
            <dt class="col-sm-3">Usuario</dt>
            <dd class="col-sm-9"><?= $invoiceHistory->hasValue('user') ? h($invoiceHistory->user->full_name) : '' ?></dd>
            <dt class="col-sm-3">Campo Modificado</dt>
            <dd class="col-sm-9"><code><?= h($invoiceHistory->field_changed) ?></code></dd>
            <dt class="col-sm-3">Valor Anterior</dt>
            <dd class="col-sm-9 text-muted"><?= h($invoiceHistory->old_value) ?: '—' ?></dd>
            <dt class="col-sm-3">Valor Nuevo</dt>
            <dd class="col-sm-9 fw-semibold"><?= h($invoiceHistory->new_value) ?: '—' ?></dd>
            <dt class="col-sm-3">Fecha</dt>
            <dd class="col-sm-9"><?= $invoiceHistory->created?->format('d/m/Y H:i:s') ?></dd>
        </dl>
    </div>
</div>

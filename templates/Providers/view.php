<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Provider $provider
 */
$this->assign('title', 'Proveedor: ' . $provider->name);
?>
<div class="mb-4">
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header"><h5 class="mb-0">Detalle del Proveedor</h5></div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">ID</dt>
            <dd class="col-sm-9"><?= $this->Number->format($provider->id) ?></dd>
            <dt class="col-sm-3">NIT</dt>
            <dd class="col-sm-9"><code><?= h($provider->nit) ?></code></dd>
            <dt class="col-sm-3">Nombre</dt>
            <dd class="col-sm-9"><?= h($provider->name) ?></dd>
            <dt class="col-sm-3">Estado</dt>
            <dd class="col-sm-9"><?= $provider->active ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></dd>
            <dt class="col-sm-3">Creado</dt>
            <dd class="col-sm-9"><?= $provider->created?->format('d/m/Y H:i') ?></dd>
            <dt class="col-sm-3">Modificado</dt>
            <dd class="col-sm-9"><?= $provider->modified?->format('d/m/Y H:i') ?></dd>
        </dl>
    </div>
    <div class="card-footer">
        <?= $this->Html->link('<i class="bi bi-pencil me-1"></i>Editar', ['action' => 'edit', $provider->id], ['class' => 'btn btn-warning btn-sm', 'escape' => false]) ?>
        <?= $this->Form->postLink('<i class="bi bi-trash me-1"></i>Eliminar', ['action' => 'delete', $provider->id], ['confirm' => '¿Está seguro?', 'class' => 'btn btn-danger btn-sm', 'escape' => false]) ?>
    </div>
</div>

<?php if (!empty($provider->invoices)): ?>
<div class="card shadow-sm">
    <div class="card-header"><h5 class="mb-0">Facturas del Proveedor</h5></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Tipo</th>
                    <th>Fecha Emisión</th>
                    <th>Valor</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($provider->invoices as $invoice): ?>
                <tr>
                    <td><?= $this->Html->link($invoice->id, ['controller' => 'Invoices', 'action' => 'view', $invoice->id]) ?></td>
                    <td><?= h($invoice->document_type) ?></td>
                    <td><?= $invoice->issue_date?->format('d/m/Y') ?></td>
                    <td class="text-end">$ <?= $this->Number->format($invoice->amount, ['places' => 2]) ?></td>
                    <td><span class="badge bg-info"><?= h($invoice->pipeline_status) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

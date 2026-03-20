<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Provider> $providers
 */
$this->assign('title', 'Proveedores');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Proveedores</span>
    <div class="d-flex gap-2">
        <?= $this->element('catalog_excel_buttons') ?>
        <?php if (!empty($userPermissions['providers']['can_create'])): ?>
        <?= $this->Html->link('<i class="bi bi-plus-lg me-1"></i>Nuevo Proveedor', ['action' => 'add'], ['class' => 'btn btn-primary', 'escape' => false]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th><?= $this->Paginator->sort('id', '#') ?></th>
                    <th><?= $this->Paginator->sort('document_type', 'Tipo Doc.') ?></th>
                    <th><?= $this->Paginator->sort('document_number', 'Número Doc.') ?></th>
                    <th><?= $this->Paginator->sort('name', 'Nombre') ?></th>
                    <th><?= $this->Paginator->sort('active', 'Estado') ?></th>
                    <th><?= $this->Paginator->sort('created', 'Creado') ?></th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($providers as $provider): ?>
                <tr>
                    <td><?= $this->Number->format($provider->id) ?></td>
                    <td><span class="badge bg-secondary"><?= h($provider->document_type) ?></span></td>
                    <td><code><?= h($provider->document_number) ?></code></td>
                    <td><?= h($provider->name) ?></td>
                    <td><?= $provider->active ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
                    <td><?= $provider->created?->format('d/m/Y H:i') ?></td>
                    <td class="text-end">
                        <?= $this->Html->link('<i class="bi bi-eye"></i>', ['action' => 'view', $provider->id], ['class' => 'btn btn-sm btn-outline-info', 'escape' => false, 'title' => 'Ver']) ?>
                        <?php if (!empty($userPermissions['providers']['can_edit'])): ?>
                        <?= $this->Html->link('<i class="bi bi-pencil"></i>', ['action' => 'edit', $provider->id], ['class' => 'btn btn-sm btn-outline-warning', 'escape' => false, 'title' => 'Editar']) ?>
                        <?php endif; ?>
                        <?php if (!empty($userPermissions['providers']['can_delete'])): ?>
                        <?= $this->Form->postLink('<i class="bi bi-trash"></i>', ['action' => 'delete', $provider->id], ['confirm' => '¿Está seguro de eliminar este proveedor?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $this->element('pagination') ?>
</div>

<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\CostCenter> $costCenters
 */
$this->assign('title', 'Centros de Costos');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Centros de Costos</span>
    <div class="d-flex gap-2">
        <?= $this->element('excel_wizard/buttons', [
            'module' => 'CostCenters',
            'importable' => true,
            'canCreate' => !empty($userPermissions['cost_centers']['can_create']),
        ]) ?>
        <?php if (!empty($userPermissions['cost_centers']['can_create'])): ?>
        <?= $this->Html->link('<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Centro', ['action' => 'add'], ['class' => 'btn btn-primary', 'escape' => false]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th><?= $this->Paginator->sort('id', '#') ?></th>
                    <th><?= $this->Paginator->sort('code', 'Código') ?></th>
                    <th><?= $this->Paginator->sort('name', 'Nombre') ?></th>
                    <th><?= $this->Paginator->sort('created', 'Creado') ?></th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($costCenters as $costCenter): ?>
                <tr>
                    <td><?= $this->Number->format($costCenter->id) ?></td>
                    <td><code><?= h($costCenter->code ?? '-') ?></code></td>
                    <td><?= h($costCenter->name) ?></td>
                    <td><?= $costCenter->created?->format('d/m/Y H:i') ?></td>
                    <td class="text-end">
                        <?= $this->Html->link('<i class="bi bi-eye" aria-hidden="true"></i>', ['action' => 'view', $costCenter->id], ['class' => 'btn btn-sm btn-outline-info', 'escape' => false, 'title' => 'Ver']) ?>
                        <?php if (!empty($userPermissions['cost_centers']['can_edit'])): ?>
                        <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>', ['action' => 'edit', $costCenter->id], ['class' => 'btn btn-sm btn-outline-warning', 'escape' => false, 'title' => 'Editar']) ?>
                        <?php endif; ?>
                        <?php if (!empty($userPermissions['cost_centers']['can_delete'])): ?>
                        <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>', ['action' => 'delete', $costCenter->id], ['confirm' => '¿Está seguro de eliminar este centro de costos?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $this->element('pagination') ?>
</div>

<?= $this->element('excel_wizard/modals', [
    'module' => 'CostCenters',
    'entityName' => 'Centros de Costos',
    'downloadSlug' => 'centros_costos',
    'importable' => true,
]) ?>

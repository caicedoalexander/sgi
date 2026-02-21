<?php
/**
 * Reusable Excel export/import buttons for catalog pages.
 *
 * @var \App\View\AppView $this
 * @var string $controller The controller name (e.g., 'CostCenters')
 */
$controller = $controller ?? $this->request->getParam('controller');
?>
<div class="d-flex gap-2">
    <?= $this->Html->link(
        '<i class="bi bi-download me-1"></i>Exportar Excel',
        ['controller' => $controller, 'action' => 'export'],
        ['class' => 'btn btn-outline-success btn-sm', 'escape' => false]
    ) ?>
    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importExcelModal">
        <i class="bi bi-upload me-1"></i>Importar Excel
    </button>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importExcelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <?= $this->Form->create(null, [
                'url' => ['controller' => $controller, 'action' => 'import'],
                'type' => 'file',
            ]) ?>
            <div class="modal-header">
                <h5 class="modal-title">Importar desde Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p style="font-size:.85rem;color:#666;">
                    El archivo debe ser .xlsx con una columna <code>code</code> como identificador.
                    Los registros existentes se actualizarán, los nuevos se crearán.
                </p>
                <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Importar</button>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>

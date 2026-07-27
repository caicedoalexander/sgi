<?php
/**
 * Modales de movimientos de un activo. Cada uno postea a su acción del
 * AssetsController. Selects en texto plano (form-select) para funcionar dentro
 * del modal sin inicializar select2.
 *
 * @var \App\View\AppView $this
 * @var \App\ViewModel\AssetViewViewModel $viewModel
 */
$asset = $viewModel->asset;
$assetId = $asset->id;

$modal = function (string $id, string $title, string $action, callable $body) use ($assetId): string {
    $html = '<div class="modal fade" id="' . h($id) . '" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">';
    $html .= $this->Form->create(null, ['url' => ['action' => $action, $assetId]]);
    $html .= '<div class="modal-header"><h5 class="modal-title">' . h($title) . '</h5>';
    $html .= '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>';
    $html .= '<div class="modal-body">' . $body() . '</div>';
    $html .= '<div class="modal-footer"><button type="button" class="btn btn-ghost-card" data-bs-dismiss="modal">Cancelar</button>';
    $html .= '<button type="submit" class="btn btn-primary">Confirmar</button></div>';
    $html .= $this->Form->end();
    $html .= '</div></div></div>';

    return $html;
};

$employeeSelect = function (string $label) use ($viewModel): string {
    return $this->Form->control('to_employee_id', [
        'options' => $viewModel->employees,
        'empty' => 'Seleccione…',
        'class' => 'form-select',
        'required' => true,
        'label' => ['text' => $label, 'class' => 'input-label'],
    ]);
};

$reasonField = function (): string {
    return $this->Form->control('reason', [
        'type' => 'textarea', 'rows' => 2, 'class' => 'form-control',
        'label' => ['text' => 'Motivo / observación', 'class' => 'input-label'],
    ]);
};
?>
<?= $modal('modal-assign', 'Asignar activo', 'assign', fn() => $employeeSelect('Responsable') . $reasonField()) ?>
<?= $modal('modal-lend', 'Prestar activo', 'lend', fn() => $employeeSelect('Responsable') . $reasonField()) ?>
<?= $modal('modal-return', 'Devolver activo', 'returnAsset', fn() => $reasonField()) ?>
<?= $modal('modal-transfer', 'Trasladar activo', 'transfer', fn() => $this->Form->control('to_operation_center_id', [
    'options' => $viewModel->operationCenters, 'empty' => 'Seleccione…', 'class' => 'form-select', 'required' => true,
    'label' => ['text' => 'Centro de operación destino', 'class' => 'input-label'],
]) . $reasonField()) ?>
<?= $modal('modal-dispose', 'Dar de baja', 'dispose', fn() => '<p class="text-muted">Esta acción es irreversible.</p>' . $reasonField()) ?>

<?php
/**
 * Alerta amarilla "Para avanzar al siguiente estado complete..."
 * con la lista de campos faltantes. Solo se muestra cuando el
 * usuario puede avanzar y hay errores de validación bloqueando.
 *
 * @var \App\View\AppView $this
 * @var \App\ViewModel\InvoiceEditViewModel $viewModel
 */
if (!$viewModel->canAdvance || $viewModel->isRejected || empty($viewModel->advanceErrors)) {
    return;
}
?>
<div class="alert alert-warning mb-4">
    <div class="d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
        <div>
            <strong>Para avanzar al siguiente estado complete:</strong>
            <ul class="mb-0 mt-1 ps-3">
                <?php foreach ($viewModel->advanceErrors as $err): ?>
                    <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

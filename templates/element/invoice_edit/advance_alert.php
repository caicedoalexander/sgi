<?php
/**
 * Banner "Para avanzar al siguiente estado complete..." con la lista
 * de campos faltantes. Solo aparece cuando el usuario puede avanzar
 * y hay errores de validación bloqueando.
 *
 * Estilo: soft warning bg + border-left 3px (sin border-radius).
 * Alineado al spec del Sistema de Diseño v2.
 *
 * @var \App\View\AppView $this
 * @var \App\ViewModel\InvoiceEditViewModel $viewModel
 */
if (!$viewModel->canAdvance || $viewModel->isRejected || empty($viewModel->advanceErrors)) {
    return;
}
$nextLabel = $viewModel->nextStatus !== null
    ? ($viewModel->pipelineLabels[$viewModel->nextStatus] ?? $viewModel->nextStatus)
    : null;
?>
<div class="sgi-edit-banner">
    <i class="bi bi-exclamation-triangle-fill bi-banner" aria-hidden="true"></i>
    <div style="flex:1;min-width:0;">
        <div class="sgi-edit-banner-title">
            <?php if ($nextLabel): ?>
                Para avanzar a <span style="color:var(--warning-text);"><?= h($nextLabel) ?></span> debe completar:
            <?php else: ?>
                Para avanzar al siguiente estado debe completar:
            <?php endif; ?>
        </div>
        <ul>
            <?php foreach ($viewModel->advanceErrors as $err): ?>
                <li><?= h($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

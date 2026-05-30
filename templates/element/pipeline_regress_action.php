<?php
/**
 * Botón de acción "Regresar al paso anterior" del sidebar de pipeline.
 * Renderiza el markup que el consumidor pasa como `actionsHtml` a element('pipeline_sidebar').
 * El consumidor debe envolver la llamada en una guarda `!empty($canRegress)`.
 *
 * @var \App\View\AppView $this
 * @var string $previousStatus
 * @var array<string,string> $pipelineLabels
 * @var string|null $regressLockMessage
 */
$prevLabel = $pipelineLabels[$previousStatus] ?? $previousStatus;
$isLocked  = !empty($regressLockMessage);
?>
<?php if ($isLocked): ?>
    <button type="button" class="btn" disabled title="<?= h($regressLockMessage) ?>">
        <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Regresar al paso anterior
    </button>
<?php else: ?>
    <button type="button" class="btn"
            data-bs-toggle="modal" data-bs-target="#regressStatusModal">
        <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Regresar a: <?= h($prevLabel) ?>
    </button>
<?php endif; ?>

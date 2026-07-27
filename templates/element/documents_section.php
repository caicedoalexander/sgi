<?php
/**
 * Sección de Soportes — element compartido. Card `.spi-card` con header
 * (contador + botón "Subir" opcional), empty state (dropzone si hay subida
 * habilitada, si no `.empty-state`) y lista de `document_row` agrupada
 * opcionalmente por estado.
 *
 * Conserva los IDs #docs-list, #docs-empty-state, #docs-folder-count (contrato
 * de webroot/js/spi-document-uploader.js). El host emite aparte el
 * `document_row_template` cuando hay subida.
 *
 * @var \App\View\AppView $this
 * @var array   $groups        Lista de grupos. Cada grupo:
 *                              ['label'=>?string, 'pillKind'=>?string, 'rows'=>array].
 *                              Cada row es el array de params de element('document_row').
 * @var int     $totalDocs     Conteo total para el .spi-folder-count.
 * @var bool    $canUpload     true → empty state como .dropzone; false → .empty-state.
 * @var ?string $uploadModalId Id del modal de subida (target del botón y la dropzone).
 * @var ?string $emptyTitle    Título del empty state. Default: "Sin soportes adjuntos".
 */
$groups        = $groups ?? [];
$totalDocs     = (int)($totalDocs ?? 0);
$canUpload     = $canUpload ?? false;
$uploadModalId = $uploadModalId ?? null;
$emptyTitle    = $emptyTitle ?? 'Sin soportes adjuntos';

$hasDocs = false;
foreach ($groups as $g) {
    if (!empty($g['rows'])) { $hasDocs = true; break; }
}
$showUpload = $canUpload && $uploadModalId !== null;
?>
<div class="spi-card card d-flex flex-column">
    <div class="d-flex align-items-center justify-content-between" style="margin-bottom:12px;">
        <span class="spi-label d-inline-flex align-items-center gap-2">
            <i class="bi bi-paperclip" aria-hidden="true"></i>
            Soportes
            <span id="docs-folder-count" class="spi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
        </span>
        <?php if ($showUpload): ?>
        <button type="button" class="btn btn-default btn-sm"
                data-bs-toggle="modal" data-bs-target="#<?= h($uploadModalId) ?>">
            <i class="bi bi-upload" aria-hidden="true"></i>Subir
        </button>
        <?php endif; ?>
    </div>

    <?php if ($showUpload): ?>
    <div id="docs-empty-state" class="dropzone"
         data-bs-toggle="modal" data-bs-target="#<?= h($uploadModalId) ?>"
         style="cursor:pointer;<?= $hasDocs ? 'display:none;' : '' ?>">
        <i class="bi bi-paperclip" aria-hidden="true"></i>
        <div>Arrastra archivos o <a class="dz-link">examina</a></div>
        <div class="dz-hint">PDF, JPG, PNG · máximo 10 MB por archivo</div>
    </div>
    <?php else: ?>
    <div id="docs-empty-state" class="empty-state" <?= $hasDocs ? 'style="display:none;"' : '' ?>>
        <div class="es-icon es-icon-neutral">
            <i class="bi bi-paperclip" aria-hidden="true"></i>
        </div>
        <div class="es-title"><?= h($emptyTitle) ?></div>
    </div>
    <?php endif; ?>

    <div id="docs-list" style="max-height:420px;overflow-y:auto;">
        <?php foreach ($groups as $group): ?>
            <?php if (!empty($group['label'])): ?>
            <div class="d-flex align-items-center gap-2"
                 style="padding:.3rem .5rem;background:var(--bg-subtle);margin-top:.5rem;">
                <span class="pill <?= h($group['pillKind'] ?? 'pill-muted') ?>"><?= h($group['label']) ?></span>
                <span style="font-size:var(--fs-label);color:var(--text-faint);">
                    <?= count($group['rows'] ?? []) ?> archivo<?= count($group['rows'] ?? []) !== 1 ? 's' : '' ?>
                </span>
            </div>
            <?php endif; ?>
            <?php foreach ($group['rows'] ?? [] as $rowParams): ?>
                <?= $this->element('document_row', $rowParams) ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</div>

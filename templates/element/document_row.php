<?php
/**
 * Document row partial — used by Invoices, PettyCashRecords, EmployeeNovelties,
 * NoveltyLiquidationDocs for both initial server render and as the structural
 * twin of `templates/element/document_row_template.php` (consumed by
 * `webroot/js/sgi-document-uploader.js`).
 *
 * Visual pattern (design.md §15 · Gestión documental — "Fila de documento"):
 *   icono bi-file-earmark-* + nombre/archivo + pill de estado + btn-icon (28×28).
 *   Mantener IDÉNTICO a document_row_template.php.
 *
 * IMPORTANT: keep the markup, CSS classes, the `.doc-row` class, the
 * `.doc-delete-btn` class, the `data-doc-id` attribute and the `data-slot`
 * keys in sync between:
 *   - templates/element/document_row.php           (this file, server render)
 *   - templates/element/document_row_template.php  (<template> for JS clone)
 *   - webroot/js/sgi-document-uploader.js          (slot consumer)
 * The JS reads slots: label, filename, badge, created, size, open-link,
 * delete-btn — and selectors `.doc-row`, `.doc-icon i`, `.doc-delete-btn`.
 *
 * Required: $doc (entity with id, file_name, document_type, mime_type, file_path,
 *           file_size, created, pipeline_status [optional]).
 * Optional: $canDelete (bool, default false)
 *           $deleteUrl (string, required if $canDelete)
 *           $showBadge (bool, default false) — show pipeline_status badge
 *           $badgeColors (array<string,string>) — required if $showBadge
 *           $statusLabels (array<string,string>) — required if $showBadge
 *
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\EntityInterface $doc
 * @var bool $canDelete
 * @var string $deleteUrl
 * @var bool $showBadge
 * @var array<string,string> $badgeColors
 * @var array<string,string> $statusLabels
 */
$canDelete    = $canDelete    ?? false;
$showBadge    = $showBadge    ?? false;
$badgeColors  = $badgeColors  ?? [];
$statusLabels = $statusLabels ?? [];
$deleteUrl    = $deleteUrl    ?? '';

$mime = $doc->mime_type ?? '';
$icon = $this->DocumentIcon->iconClass($mime);
$iconColor = $this->DocumentIcon->iconColor($mime);

$label = $doc->document_type ?: $doc->file_name;
$badgeClass = $badgeColors[$doc->pipeline_status ?? ''] ?? 'pill-secondary-soft';
$badgeLabel = $statusLabels[$doc->pipeline_status ?? ''] ?? ($doc->pipeline_status ?? '');
?>
<div class="doc-row row-flex gap-12" data-doc-id="<?= h($doc->id) ?>"
     style="padding:10px 12px;background:var(--bg-muted);margin-bottom:6px;">
    <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
        <i class="bi <?= h($icon) ?>" style="color:<?= h($iconColor) ?>;font-size:18px;" aria-hidden="true"></i>
    </div>
    <div class="grow">
        <div data-slot="label" title="<?= h($label) ?>"
             style="font-size:var(--fs-body);font-weight:600;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($label) ?></div>
        <div class="row-flex gap-6 mono sgi-body-faint" style="margin-top:2px;">
            <?php if ($doc->document_type): ?>
            <span data-slot="filename" title="<?= h($doc->file_name) ?>"
                  style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($doc->file_name) ?></span>
            <?php else: ?>
            <span data-slot="filename" style="display:none;"></span>
            <?php endif; ?>
            <?php if ($doc->file_size): ?>
            <span data-slot="size">· <?= $this->Number->toReadableSize($doc->file_size) ?></span>
            <?php else: ?>
            <span data-slot="size" style="display:none;"></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-flex gap-2" style="flex-shrink:0;text-align:right;">
        <span class="sgi-label">Cargado</span>
        <span class="mono" style="font-size:var(--fs-body-sm);" data-slot="created"><?= h($doc->created?->format('d/m/Y H:i')) ?></span>
    </div>
    <?php if ($showBadge): ?>
        <?php if ($doc->pipeline_status ?? null): ?>
        <span class="pill <?= h($badgeClass) ?>" data-slot="badge" style="flex-shrink:0;"><?= h($badgeLabel) ?></span>
        <?php else: ?>
        <span class="pill" data-slot="badge" style="display:none;flex-shrink:0;"></span>
        <?php endif; ?>
    <?php endif; ?>
    <div class="row-flex gap-4" style="flex-shrink:0;">
        <a class="btn-icon" data-slot="open-link"
           href="/<?= h($doc->file_path) ?>" target="_blank" rel="noopener noreferrer" title="Abrir">
            <i class="bi bi-eye" aria-hidden="true"></i>
        </a>
        <?php if ($canDelete): ?>
        <button type="button" class="btn-icon doc-delete-btn"
                data-slot="delete-btn" data-url="<?= h($deleteUrl) ?>" title="Eliminar">
            <i class="bi bi-trash" aria-hidden="true"></i>
        </button>
        <?php else: ?>
        <button type="button" class="btn-icon doc-delete-btn"
                data-slot="delete-btn" data-url="" style="display:none;" title="Eliminar">
            <i class="bi bi-trash" aria-hidden="true"></i>
        </button>
        <?php endif; ?>
    </div>
</div>

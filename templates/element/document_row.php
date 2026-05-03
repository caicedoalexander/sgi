<?php
/**
 * Document row partial — used by Invoices, PettyCashRecords, EmployeeNovelties,
 * NoveltyLiquidationDocs for both initial server render and as the structural
 * twin of `templates/element/document_row_template.php` (consumed by
 * `webroot/js/sgi-document-uploader.js`).
 *
 * IMPORTANT: keep the markup, CSS classes and `data-slot` keys in sync between:
 *   - templates/element/document_row.php           (this file, server render)
 *   - templates/element/document_row_template.php  (<template> for JS clone)
 *   - webroot/js/sgi-document-uploader.js          (slot consumer)
 *
 * Required: $doc (entity with id, file_name, document_type, mime_type, file_path,
 *           file_size, created, pipeline_status [optional]).
 * Optional: $canDelete (bool, default false)
 *           $deleteUrl (string, required if $canDelete)
 *           $showBadge (bool, default false) — show pipeline_status badge
 *           $badgeColors (array<string,string>) — required if $showBadge
 *           $statusLabels (array<string,string>) — required if $showBadge
 */
$canDelete    = $canDelete    ?? false;
$showBadge    = $showBadge    ?? false;
$badgeColors  = $badgeColors  ?? [];
$statusLabels = $statusLabels ?? [];
$deleteUrl    = $deleteUrl    ?? '';

$mime = $doc->mime_type ?? '';
$icon = 'bi-file-earmark';
$iconColor = '#aaa';
if (str_contains($mime, 'pdf'))                                         { $icon = 'bi-file-earmark-pdf';   $iconColor = '#dc3545'; }
elseif (str_contains($mime, 'image'))                                   { $icon = 'bi-file-earmark-image'; $iconColor = '#0dcaf0'; }
elseif (str_contains($mime, 'wordprocessingml') || str_contains($mime, 'msword')) { $icon = 'bi-file-earmark-word';  $iconColor = '#0d6efd'; }
elseif (str_contains($mime, 'spreadsheet') || str_contains($mime, 'excel'))       { $icon = 'bi-file-earmark-excel'; $iconColor = 'var(--primary-color)'; }

$label = $doc->document_type ?: $doc->file_name;
$badgeClass = $badgeColors[$doc->pipeline_status ?? ''] ?? 'bg-secondary';
$badgeLabel = $statusLabels[$doc->pipeline_status ?? ''] ?? ($doc->pipeline_status ?? '');
?>
<div class="doc-row sgi-doc-row" data-doc-id="<?= h($doc->id) ?>">
    <div class="doc-icon sgi-doc-icon">
        <i class="bi <?= h($icon) ?>" style="color:<?= h($iconColor) ?>;"></i>
    </div>
    <div class="doc-body sgi-doc-body">
        <div class="doc-label sgi-doc-label" data-slot="label" title="<?= h($label) ?>"><?= h($label) ?></div>
        <?php if ($doc->document_type): ?>
        <div class="doc-filename sgi-doc-filename" data-slot="filename" title="<?= h($doc->file_name) ?>"><?= h($doc->file_name) ?></div>
        <?php endif; ?>
        <div class="doc-meta sgi-doc-meta">
            <?php if ($showBadge && ($doc->pipeline_status ?? null)): ?>
            <span class="badge <?= h($badgeClass) ?>" data-slot="badge"><?= h($badgeLabel) ?></span>
            <?php endif; ?>
            <span class="doc-created sgi-doc-created">
                <i class="bi bi-clock"></i>
                <span data-slot="created"><?= h($doc->created?->format('d/m/Y H:i')) ?></span>
            </span>
            <?php if ($doc->file_size): ?>
            <span class="doc-size sgi-doc-size" data-slot="size"><?= $this->Number->toReadableSize($doc->file_size) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="doc-actions sgi-doc-actions">
        <a class="btn btn-sm btn-outline-secondary" data-slot="open-link"
           href="/<?= h($doc->file_path) ?>" target="_blank" title="Abrir">
            <i class="bi bi-box-arrow-up-right"></i>
        </a>
        <?php if ($canDelete): ?>
        <button type="button" class="btn btn-sm btn-outline-danger doc-delete-btn"
                data-slot="delete-btn" data-url="<?= h($deleteUrl) ?>" title="Eliminar">
            <i class="bi bi-trash"></i>
        </button>
        <?php endif; ?>
    </div>
</div>

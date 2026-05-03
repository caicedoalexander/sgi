<?php
/**
 * Document row partial — used by Invoices, PettyCashRecords, EmployeeNovelties,
 * NoveltyLiquidationDocs for both initial server render and as the structural
 * twin of the <template id="doc-row-template"> used by sgi-document-uploader.js.
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
<div class="doc-row" data-doc-id="<?= h($doc->id) ?>"
     style="display:flex;align-items:flex-start;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);">
    <div class="doc-icon"
         style="width:34px;height:34px;flex-shrink:0;background:#f5f5f5;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
        <i class="bi <?= h($icon) ?>" style="color:<?= h($iconColor) ?>;font-size:1rem;"></i>
    </div>
    <div class="doc-body" style="flex:1;min-width:0;">
        <div class="doc-label" data-slot="label"
             style="font-size:.79rem;font-weight:600;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.35;"
             title="<?= h($label) ?>"><?= h($label) ?></div>
        <?php if ($doc->document_type): ?>
        <div class="doc-filename" data-slot="filename"
             style="font-size:.7rem;color:#999;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:.1rem;"
             title="<?= h($doc->file_name) ?>"><?= h($doc->file_name) ?></div>
        <?php endif; ?>
        <div class="doc-meta" style="display:flex;align-items:center;gap:.5rem;margin-top:.35rem;flex-wrap:wrap;">
            <?php if ($showBadge && ($doc->pipeline_status ?? null)): ?>
            <span class="badge <?= h($badgeClass) ?>" data-slot="badge" style="font-size:.58rem;"><?= h($badgeLabel) ?></span>
            <?php endif; ?>
            <span class="doc-created" style="font-size:.65rem;color:#bbb;">
                <i class="bi bi-clock" style="font-size:.6rem;"></i>
                <span data-slot="created"><?= h($doc->created?->format('d/m/Y H:i')) ?></span>
            </span>
            <?php if ($doc->file_size): ?>
            <span class="doc-size" data-slot="size" style="font-size:.63rem;color:#ccc;"><?= $this->Number->toReadableSize($doc->file_size) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="doc-actions" style="display:flex;gap:.25rem;flex-shrink:0;align-self:center;">
        <a class="btn btn-sm btn-outline-secondary" data-slot="open-link"
           href="/<?= h($doc->file_path) ?>" target="_blank" title="Abrir"
           style="padding:.25rem .45rem;font-size:.72rem;line-height:1;">
            <i class="bi bi-box-arrow-up-right"></i>
        </a>
        <?php if ($canDelete): ?>
        <button type="button" class="btn btn-sm btn-outline-danger doc-delete-btn"
                data-slot="delete-btn" data-url="<?= h($deleteUrl) ?>"
                style="padding:.25rem .45rem;font-size:.72rem;line-height:1;" title="Eliminar">
            <i class="bi bi-trash"></i>
        </button>
        <?php endif; ?>
    </div>
</div>

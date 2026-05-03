<?php
/**
 * Emits <template id="doc-row-template"> for sgi-document-uploader.js to clone.
 * Mirror of document_row.php structure (same data-slot keys).
 *
 * Optional: $showBadge (bool, default false) — include the badge slot for modules
 *           with per-document pipeline_status (Invoices, NoveltyLiquidationDocs).
 */
$showBadge = $showBadge ?? false;
?>
<template id="doc-row-template">
    <div class="doc-row" data-doc-id=""
         style="display:flex;align-items:flex-start;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);">
        <div class="doc-icon"
             style="width:34px;height:34px;flex-shrink:0;background:#f5f5f5;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
            <i class="bi" style="font-size:1rem;"></i>
        </div>
        <div class="doc-body" style="flex:1;min-width:0;">
            <div class="doc-label" data-slot="label"
                 style="font-size:.79rem;font-weight:600;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.35;"></div>
            <div class="doc-filename" data-slot="filename"
                 style="font-size:.7rem;color:#999;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:.1rem;display:none;"></div>
            <div class="doc-meta" style="display:flex;align-items:center;gap:.5rem;margin-top:.35rem;flex-wrap:wrap;">
                <?php if ($showBadge): ?>
                <span class="badge" data-slot="badge" style="font-size:.58rem;display:none;"></span>
                <?php endif; ?>
                <span class="doc-created" style="font-size:.65rem;color:#bbb;">
                    <i class="bi bi-clock" style="font-size:.6rem;"></i>
                    <span data-slot="created"></span>
                </span>
                <span class="doc-size" data-slot="size" style="font-size:.63rem;color:#ccc;display:none;"></span>
            </div>
        </div>
        <div class="doc-actions" style="display:flex;gap:.25rem;flex-shrink:0;align-self:center;">
            <a class="btn btn-sm btn-outline-secondary" data-slot="open-link"
               href="" target="_blank" title="Abrir"
               style="padding:.25rem .45rem;font-size:.72rem;line-height:1;">
                <i class="bi bi-box-arrow-up-right"></i>
            </a>
            <button type="button" class="btn btn-sm btn-outline-danger doc-delete-btn"
                    data-slot="delete-btn" data-url=""
                    style="padding:.25rem .45rem;font-size:.72rem;line-height:1;display:none;" title="Eliminar">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </div>
</template>

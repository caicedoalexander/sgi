<?php
/**
 * Emits <template id="doc-row-template"> for sgi-document-uploader.js to clone.
 *
 * IMPORTANT: structural twin of `templates/element/document_row.php` —
 * any change to data-slot keys, CSS classes, or markup MUST be applied to both
 * files. The JS helper (`webroot/js/sgi-document-uploader.js`) reads slots:
 *   label, filename, badge, created, size, open-link, delete-btn.
 *
 * Optional: $showBadge (bool, default false) — include the badge slot for modules
 *           with per-document pipeline_status (Invoices, NoveltyLiquidationDocs).
 */
$showBadge = $showBadge ?? false;
?>
<template id="doc-row-template">
    <div class="doc-row sgi-doc-row" data-doc-id="">
        <div class="doc-icon sgi-doc-icon">
            <i class="bi"></i>
        </div>
        <div class="doc-body sgi-doc-body">
            <div class="doc-label sgi-doc-label" data-slot="label"></div>
            <div class="doc-filename sgi-doc-filename" data-slot="filename" style="display:none;"></div>
            <div class="doc-meta sgi-doc-meta">
                <?php if ($showBadge): ?>
                <span class="badge" data-slot="badge" style="display:none;"></span>
                <?php endif; ?>
                <span class="doc-created sgi-doc-created">
                    <i class="bi bi-clock"></i>
                    <span data-slot="created"></span>
                </span>
                <span class="doc-size sgi-doc-size" data-slot="size" style="display:none;"></span>
            </div>
        </div>
        <div class="doc-actions sgi-doc-actions">
            <a class="btn btn-sm btn-outline-secondary" data-slot="open-link" href="" target="_blank" title="Abrir">
                <i class="bi bi-box-arrow-up-right"></i>
            </a>
            <button type="button" class="btn btn-sm btn-outline-danger doc-delete-btn"
                    data-slot="delete-btn" data-url="" style="display:none;" title="Eliminar">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </div>
</template>

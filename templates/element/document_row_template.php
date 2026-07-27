<?php
/**
 * Emits <template id="doc-row-template"> for spi-document-uploader.js to clone.
 *
 * Visual pattern (docs/design/documental-vacios.md §15 · Gestión documental — "Fila de documento").
 *
 * IMPORTANT: structural twin of `templates/element/document_row.php` —
 * any change to data-slot keys, CSS classes (`.doc-row`, `.doc-icon`,
 * `.doc-delete-btn`), markup or the `data-doc-id` attribute MUST be applied
 * to both files. The JS helper (`webroot/js/spi-document-uploader.js`) reads
 * slots: label, filename, badge, created, size, open-link, delete-btn.
 *
 * Optional: $showBadge (bool, default false) — include the badge slot for modules
 *           with per-document pipeline_status (Invoices, NoveltyLiquidationDocs).
 */
$showBadge = $showBadge ?? false;
?>
<template id="doc-row-template">
    <div class="doc-row row-flex gap-12" data-doc-id=""
         style="padding:10px 12px;background:var(--bg-muted);margin-bottom:6px;">
        <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
            <i class="bi" style="font-size:18px;" aria-hidden="true"></i>
        </div>
        <div class="grow">
            <div data-slot="label"
                 style="font-size:var(--fs-body);font-weight:600;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
            <div class="row-flex gap-6 mono spi-body-faint" style="margin-top:2px;">
                <span data-slot="filename" style="display:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
                <span data-slot="size" style="display:none;"></span>
            </div>
        </div>
        <div class="col-flex gap-2" style="flex-shrink:0;text-align:right;">
            <span class="spi-label">Cargado</span>
            <span class="mono" style="font-size:var(--fs-body-sm);" data-slot="created"></span>
        </div>
        <?php if ($showBadge): ?>
        <span class="pill" data-slot="badge" style="display:none;flex-shrink:0;"></span>
        <?php endif; ?>
        <div class="row-flex gap-4" style="flex-shrink:0;">
            <a class="btn-icon" data-slot="open-link" href="" target="_blank" rel="noopener noreferrer" title="Abrir">
                <i class="bi bi-eye" aria-hidden="true"></i>
            </a>
            <button type="button" class="btn-icon doc-delete-btn"
                    data-slot="delete-btn" data-url="" style="display:none;" title="Eliminar">
                <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
        </div>
    </div>
</template>

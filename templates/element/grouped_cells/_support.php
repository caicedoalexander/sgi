<?php
/**
 * Celda Soporte de una fila de la tabla de facturas hijas. Extraída de
 * grouped_invoices_table para que T12 (wrapper `td`) y T14 (wrapper `span`)
 * compartan EXACTAMENTE el mismo markup interno (link+pill na/ok/missing +
 * botón de subida). El mapeo $supportKind → pill sale de
 * InvoicePresentation::SUPPORT_BADGES (anti-drift: cero literales pill-* aquí).
 *
 * @var \App\View\AppView $this
 * @var \App\View\Presentation\GroupedInvoiceRowView $row
 * @var string $wrapper Etiqueta contenedora ('td' | 'span') — whitelist, nunca cruda.
 * @var bool $canUploadSupport Pinta el botón de subida (el server sigue siendo la autoridad).
 * @var string|null $uploadModalId Id del upload_doc_modal incluido por la página.
 */

use App\View\Presentation\InvoicePresentation;

// Whitelist estricta: el wrapper jamás se interpola crudo (evita inyección de tag).
$tag = in_array($wrapper ?? '', ['td', 'span'], true) ? $wrapper : 'td';
$supportKind = !$row->supportRequired ? 'na' : ($row->supportOk ? 'ok' : 'missing');
$supportPill = InvoicePresentation::SUPPORT_BADGES[$supportKind];
?>
<<?= $tag ?> onclick="event.stopPropagation();">
    <span class="d-inline-flex align-items-center gap-1">
        <?php // El badge enlaza a la vista de la factura (spec §3.4: ver/borrar documentos se hace allá). ?>
        <a href="<?= $this->Url->build(['controller' => 'Invoices', 'action' => 'view', $row->id]) ?>"
           style="text-decoration:none;" title="Ver documentos de la factura">
        <?php if ($supportKind === 'na'): ?>
            <span class="pill pill-sm <?= h($supportPill) ?>">N/A</span>
        <?php elseif ($supportKind === 'ok'): ?>
            <span class="pill pill-sm <?= h($supportPill) ?>"><i class="bi bi-check2" aria-hidden="true"></i> <?= $row->docsCount ?></span>
        <?php else: ?>
            <span class="pill pill-sm <?= h($supportPill) ?>">Falta</span>
        <?php endif; ?>
        </a>
        <?php if ($canUploadSupport && $uploadModalId !== null): ?>
        <button type="button" class="btn btn-icon btn-sm grouped-upload-btn" title="Subir soporte"
                data-upload-url="<?= $this->Url->build(['controller' => 'Invoices', 'action' => 'uploadDocument', $row->id]) ?>">
            <i class="bi bi-upload" aria-hidden="true"></i>
        </button>
        <?php endif; ?>
    </span>
</<?= $tag ?>>

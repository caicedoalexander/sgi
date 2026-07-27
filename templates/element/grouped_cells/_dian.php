<?php
/**
 * Celda DIAN de una fila de la tabla de facturas hijas. Extraída de
 * grouped_invoices_table para que T12 (wrapper `td`) y T14 (wrapper `span`)
 * compartan EXACTAMENTE el mismo markup interno (3 ramas na/select/pill). Los
 * mapeos visuales salen de InvoicePresentation vía el DTO (anti-drift: cero
 * literales pill-* aquí).
 *
 * @var \App\View\AppView $this
 * @var \App\View\Presentation\GroupedInvoiceRowView $row
 * @var string $wrapper Etiqueta contenedora ('td' | 'span') — whitelist, nunca cruda.
 */

use App\Constants\InvoiceConstants;

// Whitelist estricta: el wrapper jamás se interpola crudo (evita inyección de tag).
$tag = in_array($wrapper ?? '', ['td', 'span'], true) ? $wrapper : 'td';
?>
<<?= $tag ?> onclick="event.stopPropagation();">
    <?php if ($row->dianMode === 'na'): ?>
        <span class="spi-fg-faint" style="font-size:12px;">No aplica</span>
    <?php elseif ($row->dianMode === 'select'): ?>
        <select class="form-select form-select-sm grouped-dian-select" style="max-width:130px;"
                data-invoice-id="<?= $row->id ?>"
                data-current-value="<?= h($row->dianValue) ?>"
                data-approved-value="<?= h(InvoiceConstants::DIAN_APPROVED) ?>">
            <?php foreach (InvoiceConstants::DIAN_STATUSES as $opt): ?>
            <option value="<?= h($opt) ?>" <?= $opt === $row->dianValue ? 'selected' : '' ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
        </select>
    <?php else: ?>
        <span class="pill pill-sm <?= h($row->dianPill) ?>"><?= h($row->dianValue) ?></span>
    <?php endif; ?>
</<?= $tag ?>>

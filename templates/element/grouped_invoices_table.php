<?php
/**
 * Card "Facturas Agrupadas" de la vista de un registro padre, con acciones
 * inline de DIAN y soporte por fila (spec 2026-07-14 §3.4). Los mapeos
 * visuales salen de InvoicePresentation (anti-drift, cero literales aquí): el
 * markup interno de las celdas DIAN y Soporte vive en los sub-elements
 * `grouped_cells/_dian` y `grouped_cells/_support`, compartidos con T14.
 *
 * @var \App\View\AppView $this
 * @var list<\App\View\Presentation\GroupedInvoiceRowView> $rows
 * @var \App\Service\Dto\GroupReadinessReport|null $readiness
 * @var string $parentField  FK de contención (refund_id | petty_cash_record_id | advance_id)
 * @var int $parentId
 * @var bool $canUploadSupport
 * @var string|null $uploadModalId  id del upload_doc_modal incluido por la página
 * @var string $title
 * @var bool $editable  Pinta la columna de desvincular por fila (default false).
 * @var string|null $headerActionsHtml  Slot HTML en el header (ej. botón Vincular).
 * @var string $unlinkAction  Acción del controller actual para el postLink de desvincular.
 * @var float|null $totalAmount  Cuando != null, renderiza el <tfoot> de Total.
 */

$title = $title ?? 'Facturas Agrupadas';
$readiness = $readiness ?? null;
$canUploadSupport = $canUploadSupport ?? false;
$uploadModalId = $uploadModalId ?? null;
$editable = $editable ?? false;
$headerActionsHtml = $headerActionsHtml ?? null;
$unlinkAction = $unlinkAction ?? 'removeInvoice';
$totalAmount = $totalAmount ?? null;
$rootId = 'grouped-invoices-' . h($parentField);
$blockedIds = $readiness === null
    ? []
    : array_unique(array_merge(array_keys($readiness->dianPending), array_keys($readiness->supportMissing)));
?>
<div class="spi-card" id="<?= $rootId ?>" data-parent-field="<?= h($parentField) ?>" data-parent-id="<?= (int)$parentId ?>">
    <div class="d-flex justify-content-between align-items-center" style="margin-bottom:14px;">
        <span class="spi-label d-inline-flex align-items-center gap-2">
            <i class="bi bi-receipt" aria-hidden="true"></i>
            <?= h($title) ?>
            <span class="spi-folder-count"><?= count($rows) ?></span>
        </span>
        <?php if ($headerActionsHtml !== null): ?>
        <div class="d-flex gap-2"><?= $headerActionsHtml ?></div>
        <?php endif; ?>
    </div>

    <?php if ($readiness !== null): ?>
    <div class="d-flex align-items-center gap-2" data-grouped-checklist
         style="margin-bottom:12px;padding:10px 14px;background:var(--bg-subtle);border:1px solid var(--rule);font-size:12px;<?= $readiness->isBlocked() ? '' : 'display:none;' ?>">
        <i class="bi bi-exclamation-triangle" aria-hidden="true" style="color:var(--secondary-color, #CD6A15);"></i>
        <span data-slot="dian-pending"><?= count($readiness->dianPending) ?> con DIAN pendiente</span>
        <span aria-hidden="true">·</span>
        <span data-slot="support-missing"><?= count($readiness->supportMissing) ?> sin soporte</span>
    </div>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
        <div class="empty-state">
            <div class="es-icon es-icon-neutral"><i class="bi bi-inbox" aria-hidden="true"></i></div>
            <div class="es-title">No hay facturas agrupadas</div>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th># Factura</th>
                        <th>Tipo</th>
                        <th>Beneficiario</th>
                        <th style="text-align:right;">Monto</th>
                        <th>Fecha Emisión</th>
                        <th>Estado</th>
                        <th>DIAN</th>
                        <th>Soporte</th>
                        <?php if ($editable): ?>
                        <th style="width:48px;" aria-label="Acciones"></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                    <tr class="clickable-row" data-href="<?= $this->Url->build(['controller' => 'Invoices', 'action' => 'view', $row->id]) ?>">
                        <td class="mono" style="font-weight:600;">
                            <?php if (in_array($row->id, $blockedIds, true)): ?>
                            <i class="bi bi-exclamation-triangle" data-slot="row-warn" aria-hidden="true"
                               style="color:var(--secondary-color, #CD6A15);margin-right:4px;" title="Requisitos pendientes"></i>
                            <?php endif; ?>
                            <?= h($row->number) ?>
                        </td>
                        <td><?= h($row->documentType ?: '—') ?></td>
                        <td><?= h($row->beneficiaryName) ?></td>
                        <td class="mono" style="text-align:right;">$ <?= number_format($row->amount, 0, ',', '.') ?></td>
                        <td class="mono"><?= h($row->issueDate ?? '—') ?></td>
                        <td><span class="pill pill-sm <?= h($row->statusPill) ?>"><?= h($row->statusLabel) ?></span></td>
                        <?= $this->element('grouped_cells/_dian', ['row' => $row, 'wrapper' => 'td']) ?>
                        <?= $this->element('grouped_cells/_support', [
                            'row' => $row,
                            'wrapper' => 'td',
                            'canUploadSupport' => $canUploadSupport,
                            'uploadModalId' => $uploadModalId,
                        ]) ?>
                        <?php if ($editable): ?>
                        <td onclick="event.stopPropagation();" style="text-align:center;">
                            <?= $this->Form->postLink(
                                '<i class="bi bi-x-lg" aria-hidden="true"></i>',
                                ['action' => $unlinkAction, (int)$parentId, $row->id],
                                [
                                    'class' => 'btn-icon',
                                    'escape' => false,
                                    'confirm' => '¿Remover esta factura del registro?',
                                    'title' => 'Quitar',
                                    'block' => true,
                                ],
                            ) ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if ($totalAmount !== null): ?>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align:right;font-weight:700;">Total:</td>
                        <td class="mono" style="text-align:right;font-weight:700;color:var(--primary-color);">
                            $ <?= number_format((float)$totalAmount, 0, ',', '.') ?>
                        </td>
                        <td colspan="<?= $editable ? 5 : 4 ?>"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    <?php endif; ?>
</div>
<?= $this->Html->script('spi-grouped-invoices', ['block' => true]) ?>
<?php $this->Html->scriptBlock(sprintf(
    "document.addEventListener('DOMContentLoaded',function(){SpiGroupedInvoices.init({rootSelector:'#%s',csrfToken:%s,uploadFormSelector:%s,uploadModalSelector:%s});});",
    $rootId,
    json_encode((string)$this->request->getAttribute('csrfToken')),
    json_encode($uploadModalId !== null ? '#grouped-upload-form' : null),
    json_encode($uploadModalId !== null ? '#' . $uploadModalId : null),
), ['block' => true]); ?>

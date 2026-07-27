<?php
/**
 * Card "Facturas vinculadas" de una legalización de anticipo. Compartida por la
 * vista operativa (Advances/legalization.php, editable=true) y el hub de consulta
 * (Advances/view.php, editable=false). Con editable=false oculta los controles de
 * mutación (Nueva/Vincular/Desvincular).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AdvanceLegalization $leg
 * @var \App\Model\Entity\Invoice $invoice
 * @var iterable $linkedInvoices
 * @var float $linkedTotal
 * @var int $linkedCount
 * @var bool $editable
 * @var \App\Service\Dto\GroupReadinessReport|null $readiness Requisitos DIAN/soporte pendientes (default null → sin checklist).
 * @var bool $canResolveDian Pinta el select DIAN inline en las hijas en `aprobacion` (default false → pill/lectura).
 * @var bool $canUploadSupport Pinta el botón de subir soporte inline (default false).
 * @var string|null $uploadModalId Id del upload_doc_modal incluido por la página (default null).
 */
use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\View\Presentation\InvoicePresentation;

$editable = $editable ?? true;
$readiness = $readiness ?? null;
$canResolveDian = $canResolveDian ?? false;
$canUploadSupport = $canUploadSupport ?? false;
$uploadModalId = $uploadModalId ?? null;
$blockedIds = $readiness === null
    ? []
    : array_unique(array_merge(array_keys($readiness->dianPending), array_keys($readiness->supportMissing)));
$liGrid = 'display:grid;grid-template-columns:1.1fr 1fr 1.8fr 0.9fr 1fr 1.2fr 1fr 0.9fr 32px;gap:12px;align-items:center;';
?>
<div class="spi-card" id="grouped-invoices-advance_id" data-parent-field="advance_id" data-parent-id="<?= (int)$leg->advance_invoice_id ?>">
    <div class="d-flex align-items-center justify-content-between" style="margin-bottom:12px;">
        <span class="spi-label d-inline-flex align-items-center gap-2">
            <i class="bi bi-link-45deg" aria-hidden="true"></i>Facturas vinculadas
        </span>
        <?php if ($editable && $leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
        <div class="d-flex gap-2">
            <?php if (!empty($userPermissions['invoices']['can_create'])): ?>
            <?= $this->Html->link(
                '<i class="bi bi-file-earmark-plus" aria-hidden="true"></i>Nueva',
                ['controller' => 'Invoices', 'action' => 'add', '?' => ['advance_id' => $leg->advance_invoice_id]],
                ['class' => 'btn btn-default btn-sm', 'escape' => false],
            ) ?>
            <?php endif; ?>
            <button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#advanceLinkModal">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>Vincular
            </button>
        </div>
        <?php endif; ?>
    </div>
    <div class="hr" style="margin-bottom:16px;"></div>

    <?php if ($linkedCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral">
            <i class="bi bi-inbox" aria-hidden="true"></i>
        </div>
        <div class="es-title">Sin facturas vinculadas</div>
    </div>
    <?php else: ?>
        <div style="<?= $liGrid ?>padding:9px 14px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.6px;text-transform:uppercase;" role="row">
            <span># Factura</span>
            <span>Tipo</span>
            <span>Beneficiario</span>
            <span>Fecha</span>
            <span style="text-align:right;">Monto</span>
            <span>Estado</span>
            <span>DIAN</span>
            <span>Soporte</span>
            <span aria-hidden="true"></span>
        </div>
        <?php foreach ($linkedInvoices as $idx => $li): ?>
        <?php $rowView = InvoicePresentation::forGroupedRow($li, $canResolveDian); ?>
        <div class="clickable-row" role="row"
             data-href="<?= $this->Url->build(['controller' => 'Invoices', 'action' => 'view', $li->id]) ?>"
             style="<?= $liGrid ?>padding:11px 14px;background:#fff;cursor:pointer;<?= $idx > 0 ? 'border-top:1px solid var(--rule);' : '' ?>">
            <span class="mono" style="font-size:12px;font-weight:700;color:var(--text-strong);">
                <?php if (in_array($li->id, $blockedIds, true)): ?>
                <i class="bi bi-exclamation-triangle" data-slot="row-warn" aria-hidden="true"
                   style="color:var(--secondary-color, #CD6A15);margin-right:4px;" title="Requisitos pendientes"></i>
                <?php endif; ?>
                <?= h($li->invoice_number ?: '#' . $li->id) ?>
            </span>
            <span class="mono" style="font-size:11.5px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= h($rowView->documentType ?: '—') ?>
            </span>
            <span style="font-size:12px;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= h($rowView->beneficiaryName) ?>
            </span>
            <span class="mono" style="font-size:11.5px;color:var(--text-muted);">
                <?= $li->issue_date?->format('d/m/Y') ?? '—' ?>
            </span>
            <span class="mono" style="text-align:right;font-size:12.5px;font-weight:700;color:var(--text-default);">
                $ <?= number_format((float)$li->amount, 0, ',', '.') ?>
            </span>
            <span>
                <span class="pill <?= InvoicePresentation::STATUS_BADGES[$li->pipeline_status] ?? 'pill-muted' ?> pill-sm">
                    <?= h(strtoupper(InvoiceConstants::STATUS_LABELS[$li->pipeline_status] ?? $li->pipeline_status)) ?>
                </span>
            </span>
            <?= $this->element('grouped_cells/_dian', ['row' => $rowView, 'wrapper' => 'span']) ?>
            <?= $this->element('grouped_cells/_support', [
                'row' => $rowView,
                'wrapper' => 'span',
                'canUploadSupport' => $canUploadSupport,
                'uploadModalId' => $uploadModalId,
            ]) ?>
            <span style="display:flex;justify-content:flex-end;">
                <?php if ($editable && $leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
                <?= $this->Form->postLink(
                    '<i class="bi bi-x-lg" aria-hidden="true"></i>',
                    ['action' => 'unlinkInvoice', $invoice->id, $li->id],
                    ['class' => 'btn-icon', 'escape' => false, 'confirm' => '¿Desvincular esta factura?', 'title' => 'Desvincular']
                ) ?>
                <?php endif; ?>
            </span>
        </div>
        <?php endforeach; ?>
        <div style="<?= $liGrid ?>padding:10px 14px;background:var(--bg-subtle);border-top:1px solid var(--rule);">
            <span style="grid-column:1 / 5;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;">
                Total vinculado
            </span>
            <span class="mono" style="text-align:right;font-size:12.5px;font-weight:700;color:var(--primary-color);">
                $ <?= number_format($linkedTotal, 0, ',', '.') ?>
            </span>
            <?php // Cols 6-9 (Estado, DIAN, Soporte, desvincular) — vacías en el total. ?>
            <span aria-hidden="true"></span>
            <span aria-hidden="true"></span>
            <span aria-hidden="true"></span>
            <span aria-hidden="true"></span>
        </div>
    <?php endif; ?>
</div>
<?= $this->Html->script('spi-grouped-invoices', ['block' => true]) ?>
<?php $this->Html->scriptBlock(sprintf(
    "document.addEventListener('DOMContentLoaded',function(){SpiGroupedInvoices.init({rootSelector:'#grouped-invoices-advance_id',csrfToken:%s,uploadFormSelector:%s,uploadModalSelector:%s});});",
    json_encode((string)$this->request->getAttribute('csrfToken')),
    json_encode($uploadModalId !== null ? '#grouped-upload-form' : null),
    json_encode($uploadModalId !== null ? '#' . $uploadModalId : null),
), ['block' => true]); ?>

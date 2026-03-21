<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\LegalizationRecord $record
 */
use App\Constants\InvoiceConstants;
use App\Constants\LegalizationConstants;

$this->assign('title', 'Legalización ' . ($record->code ?? '#' . $record->id));

$statusBadge = [
    LegalizationConstants::STATUS_AGRUPACION => 'bg-info text-dark',
    LegalizationConstants::STATUS_CONTABILIDAD => 'bg-primary',
    LegalizationConstants::STATUS_TESORERIA => 'bg-warning text-dark',
    LegalizationConstants::STATUS_PAGADO => 'bg-success',
];
$statusLabels = LegalizationConstants::STATUS_LABELS;

$docIcon = fn(?string $mime): string => match(true) {
    str_contains($mime ?? '', 'pdf') => 'bi-file-earmark-pdf',
    str_contains($mime ?? '', 'image') => 'bi-file-earmark-image',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword') => 'bi-file-earmark-word',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel') => 'bi-file-earmark-excel',
    default => 'bi-file-earmark',
};
$docIconColor = fn(?string $mime): string => match(true) {
    str_contains($mime ?? '', 'pdf') => '#dc3545',
    str_contains($mime ?? '', 'image') => '#0dcaf0',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword') => '#0d6efd',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel') => 'var(--primary-color)',
    default => '#aaa',
};
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Ver Registro de Legalización</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?php if (!empty($userPermissions['legalizations']['can_edit']) && !$record->isPagado()): ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil me-1"></i>Editar',
            ['action' => 'edit', $record->id],
            ['class' => 'btn btn-warning btn-sm', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- Card principal -->
<div class="card card-primary mb-4">
    <!-- Header -->
    <div class="card-header d-flex align-items-start justify-content-between gap-3" style="padding:1rem 1.25rem;">
        <div class="d-flex align-items-start gap-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:52px;height:52px;background:var(--primary-color);color:#fff;font-size:1.35rem;">
                <i class="bi bi-file-earmark-check"></i>
            </div>
            <div>
                <div style="font-size:1.25rem;font-weight:700;letter-spacing:-.03em;color:#111;line-height:1.15;font-family:monospace;">
                    <?= h($record->code ?? '#' . $record->id) ?>
                </div>
                <div class="mt-1 d-flex align-items-center gap-2">
                    <span class="badge <?= $statusBadge[$record->status] ?? 'bg-dark' ?>">
                        <?= $statusLabels[$record->status] ?? $record->status ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="text-end flex-shrink-0">
            <div style="font-size:.55rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:#bbb;margin-bottom:.2rem;">Total</div>
            <div style="font-size:1.55rem;font-weight:700;letter-spacing:-.04em;color:var(--primary-color);line-height:1;white-space:nowrap;">
                $ <?= $this->Number->format($record->total_amount, ['places' => 2]) ?>
            </div>
        </div>
    </div>

    <!-- Progress -->
    <div style="background:#fafafa;border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color);padding:1.25rem 1.5rem;">
        <?= $this->element('legalization_progress', ['status' => $record->status]) ?>
    </div>

    <!-- Info -->
    <div class="row g-0" style="border-bottom:1px solid var(--border-color);">
        <div class="col-md-6" style="border-right:1px solid var(--border-color);">
            <div class="sgi-section-title">Información</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Código</span>
                <span class="sgi-data-value" style="font-family:monospace;"><?= h($record->code ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Estado</span>
                <span class="sgi-data-value">
                    <span class="badge <?= $statusBadge[$record->status] ?? 'bg-dark' ?>">
                        <?= $statusLabels[$record->status] ?? $record->status ?>
                    </span>
                </span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Creado por</span>
                <span class="sgi-data-value"><?= $record->hasValue('created_by_user') ? h($record->created_by_user->full_name) : '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha</span>
                <span class="sgi-data-value"><?= $record->created?->format('d/m/Y H:i') ?? '—' ?></span>
            </div>
        </div>
        <div class="col-md-6">
            <div class="sgi-section-title">Notas</div>
            <div style="padding:.25rem 1.25rem .875rem;font-size:.875rem;color:#333;line-height:1.65;">
                <?= $record->notes ? nl2br(h($record->notes)) : '<span class="text-muted">Sin notas</span>' ?>
            </div>
        </div>
    </div>
</div>

<!-- Facturas agrupadas -->
<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-receipt"></i>
        <span>Facturas Agrupadas</span>
        <span class="sgi-folder-count"><?= count($record->invoices ?? []) ?></span>
    </div>
    <?php if (empty($record->invoices)): ?>
    <div class="p-3 text-center text-muted" style="font-size:.875rem;">
        <i class="bi bi-inbox me-1"></i>No hay facturas agrupadas
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead>
                <tr>
                    <th># Factura</th>
                    <th>Proveedor</th>
                    <th class="text-end">Monto</th>
                    <th>Fecha Emisión</th>
                    <th>Estado Pipeline</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($record->invoices as $inv): ?>
                <tr class="clickable-row" data-href="<?= $this->Url->build(['controller' => 'Invoices', 'action' => 'view', $inv->id]) ?>">
                    <td style="font-family:monospace;font-weight:600;"><?= h($inv->invoice_number ?? '#' . $inv->id) ?></td>
                    <td><?= $inv->hasValue('provider') ? h($inv->provider->name) : '—' ?></td>
                    <td class="text-end">$ <?= $this->Number->format($inv->amount, ['places' => 2]) ?></td>
                    <td><?= $inv->issue_date?->format('d/m/Y') ?? '—' ?></td>
                    <td>
                        <?php
                        $pBadge = match($inv->pipeline_status) {
                            InvoiceConstants::STATUS_APROBACION => 'bg-info text-dark',
                            InvoiceConstants::STATUS_CONTABILIDAD => 'bg-primary',
                            InvoiceConstants::STATUS_TESORERIA => 'bg-warning text-dark',
                            InvoiceConstants::STATUS_PAGADA => 'bg-success',
                            default => 'bg-dark',
                        };
                        ?>
                        <span class="badge <?= $pBadge ?>"><?= h($inv->pipeline_status) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Documentos soporte -->
<?php $docs = $record->legalization_documents ?? []; ?>
<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-paperclip"></i>
        <span>Soportes</span>
        <span class="sgi-folder-count"><?= count($docs) ?> doc<?= count($docs) !== 1 ? 's' : '' ?></span>
    </div>
    <?php if (empty($docs)): ?>
    <div class="p-3 text-center text-muted" style="font-size:.875rem;">
        <i class="bi bi-file-earmark-x me-1"></i>Sin soportes adjuntos
    </div>
    <?php else: ?>
    <div class="p-3">
        <div class="row row-cols-1 row-cols-md-3 g-3">
            <?php foreach ($docs as $doc): ?>
            <div class="col">
                <div style="border:1px solid var(--border-color);height:100%;display:flex;flex-direction:column;">
                    <div style="padding:.6rem .875rem;border-bottom:1px solid var(--border-color);background:#fafafa;display:flex;align-items:center;gap:.5rem;min-width:0;">
                        <i class="bi <?= $docIcon($doc->mime_type) ?> flex-shrink-0"
                           style="color:<?= $docIconColor($doc->mime_type) ?>;font-size:1.1rem;"></i>
                        <div style="min-width:0;flex:1;overflow:hidden;">
                            <span style="font-size:.78rem;font-weight:600;color:#222;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;" title="<?= h($doc->document_type ?: $doc->file_name) ?>">
                                <?= h($doc->document_type ?: $doc->file_name) ?>
                            </span>
                            <?php if ($doc->document_type): ?>
                            <span style="font-size:.7rem;color:#888;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($doc->file_name) ?>">
                                <?= h($doc->file_name) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="padding:.6rem .875rem;flex:1;font-size:.78rem;color:#555;display:flex;flex-direction:column;gap:.3rem;">
                        <div style="display:flex;align-items:center;gap:.35rem;color:#666;">
                            <i class="bi bi-person" style="font-size:.8rem;"></i>
                            <span><?= $doc->has('uploaded_by_user') ? h($doc->uploaded_by_user->full_name) : '—' ?></span>
                        </div>
                        <div style="display:flex;align-items:center;gap:.35rem;color:#888;">
                            <i class="bi bi-clock" style="font-size:.75rem;"></i>
                            <span><?= $doc->created?->format('d/m/Y H:i') ?></span>
                        </div>
                        <?php if ($doc->file_size): ?>
                        <div style="color:#aaa;font-size:.72rem;"><?= $this->Number->toReadableSize($doc->file_size) ?></div>
                        <?php endif; ?>
                    </div>
                    <div style="padding:.5rem .875rem;border-top:1px solid var(--border-color);text-align:right;">
                        <?= $this->Html->link(
                            '<i class="bi bi-box-arrow-up-right me-1"></i>Abrir',
                            '/' . $doc->file_path,
                            ['class' => 'btn btn-sm btn-outline-primary', 'escape' => false, 'target' => '_blank']
                        ) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\PettyCashRecord $record
 */
use App\Constants\InvoiceConstants;
use App\Constants\PettyCashConstants;
use App\View\Presentation\InvoicePresentation;
use App\View\Presentation\PettyCashPresentation;

$this->assign('title', 'Caja Menor ' . $record->code);

$statusBadge = PettyCashPresentation::STATUS_BADGES;
$statusLabels = PettyCashConstants::STATUS_LABELS;

?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Ver Registro de Caja Menor</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?php if (!empty($userPermissions['petty_cash']['can_edit']) && !$record->isPagada()): ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar',
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
                <i class="bi bi-wallet2" aria-hidden="true"></i>
            </div>
            <div>
                <div class="mono" style="font-size:1.25rem;font-weight:700;letter-spacing:-.03em;color:var(--text-strong);line-height:1.15;">
                    <?= h($record->code) ?>
                </div>
                <div class="mt-1 d-flex align-items-center gap-2">
                    <span class="pill <?= $statusBadge[$record->status] ?? 'pill-muted' ?>">
                        <?= $statusLabels[$record->status] ?? $record->status ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="text-end flex-shrink-0">
            <div style="font-size:.55rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--text-disabled);margin-bottom:.2rem;">Total</div>
            <div style="font-size:1.55rem;font-weight:700;letter-spacing:-.04em;color:var(--primary-color);line-height:1;white-space:nowrap;">
                $ <?= $this->Number->format($record->total_amount, ['places' => 2]) ?>
            </div>
        </div>
    </div>

    <!-- Progress -->
    <div class="sgi-pipeline-wrapper">
        <?= $this->element('petty_cash_progress', ['status' => $record->status]) ?>
    </div>

    <!-- Info -->
    <div class="row g-0" style="border-bottom:1px solid var(--border-color);">
        <div class="col-md-6" style="border-right:1px solid var(--border-color);">
            <div class="sgi-label">Información</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Código</span>
                <span class="sgi-data-value mono"><?= h($record->code) ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Estado</span>
                <span class="sgi-data-value">
                    <span class="pill <?= $statusBadge[$record->status] ?? 'pill-muted' ?>">
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
            <div class="sgi-label">Notas</div>
            <div style="padding:.25rem 1.25rem .875rem;font-size:.875rem;color:var(--text-default);line-height:1.65;">
                <?= $record->notes ? nl2br(h($record->notes)) : '<span class="text-muted">Sin notas</span>' ?>
            </div>
        </div>
    </div>
</div>

<?php if ($record->isAutorizacionPago() || $record->isVerificacionPago() || $record->isPagada()): ?>
<!-- Datos de pago -->
<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-bank" aria-hidden="true"></i>
        <span>Pago</span>
    </div>
    <div class="row g-0">
        <div class="col-md-6" style="border-right:1px solid var(--border-color);">
            <div class="sgi-label">Información de Pago</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Entidad Bancaria</span>
                <span class="sgi-data-value"><?= $record->hasValue('banking_entity') ? h($record->banking_entity->name) : '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Monto Pagado</span>
                <span class="sgi-data-value">$ <?= $record->payment_amount ? $this->Number->format($record->payment_amount, ['places' => 2]) : '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha de Pago</span>
                <span class="sgi-data-value"><?= $record->payment_date?->format('d/m/Y') ?? '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Registrado por</span>
                <span class="sgi-data-value"><?= $record->hasValue('payment_created_by_user') ? h($record->payment_created_by_user->full_name) : '—' ?></span>
            </div>
        </div>
        <div class="col-md-6">
            <div class="sgi-label">Autorización</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Estado</span>
                <span class="sgi-data-value">
                    <?php if ($record->isPagada()): ?>
                        <span class="pill pill-primary-soft">Autorizado</span>
                    <?php elseif (!empty($record->payment_rejection_reason)): ?>
                        <span class="pill pill-danger-soft">Rechazado</span>
                    <?php else: ?>
                        <span class="pill pill-warning-soft">Pendiente</span>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($record->hasValue('payment_authorized_by_user')): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Autorizado por</span>
                <span class="sgi-data-value"><?= h($record->payment_authorized_by_user->full_name) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($record->payment_authorized_date): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Autorización</span>
                <span class="sgi-data-value"><?= $record->payment_authorized_date->format('d/m/Y') ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($record->payment_rejection_reason)): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Motivo Rechazo</span>
                <span class="sgi-data-value text-danger"><?= h($record->payment_rejection_reason) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Facturas agrupadas -->
<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-receipt" aria-hidden="true"></i>
        <span>Facturas Agrupadas</span>
        <span class="sgi-folder-count"><?= count($record->invoices ?? []) ?></span>
    </div>
    <?php if (empty($record->invoices)): ?>
    <div class="p-3 text-center text-muted" style="font-size:.875rem;">
        <i class="bi bi-inbox me-1" aria-hidden="true"></i>No hay facturas agrupadas
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
                    <td class="mono" style="font-weight:600;"><?= h($inv->invoice_number ?? '#' . $inv->id) ?></td>
                    <td><?= $inv->hasValue('provider') ? h($inv->provider->name) : '—' ?></td>
                    <td class="text-end">$ <?= $this->Number->format($inv->amount, ['places' => 2]) ?></td>
                    <td><?= $inv->issue_date?->format('d/m/Y') ?? '—' ?></td>
                    <td>
                        <?php $pBadge = InvoicePresentation::STATUS_BADGES[$inv->pipeline_status] ?? 'pill-muted'; ?>
                        <span class="pill <?= $pBadge ?>"><?= InvoiceConstants::STATUS_LABELS[$inv->pipeline_status] ?? h($inv->pipeline_status) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Documentos soporte -->
<?php $docs = $record->petty_cash_documents ?? []; ?>
<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-paperclip" aria-hidden="true"></i>
        <span>Soportes</span>
        <span class="sgi-folder-count"><?= count($docs) ?> doc<?= count($docs) !== 1 ? 's' : '' ?></span>
    </div>
    <?php if (empty($docs)): ?>
    <div class="p-3 text-center text-muted" style="font-size:.875rem;">
        <i class="bi bi-file-earmark-x me-1" aria-hidden="true"></i>Sin soportes adjuntos
    </div>
    <?php else: ?>
    <div class="p-3">
        <div class="row row-cols-1 row-cols-md-3 g-3">
            <?php foreach ($docs as $doc): ?>
            <div class="col">
                <div style="border:1px solid var(--border-color);height:100%;display:flex;flex-direction:column;">
                    <div style="padding:.6rem .875rem;border-bottom:1px solid var(--border-color);background:var(--bg-muted);display:flex;align-items:center;gap:.5rem;min-width:0;">
                        <i class="bi <?= h($this->DocumentIcon->iconClass($doc->mime_type)) ?> flex-shrink-0"
                           style="color:<?= h($this->DocumentIcon->iconColor($doc->mime_type)) ?>;font-size:1.1rem;"></i>
                        <div style="min-width:0;flex:1;overflow:hidden;">
                            <span style="font-size:.78rem;font-weight:600;color:var(--text-default);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;" title="<?= h($doc->document_type ?: $doc->file_name) ?>">
                                <?= h($doc->document_type ?: $doc->file_name) ?>
                            </span>
                            <?php if ($doc->document_type): ?>
                            <span style="font-size:.7rem;color:var(--text-faint);display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($doc->file_name) ?>">
                                <?= h($doc->file_name) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="padding:.6rem .875rem;flex:1;font-size:.78rem;color:var(--text-muted);display:flex;flex-direction:column;gap:.3rem;">
                        <div style="display:flex;align-items:center;gap:.35rem;color:var(--text-muted);">
                            <i class="bi bi-person" style="font-size:.8rem;" aria-hidden="true"></i>
                            <span><?= $doc->has('uploaded_by_user') ? h($doc->uploaded_by_user->full_name) : '—' ?></span>
                        </div>
                        <div style="display:flex;align-items:center;gap:.35rem;color:var(--text-faint);">
                            <i class="bi bi-clock" style="font-size:.75rem;" aria-hidden="true"></i>
                            <span><?= $doc->created?->format('d/m/Y H:i') ?></span>
                        </div>
                        <?php if ($doc->file_size): ?>
                        <div style="color:var(--text-disabled);font-size:.72rem;"><?= $this->Number->toReadableSize($doc->file_size) ?></div>
                        <?php endif; ?>
                    </div>
                    <div style="padding:.5rem .875rem;border-top:1px solid var(--border-color);text-align:right;">
                        <?= $this->Html->link(
                            '<i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Abrir',
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

<!-- Observaciones -->
<?php $obsList = $record->petty_cash_observations ?? []; ?>
<?php if (!empty($obsList)): ?>
<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-chat-left-text" aria-hidden="true"></i>
        <span>Observaciones</span>
        <span class="sgi-folder-count"><?= count($obsList) ?></span>
    </div>
    <div class="p-3" style="max-height:400px;overflow-y:auto;">
        <?php foreach ($obsList as $obs): ?>
        <?php
            $isRegression = ($obs->type ?? null) === PettyCashConstants::OBSERVATION_TYPE_REGRESSION;
            $meta = $obs->metadata ?? [];
            $fromLbl = $statusLabels[$meta['from_status'] ?? ''] ?? null;
            $toLbl = $statusLabels[$meta['to_status'] ?? ''] ?? null;
        ?>
        <div class="d-flex align-items-start gap-2 mb-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:32px;height:32px;background:<?= $isRegression ? 'var(--secondary-color)' : 'var(--primary-color)' ?>;color:#fff;font-size:.7rem;font-weight:700;">
                <?php
                $names = explode(' ', $obs->user->full_name ?? '');
                echo strtoupper(substr($names[0] ?? '', 0, 1) . substr($names[1] ?? '', 0, 1));
                ?>
            </div>
            <div style="flex:1;min-width:0;">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span style="font-size:.8rem;font-weight:600;color:var(--text-default);">
                        <?= h($obs->user->full_name ?? '') ?>
                    </span>
                    <?php if ($isRegression): ?>
                        <span class="pill pill-warning-soft" style="font-size:.65rem;">Regresión</span>
                    <?php endif; ?>
                    <span style="font-size:.7rem;color:var(--text-disabled);">
                        <?= $obs->created ? $obs->created->format('d/m/Y H:i') : '' ?>
                    </span>
                </div>
                <?php if ($isRegression && $fromLbl && $toLbl): ?>
                    <div style="font-size:.74rem;color:var(--text-muted);margin-top:.1rem;">
                        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>
                        <?= h($fromLbl) ?> &rarr; <?= h($toLbl) ?>
                    </div>
                <?php endif; ?>
                <div style="font-size:.84rem;color:var(--text-muted);line-height:1.5;margin-top:.15rem;">
                    <?= nl2br(h($obs->message)) ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

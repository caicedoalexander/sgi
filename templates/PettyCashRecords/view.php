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

$statusBadge  = PettyCashPresentation::STATUS_BADGES;
$statusLabels = PettyCashConstants::STATUS_LABELS;

$pcStatusPills = [
    PettyCashConstants::STATUS_AGRUPACION        => 'pill-info-soft',
    PettyCashConstants::STATUS_CONTABILIDAD      => 'pill-primary-soft',
    PettyCashConstants::STATUS_TESORERIA         => 'pill-warning-soft',
    PettyCashConstants::STATUS_AUTORIZACION_PAGO => 'pill-info-soft',
    PettyCashConstants::STATUS_VERIFICACION_PAGO => 'pill-warning-soft',
    PettyCashConstants::STATUS_PAGADA            => 'pill-primary-soft',
];
$pcStatusPill  = $pcStatusPills[$record->status] ?? 'pill-muted';
$pcStatusLabel = $statusLabels[$record->status] ?? $record->status;

$isTerminal = $record->status === PettyCashConstants::STATUS_PAGADA;
$invoiceCount = count($record->invoices ?? []);
$docs = $record->petty_cash_documents ?? [];
$obsList = $record->petty_cash_observations ?? [];
?>

<!-- Page header -->
<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="sgi-breadcrumb">
            <?= $this->Html->link('Caja Menor', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current"><?= h($record->code) ?></span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="sgi-page-title">Ver Caja Menor</span>
            <span class="sgi-edit-id-chip"><?= h($record->code) ?></span>
            <span class="pill <?= $pcStatusPill ?>"><?= h($pcStatusLabel) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
        <?php if (!empty($userPermissions['petty_cash']['can_edit']) && !$record->isPagada()): ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $record->id],
            ['class' => 'btn btn-secondary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<div class="sgi-invoice-view-grid view-anim">

    <!-- ═══════════════════ SIDEBAR ═══════════════════ -->
    <aside class="sgi-invoice-view-left">
        <?php
        $registryLines = [];
        if ($record->hasValue('created_by_user')) {
            $registryLines[] = ['icon' => 'bi-person', 'html' => 'Creado por ' . h($record->created_by_user->full_name)];
        }
        if ($record->created) {
            $registryLines[] = ['icon' => 'bi-calendar3', 'html' => 'Creado · <span class="mono">' . $record->created->format('d/m/Y H:i') . '</span>'];
        }
        if ($record->modified) {
            $registryLines[] = ['icon' => 'bi-pencil-square', 'html' => 'Modificado · <span class="mono">' . $record->modified->format('d/m/Y') . '</span>'];
        }

        echo $this->element('pipeline_sidebar', [
            'icon'           => 'wallet2',
            'idLabel'        => $record->code,
            'typeLabel'      => 'Caja Menor',
            'statusPill'     => $pcStatusPill,
            'statusLabel'    => $pcStatusLabel,
            'entityLabel'    => 'Centro de Operación',
            'entityValue'    => $record->operation_center->name ?? '—',
            'entitySubLabel' => $invoiceCount . ' factura' . ($invoiceCount !== 1 ? 's' : ''),
            'entitySubIcon'  => 'bi-receipt',
            'amountLabel'    => 'Total',
            'amount'         => (float)$record->total_amount,
            'pipelineSteps'  => PettyCashConstants::STATUSES,
            'pipelineLabels' => $statusLabels,
            'currentStatus'  => $record->status,
            'isTerminal'     => $isTerminal,
            'modifiedAt'     => $record->modified,
            'registryLines'  => $registryLines,
        ]);
        ?>
    </aside>

    <!-- ═══════════════════ CONTENIDO ═══════════════════ -->
    <main class="sgi-invoice-view-right">

        <!-- Información + Notas -->
        <div class="card">
            <div class="row g-0">
                <div class="col-md-6" style="border-right:1px solid var(--rule);">
                    <div class="sgi-section-head" style="padding:14px 18px 0;">
                        <span class="sgi-label">Información</span>
                    </div>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Código</span>
                        <span class="sgi-data-value mono"><?= h($record->code) ?></span>
                    </div>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Estado</span>
                        <span class="sgi-data-value">
                            <span class="pill <?= $pcStatusPill ?>"><?= h($pcStatusLabel) ?></span>
                        </span>
                    </div>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Creado por</span>
                        <span class="sgi-data-value"><?= $record->hasValue('created_by_user') ? h($record->created_by_user->full_name) : '—' ?></span>
                    </div>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Fecha</span>
                        <span class="sgi-data-value mono"><?= $record->created?->format('d/m/Y H:i') ?? '—' ?></span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="sgi-section-head" style="padding:14px 18px 0;">
                        <span class="sgi-label">Notas</span>
                    </div>
                    <div style="padding:.25rem 18px 14px;font-size:var(--fs-body);color:var(--text-default);line-height:1.55;">
                        <?= $record->notes ? nl2br(h($record->notes)) : '<span class="sgi-fg-faint">Sin notas</span>' ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($record->isAutorizacionPago() || $record->isVerificacionPago() || $record->isPagada()): ?>
        <!-- Datos de pago -->
        <div class="card">
            <div class="sgi-section-head" style="padding:14px 18px 0;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-bank" aria-hidden="true"></i>Pago
                </span>
            </div>
            <div class="row g-0">
                <div class="col-md-6" style="border-right:1px solid var(--rule);">
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Entidad Bancaria</span>
                        <span class="sgi-data-value"><?= $record->hasValue('banking_entity') ? h($record->banking_entity->name) : '—' ?></span>
                    </div>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Monto Pagado</span>
                        <span class="sgi-data-value mono">$ <?= $record->payment_amount ? number_format((float)$record->payment_amount, 0, ',', '.') : '—' ?></span>
                    </div>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Fecha de Pago</span>
                        <span class="sgi-data-value mono"><?= $record->payment_date?->format('d/m/Y') ?? '—' ?></span>
                    </div>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Registrado por</span>
                        <span class="sgi-data-value"><?= $record->hasValue('payment_created_by_user') ? h($record->payment_created_by_user->full_name) : '—' ?></span>
                    </div>
                </div>
                <div class="col-md-6">
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
                        <span class="sgi-data-value mono"><?= $record->payment_authorized_date->format('d/m/Y') ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($record->payment_rejection_reason)): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Motivo Rechazo</span>
                        <span class="sgi-data-value sgi-fg-danger"><?= h($record->payment_rejection_reason) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Facturas agrupadas -->
        <div class="card" style="padding:18px 20px;">
            <div class="sgi-section-head" style="margin-bottom:12px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-receipt" aria-hidden="true"></i>
                    Facturas Agrupadas
                    <span class="sgi-folder-count"><?= $invoiceCount ?></span>
                </span>
            </div>
            <?php if (empty($record->invoices)): ?>
            <div class="text-center sgi-fg-faint py-3" style="font-size:var(--fs-body);">
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
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($record->invoices as $inv): ?>
                        <tr class="clickable-row" data-href="<?= $this->Url->build(['controller' => 'Invoices', 'action' => 'view', $inv->id]) ?>">
                            <td class="mono" style="font-weight:600;"><?= h($inv->invoice_number ?? '#' . $inv->id) ?></td>
                            <td><?= $inv->hasValue('provider') ? h($inv->provider->name) : '—' ?></td>
                            <td class="text-end mono">$ <?= number_format((float)$inv->amount, 0, ',', '.') ?></td>
                            <td class="mono"><?= $inv->issue_date?->format('d/m/Y') ?? '—' ?></td>
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

        <!-- Soportes + Observaciones (grid lateral) -->
        <div class="sgi-edit-side-grid">
            <!-- Soportes -->
            <div class="card" style="padding:18px 20px;">
                <div class="sgi-section-head" style="margin-bottom:12px;">
                    <span class="sgi-label d-inline-flex align-items-center gap-2">
                        <i class="bi bi-paperclip" aria-hidden="true"></i>
                        Soportes
                        <span class="sgi-folder-count"><?= count($docs) ?> doc<?= count($docs) !== 1 ? 's' : '' ?></span>
                    </span>
                </div>

                <?php if (empty($docs)): ?>
                <div class="sgi-dropzone-empty">
                    <i class="bi bi-paperclip" aria-hidden="true"></i>
                    <div>Sin soportes adjuntos</div>
                </div>
                <?php else: ?>
                <div style="max-height:420px;overflow-y:auto;">
                    <?php foreach ($docs as $doc): ?>
                        <?= $this->element('document_row', [
                            'doc'       => $doc,
                            'canDelete' => false,
                            'deleteUrl' => null,
                            'showBadge' => false,
                        ]) ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Observaciones -->
            <div class="card sgi-obs-card" style="padding:18px 20px;">
                <div class="sgi-section-head" style="margin-bottom:12px;">
                    <span class="sgi-label d-inline-flex align-items-center gap-2">
                        <i class="bi bi-chat-left-text" aria-hidden="true"></i>
                        Observaciones
                        <span class="sgi-folder-count"><?= count($obsList) ?></span>
                    </span>
                </div>

                <?php if (empty($obsList)): ?>
                <div class="sgi-obs-empty">
                    <i class="bi bi-chat-square-dots" aria-hidden="true" style="font-size:1.5rem;"></i>
                    <span style="font-size:var(--fs-body-sm);">Sin observaciones</span>
                </div>
                <?php else: ?>
                <div class="sgi-obs-list" style="max-height:400px;">
                    <?php foreach ($obsList as $obs): ?>
                        <?= $this->element('observation_bubble', [
                            'observation' => $obs,
                            'isMine' => false,
                        ]) ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

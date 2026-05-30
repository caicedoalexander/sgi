<?php
/**
 * Caja Menor / view — detalle de un registro de Caja Menor.
 *
 * Reescrito (mayo 2026) para el Sistema de Diseño v2: panel izquierdo vía
 * `element('pipeline_sidebar', ...)` (hero card + pipeline vertical + acciones);
 * la card "Registro / auditoría" se mantiene inline en el panel izquierdo.
 * Panel derecho con cards de sección (información, pago, facturas, observaciones, soportes).
 *
 * @var \App\View\AppView $this
 * @var \App\ViewModel\PettyCashViewViewModel $viewModel
 */

use App\Constants\InvoiceConstants;
use App\View\Presentation\InvoicePresentation;

$record = $viewModel->record;

$this->assign('title', $viewModel->pageTitle);

// ─── Datos derivados de presentación (vía ViewModel) ────────────────
[$pcStatusLabel, $pcStatusPill] = $viewModel->currentStatusBadge;
$currentStatus = $viewModel->currentStatus;
$statusLabels  = $viewModel->pipelineLabels;
$pipelineSteps = $viewModel->pipelineSteps;
$isTerminal    = $viewModel->isTerminal;

$invoiceCount = $viewModel->invoiceCount;
$obsList      = $record->petty_cash_observations ?? [];
$obsCount     = $viewModel->obsCount;

// Formateo del total.
$amountFmt = $viewModel->totalAmount;

// Helpers de iniciales para avatares.
$initialsOf = static function (?string $name): string {
    if (!$name) {
        return '?';
    }
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $ini = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }

    return $ini ?: mb_strtoupper(mb_substr($name, 0, 2));
};

$showPaymentCard = $viewModel->showPaymentCard;
$canEdit = !empty($userPermissions['petty_cash']['can_edit']) && !$record->isPagada();
?>

<!-- ─── Page header: breadcrumb + título + acciones ───────────────── -->
<div class="d-flex justify-content-between align-items-start" style="margin-bottom:16px;">
    <div style="min-width:0;">
        <div class="d-flex align-items-center gap-1" style="font-size:11.5px;color:var(--text-faint);margin-bottom:4px;">
            <?= $this->Html->link('Caja Menor', ['action' => 'index'], ['style' => 'color:inherit;text-decoration:none;']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:10px;"></i>
            <span style="color:var(--text-default);"><?= h($record->code) ?></span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <h1 class="sgi-title-page">Ver Caja Menor</h1>
            <span class="mono" style="font-size:var(--fs-body-lg);color:var(--text-muted);padding:3px 8px;background:var(--bg-subtle);border-radius:var(--radius-sm);">
                <?= h($record->code) ?>
            </span>
            <span class="pill <?= h($pcStatusPill) ?>"><?= h($pcStatusLabel) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-default', 'escape' => false]
        ) ?>
        <?php if ($canEdit): ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $record->id],
            ['class' => 'btn btn-secondary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- ─── Grid principal (340px + 1fr) ──────────────────────────────── -->
<div class="sgi-invoice-view-grid view-anim">

    <!-- ═════════════════════════ COLUMNA IZQUIERDA ═════════════════════════ -->
    <aside class="sgi-invoice-view-left">

    <?php
    // Acciones rápidas (Editar registro / Volver).
    $quickActions = [];
    if ($canEdit) {
        $quickActions[] = ['icon' => 'bi-pencil', 'label' => 'Editar registro',
            'url' => $this->Url->build(['action' => 'edit', $record->id])];
    }
    $quickActions[] = ['icon' => 'bi-arrow-left', 'label' => 'Volver al listado',
        'url' => $this->Url->build(['action' => 'index'])];
    ob_start();
    foreach ($quickActions as $a) {
        echo $this->Html->link(
            '<i class="bi ' . h($a['icon']) . '" aria-hidden="true"></i><span>' . h($a['label']) . '</span>',
            $a['url'],
            ['class' => 'btn btn-ghost btn-sm', 'escape' => false,
             'style' => 'justify-content:flex-start;width:100%;gap:8px;']
        );
    }
    $quickActionsHtml = ob_get_clean();

    // Línea "Pagado" bajo el monto (HTML derivado en el ViewModel).
    $amountExtraHtml = $viewModel->amountExtraHtml;
    ?>

    <?= $this->element('pipeline_sidebar', [
        'icon'          => 'wallet2',
        'idLabel'       => $record->code,
        'typeLabel'     => 'Caja Menor',
        'statusPill'    => $pcStatusPill,
        'statusLabel'   => $pcStatusLabel,
        'isRejected'    => false,
        'entityLabel'   => 'Centro de Operación',
        'entityValue'   => $record->operation_center->name ?? '—',
        'entitySubLabel' => $invoiceCount . ' factura' . ($invoiceCount !== 1 ? 's' : ''),
        'entitySubIcon'  => 'bi-receipt',
        'amountLabel'   => 'Total',
        'amount'        => $amountFmt,
        'amountExtraHtml' => $amountExtraHtml,
        'pipelineSteps'  => $pipelineSteps,
        'pipelineLabels' => $statusLabels,
        'currentStatus'  => $currentStatus,
        'isTerminal'     => $isTerminal,
        'modifiedAt'     => $record->modified,
        'actionsHtml'    => $quickActionsHtml,
    ]) ?>

    <?php /* La card "Registro / auditoría" existente y el </aside> se conservan
             tal cual a continuación de esta llamada. */ ?>

        <!-- Registro / auditoría -->
        <div class="sgi-card compact">
            <div class="sgi-label" style="margin-bottom:10px;">Registro</div>
            <?php if ($record->hasValue('created_by_user')): ?>
            <div class="d-flex align-items-center gap-2 mb-1" style="font-size:var(--fs-body-sm);color:var(--text-muted);">
                <i class="bi bi-person sgi-fg-faint" aria-hidden="true"></i>
                <span>Creado por <strong style="color:var(--text-default);"><?= h($record->created_by_user->full_name) ?></strong></span>
            </div>
            <?php endif; ?>
            <?php if ($record->created): ?>
            <div class="d-flex align-items-center gap-2 mb-1" style="font-size:var(--fs-body-sm);color:var(--text-muted);">
                <i class="bi bi-calendar3 sgi-fg-faint" aria-hidden="true"></i>
                <span>Creado · <span class="mono"><?= $record->created->format('d/m/Y H:i') ?></span></span>
            </div>
            <?php endif; ?>
            <?php if ($record->modified): ?>
            <div class="d-flex align-items-center gap-2" style="font-size:var(--fs-body-sm);color:var(--text-muted);">
                <i class="bi bi-pencil sgi-fg-faint" aria-hidden="true"></i>
                <span>Modificado · <span class="mono"><?= $record->modified->format('d/m/Y') ?></span></span>
            </div>
            <?php endif; ?>
        </div>

    </aside>

    <!-- ═════════════════════════ COLUMNA DERECHA ═════════════════════════ -->
    <main class="sgi-invoice-view-right">

        <!-- Información + Notas -->
        <div class="sgi-card">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;">
                <div>
                    <div class="sgi-label" style="margin-bottom:6px;">Información</div>
                    <div class="field-row">
                        <span class="k">Código</span>
                        <span class="v mono"><?= h($record->code) ?></span>
                    </div>
                    <div class="field-row">
                        <span class="k">Estado</span>
                        <span class="v">
                            <span class="pill pill-sm <?= h($pcStatusPill) ?>"><?= h($pcStatusLabel) ?></span>
                        </span>
                    </div>
                    <div class="field-row">
                        <span class="k">Creado por</span>
                        <?php if ($record->hasValue('created_by_user')): ?>
                            <span class="v"><?= h($record->created_by_user->full_name) ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="field-row is-last">
                        <span class="k">Fecha</span>
                        <span class="v mono"><?= $record->created?->format('d/m/Y H:i') ?? '—' ?></span>
                    </div>
                </div>
                <div>
                    <div class="sgi-label" style="margin-bottom:6px;">Notas</div>
                    <div style="font-size:var(--fs-body-lg);color:var(--text-default);line-height:1.55;padding-top:9px;">
                        <?= $record->notes
                            ? nl2br(h($record->notes))
                            : '<span style="color:var(--text-faint);">Sin notas</span>' ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($showPaymentCard): ?>
        <!-- Datos de pago -->
        <div class="sgi-card">
            <div class="sgi-label d-inline-flex align-items-center gap-2" style="margin-bottom:14px;">
                <i class="bi bi-bank" aria-hidden="true"></i>Pago
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;">
                <div>
                    <div class="field-row">
                        <span class="k">Entidad Bancaria</span>
                        <?php if ($record->hasValue('banking_entity')): ?>
                            <span class="v"><?= h($record->banking_entity->name) ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="field-row">
                        <span class="k">Monto Pagado</span>
                        <span class="v mono">$ <?= $record->payment_amount
                            ? number_format((float)$record->payment_amount, 0, ',', '.')
                            : '—' ?></span>
                    </div>
                    <div class="field-row">
                        <span class="k">Fecha de Pago</span>
                        <span class="v mono"><?= $record->payment_date?->format('d/m/Y') ?? '—' ?></span>
                    </div>
                    <div class="field-row is-last">
                        <span class="k">Registrado por</span>
                        <?php if ($record->hasValue('payment_created_by_user')): ?>
                            <span class="v"><?= h($record->payment_created_by_user->full_name) ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <div class="field-row">
                        <span class="k">Estado</span>
                        <span class="v">
                            <?php if ($record->isPagada()): ?>
                                <span class="pill pill-sm pill-primary-soft">Autorizado</span>
                            <?php elseif (!empty($record->payment_rejection_reason)): ?>
                                <span class="pill pill-sm pill-danger-soft">Rechazado</span>
                            <?php else: ?>
                                <span class="pill pill-sm pill-warning-soft">Pendiente</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if ($record->hasValue('payment_authorized_by_user')): ?>
                    <div class="field-row">
                        <span class="k">Autorizado por</span>
                        <span class="v"><?= h($record->payment_authorized_by_user->full_name) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($record->payment_authorized_date): ?>
                    <div class="field-row">
                        <span class="k">Fecha Autorización</span>
                        <span class="v mono"><?= $record->payment_authorized_date->format('d/m/Y') ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($record->payment_rejection_reason)): ?>
                    <div class="field-row is-last">
                        <span class="k">Motivo Rechazo</span>
                        <span class="v" style="color:var(--danger-color);"><?= h($record->payment_rejection_reason) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Facturas agrupadas -->
        <div class="sgi-card">
            <div class="d-flex justify-content-between align-items-center" style="margin-bottom:14px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-receipt" aria-hidden="true"></i>
                    Facturas Agrupadas
                    <span class="sgi-folder-count"><?= $invoiceCount ?></span>
                </span>
            </div>

            <?php if (empty($record->invoices)): ?>
                <div class="empty-state">
                    <div class="es-icon es-icon-neutral"><i class="bi bi-inbox" aria-hidden="true"></i></div>
                    <div class="es-title">No hay facturas agrupadas</div>
                    <div class="es-msg">Este registro no tiene facturas vinculadas.</div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th># Factura</th>
                                <th>Proveedor</th>
                                <th style="text-align:right;">Monto</th>
                                <th>Fecha Emisión</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($record->invoices as $inv): ?>
                            <tr class="clickable-row" data-href="<?= $this->Url->build(['controller' => 'Invoices', 'action' => 'view', $inv->id]) ?>">
                                <td class="mono" style="font-weight:600;"><?= h($inv->invoice_number ?? '#' . $inv->id) ?></td>
                                <td><?= $inv->hasValue('provider') ? h($inv->provider->name) : '—' ?></td>
                                <td class="mono" style="text-align:right;">$ <?= number_format((float)$inv->amount, 0, ',', '.') ?></td>
                                <td class="mono"><?= $inv->issue_date?->format('d/m/Y') ?? '—' ?></td>
                                <td>
                                    <?php $pBadge = InvoicePresentation::STATUS_BADGES[$inv->pipeline_status] ?? 'pill-muted'; ?>
                                    <span class="pill pill-sm <?= h($pBadge) ?>">
                                        <?= h(InvoiceConstants::STATUS_LABELS[$inv->pipeline_status] ?? $inv->pipeline_status) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Observaciones -->
        <div class="sgi-card">
            <div class="d-flex justify-content-between align-items-center" style="margin-bottom:14px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-chat-square-text" aria-hidden="true"></i>
                    Observaciones
                    <span class="sgi-folder-count"><?= $obsCount ?></span>
                </span>
            </div>

            <?php if ($obsCount === 0): ?>
                <div class="empty-state">
                    <div class="es-icon es-icon-neutral"><i class="bi bi-chat-square-text" aria-hidden="true"></i></div>
                    <div class="es-title">Sin observaciones</div>
                    <div class="es-msg">No se han registrado observaciones para este registro.</div>
                </div>
            <?php else: ?>
                <?php foreach ($obsList as $i => $obs):
                    $userName = $obs->user->full_name ?? '';
                    $isLast   = $i === $obsCount - 1;
                ?>
                <div class="d-flex" style="gap:12px;padding:10px 0;<?= $isLast ? '' : 'border-bottom:1px solid var(--rule);' ?>">
                    <div class="av av-md"><?= h($initialsOf($userName)) ?></div>
                    <div style="flex:1;min-width:0;">
                        <div class="d-flex align-items-center flex-wrap" style="gap:8px;margin-bottom:4px;">
                            <span style="font-size:var(--fs-body);font-weight:600;color:var(--text-strong);"><?= h($userName) ?></span>
                            <span class="mono" style="margin-left:auto;font-size:10.5px;color:var(--text-faint);">
                                <?= $obs->created ? $obs->created->format('d/m/Y H:i') : '' ?>
                            </span>
                        </div>
                        <div style="font-size:var(--fs-body);color:var(--text-default);line-height:1.5;">
                            <?= nl2br(h($obs->message)) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Documentos / Soportes -->
        <?= $this->element('documents_section', [
            'groups'        => [['label' => null, 'pillKind' => null, 'rows' => $viewModel->documentRows]],
            'totalDocs'     => $viewModel->totalDocs,
            'canUpload'     => false,
            'uploadModalId' => null,
            'emptyTitle'    => 'Sin documentos adjuntos',
        ]) ?>

    </main>
</div>

<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\RefundViewViewModel $viewModel
 */
use App\Constants\InvoiceConstants;
use App\View\Presentation\InvoicePresentation;

$record = $viewModel->record;

$this->assign('title', $viewModel->pageTitle);

[$rfStatusLabel, $rfStatusPill] = $viewModel->currentStatusBadge;
$isTerminal   = $viewModel->isTerminal;
$invoiceCount = $viewModel->invoiceCount;
$bName        = $viewModel->beneficiaryName;
$bLabel       = $viewModel->beneficiaryLabel;
?>

<!-- Page header -->
<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="sgi-breadcrumb">
            <?= $this->Html->link('Reintegros', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current"><?= h($record->code) ?></span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="sgi-page-title">Ver Reintegro</span>
            <span class="sgi-edit-id-chip"><?= h($record->code) ?></span>
            <span class="pill <?= $rfStatusPill ?>"><?= h($rfStatusLabel) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
        <?php if (!empty($userPermissions['refunds']['can_edit']) && !$record->isPagada()): ?>
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
        echo $this->element('pipeline_sidebar', [
            'icon'           => 'arrow-counterclockwise',
            'idLabel'        => $record->code,
            'typeLabel'      => 'Reintegro',
            'statusPill'     => $rfStatusPill,
            'statusLabel'    => $rfStatusLabel,
            'entityLabel'    => 'Beneficiario',
            'entityValue'    => $bName ?? '— Sin beneficiario —',
            'entitySubLabel' => $bLabel,
            'entitySubIcon'  => 'bi-person-badge',
            'amountLabel'    => 'Total',
            'amount'         => $viewModel->totalAmount,
            'pipelineSteps'  => $viewModel->pipelineSteps,
            'pipelineLabels' => $viewModel->pipelineLabels,
            'currentStatus'  => $viewModel->currentStatus,
            'isTerminal'     => $isTerminal,
            'modifiedAt'     => $record->modified,
            'registryLines'  => $viewModel->registryLines,
        ]);
        ?>
    </aside>

    <!-- ═══════════════════ CONTENIDO ═══════════════════ -->
    <main class="sgi-invoice-view-right">

        <!-- Información + Beneficiario -->
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
                            <span class="pill <?= $rfStatusPill ?>"><?= h($rfStatusLabel) ?></span>
                        </span>
                    </div>
                    <div class="field-row">
                        <span class="k">Creado por</span>
                        <span class="v"><?= $record->hasValue('created_by_user') ? h($record->created_by_user->full_name) : '—' ?></span>
                    </div>
                    <div class="field-row is-last">
                        <span class="k">Fecha</span>
                        <span class="v mono"><?= $record->created?->format('d/m/Y H:i') ?? '—' ?></span>
                    </div>
                </div>
                <div>
                    <div class="sgi-label" style="margin-bottom:6px;">Beneficiario</div>
                    <div class="field-row">
                        <span class="k">Tipo</span>
                        <span class="v"><?= h($bLabel ?? '—') ?></span>
                    </div>
                    <div class="field-row is-last">
                        <span class="k">Beneficiario</span>
                        <span class="v"><?= h($bName ?? '—') ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Facturas agrupadas -->
        <div class="card" style="padding:18px 20px;">
            <div class="sgi-section-head" style="margin-bottom:12px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-receipt" aria-hidden="true"></i>
                    Facturas Agrupadas
                    <span class="sgi-folder-count"><?= $invoiceCount ?></span>
                </span>
            </div>
            <?php if ($invoiceCount === 0): ?>
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
                                <span class="pill <?= $pBadge ?>"><?= h(InvoiceConstants::STATUS_LABELS[$inv->pipeline_status] ?? $inv->pipeline_status) ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Soportes -->
        <?= $this->element('documents_section', [
            'groups'        => [['label' => null, 'pillKind' => null, 'rows' => $viewModel->documentRows]],
            'totalDocs'     => $viewModel->totalDocs,
            'canUpload'     => false,
            'uploadModalId' => null,
            'emptyTitle'    => 'Sin soportes adjuntos',
        ]) ?>

        <?= $this->element('observations/drawer', [
            'observations'    => $record->refund_observations ?? [],
            'count'           => count($record->refund_observations ?? []),
            'formUrl'         => ['action' => 'addObservation', $record->id],
            'currentUserName' => $currentUser->full_name
                ?? ($currentUser->username ?? 'Usuario'),
        ]) ?>

    </main>
</div>

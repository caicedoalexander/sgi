<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\PaymentSchedulingViewViewModel $viewModel
 */
$record = $viewModel->record;

$this->assign('title', $viewModel->pageTitle);

[$psStatusLabel, $psStatusPill] = $viewModel->currentStatusBadge;
$isTerminal = $viewModel->isTerminal;
$itemCount  = $viewModel->itemCount;
$total      = $viewModel->total;
?>

<!-- Page header -->
<div class="spi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="spi-breadcrumb">
            <?= $this->Html->link('Programación de Pagos', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current"><?= h($record->code) ?></span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="spi-page-title">Ver Programación</span>
            <span class="spi-edit-id-chip"><?= h($record->code) ?></span>
            <span class="pill <?= $psStatusPill ?>"><?= h($psStatusLabel) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-default', 'escape' => false]
        ) ?>
        <?php if (!$isTerminal): ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $record->id],
            ['class' => 'btn btn-secondary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<div class="spi-invoice-view-grid view-anim">

    <!-- ═══════════════════ SIDEBAR ═══════════════════ -->
    <aside class="spi-invoice-view-left">
        <?php
        echo $this->element('pipeline_sidebar', [
            'icon'           => 'calendar2-check',
            'idLabel'        => $record->code,
            'typeLabel'      => 'Programación',
            'statusPill'     => $psStatusPill,
            'statusLabel'    => $psStatusLabel,
            'entityLabel'    => 'Título',
            'entityValue'    => $record->title ?: '—',
            'entitySubLabel' => $itemCount . ' factura' . ($itemCount !== 1 ? 's' : ''),
            'entitySubIcon'  => 'bi-receipt',
            'amountLabel'    => 'Monto Total',
            'amount'         => (float)$total,
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
    <main class="spi-invoice-view-right">

        <!-- Información general -->
        <div class="spi-card">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;">
                <div>
                    <div class="spi-label" style="margin-bottom:6px;">Información</div>
                    <div class="field-row">
                        <span class="k">Código</span>
                        <span class="v mono"><?= h($record->code) ?></span>
                    </div>
                    <div class="field-row">
                        <span class="k">Título</span>
                        <span class="v"><?= h($record->title) ?: '—' ?></span>
                    </div>
                    <div class="field-row is-last">
                        <span class="k">Estado</span>
                        <span class="v">
                            <span class="pill <?= $psStatusPill ?>"><?= h($psStatusLabel) ?></span>
                        </span>
                    </div>
                </div>
                <div>
                    <div class="spi-label" style="margin-bottom:6px;">Detalles</div>
                    <div class="field-row">
                        <span class="k">Creado por</span>
                        <span class="v"><?= h($record->created_by_user->full_name ?? '—') ?></span>
                    </div>
                    <div class="field-row">
                        <span class="k">Facturas</span>
                        <span class="v"><?= $itemCount ?></span>
                    </div>
                    <div class="field-row is-last">
                        <span class="k">Monto Total</span>
                        <span class="v mono" style="color:var(--primary-color);font-weight:700;">$ <?= number_format((float)$total, 0, ',', '.') ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Facturas Vinculadas -->
        <?php if ($itemCount > 0): ?>
        <div class="card" style="padding:18px 20px;">
            <div class="spi-section-head" style="margin-bottom:12px;">
                <span class="spi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-receipt" aria-hidden="true"></i>
                    Facturas Vinculadas
                    <span class="spi-folder-count"><?= $itemCount ?></span>
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>N. Factura</th>
                            <th>Proveedor</th>
                            <th>Banco</th>
                            <th class="text-end">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($record->payment_scheduling_items as $item): ?>
                        <tr>
                            <td>
                                <?= $this->Html->link(
                                    h($item->invoice->invoice_number ?? 'ID:' . $item->invoice_id),
                                    ['controller' => 'Invoices', 'action' => 'view', $item->invoice_id],
                                    ['class' => 'mono', 'style' => 'font-weight:600;']
                                ) ?>
                            </td>
                            <td><?= h($item->invoice->provider->name ?? '—') ?></td>
                            <td><?= h($item->banking_entity->name ?? '—') ?></td>
                            <td class="text-end mono">$ <?= number_format((float)$item->amount, 0, ',', '.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end fw-bold">Total</td>
                            <td class="text-end fw-bold mono" style="color:var(--primary-color);">$ <?= number_format((float)$total, 0, ',', '.') ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Soportes -->
        <?= $this->element('documents_section', [
            'groups'        => [['label' => null, 'pillKind' => null, 'rows' => $viewModel->documentRows]],
            'totalDocs'     => $viewModel->totalDocs,
            'canUpload'     => false,
            'uploadModalId' => null,
            'emptyTitle'    => 'Sin soportes adjuntos',
        ]) ?>

    </main>
</div>

<?= $this->element('observations/drawer', [
    'observations'    => $record->payment_scheduling_observations ?? [],
    'count'           => count($record->payment_scheduling_observations ?? []),
    'formUrl'         => ['action' => 'addObservation', $record->id],
    'currentUserName' => $currentUser->full_name
        ?? ($currentUser->username ?? 'Usuario'),
]) ?>

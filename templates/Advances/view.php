<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\AdvanceViewViewModel $viewModel
 */

$invoice = $viewModel->record;

$this->assign('title', $viewModel->pageTitle);

$beneficiary     = $viewModel->beneficiary;
$beneficiaryType = $viewModel->beneficiaryType;

[$advStatusLabel, $advStatusPill] = $viewModel->currentStatusBadge;
$advIdLabel  = $viewModel->idLabel;
$isTerminal  = $viewModel->isTerminal;
?>

<!-- Page header -->
<div class="spi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="spi-breadcrumb">
            <?= $this->Html->link('Anticipos', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current"><?= h($advIdLabel) ?></span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="spi-page-title">Ver Anticipo</span>
            <span class="spi-edit-id-chip"><?= h($advIdLabel) ?></span>
            <span class="pill <?= $advStatusPill ?>"><?= h($advStatusLabel) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-default', 'escape' => false]
        ) ?>
        <?php if (!empty($userPermissions['advances']['can_edit']) && !$isTerminal): ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil" aria-hidden="true"></i>Editar',
            ['controller' => 'Invoices', 'action' => 'edit', $invoice->id],
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
            'icon'           => 'cash-coin',
            'idLabel'        => $advIdLabel,
            'typeLabel'      => 'Anticipo',
            'statusPill'     => $advStatusPill,
            'statusLabel'    => $advStatusLabel,
            'entityLabel'    => $beneficiaryType,
            'entityValue'    => $beneficiary,
            'entitySubLabel' => $invoice->operation_center->name ?? null,
            'entitySubIcon'  => 'bi-geo-alt',
            'amountLabel'    => 'Monto',
            'amount'         => (float)$invoice->amount,
            'pipelineSteps'  => $viewModel->pipelineSteps,
            'pipelineLabels' => $viewModel->pipelineLabels,
            'currentStatus'  => $viewModel->currentStatus,
            'isTerminal'     => $isTerminal,
            'modifiedAt'     => $invoice->modified,
            'registryLines'  => $viewModel->registryLines,
        ]);
        ?>
    </aside>

    <!-- ═══════════════════ CONTENIDO ═══════════════════ -->
    <main class="spi-invoice-view-right">

        <!-- Beneficiario + Detalle -->
        <div class="spi-card">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;">
                <div>
                    <div class="spi-label" style="margin-bottom:6px;">Beneficiario</div>
                    <div class="field-row">
                        <span class="k">Tipo</span>
                        <span class="v"><?= h($beneficiaryType) ?></span>
                    </div>
                    <div class="field-row">
                        <span class="k">Nombre</span>
                        <span class="v"><?= h($beneficiary) ?></span>
                    </div>
                    <div class="field-row">
                        <span class="k">Centro de Operación</span>
                        <span class="v"><?= h($invoice->operation_center->name ?? '—') ?></span>
                    </div>
                    <div class="field-row">
                        <span class="k">Tipo de Gasto</span>
                        <span class="v"><?= h($invoice->expense_type->name ?? '—') ?></span>
                    </div>
                    <div class="field-row is-last">
                        <span class="k">Centro de Costos</span>
                        <span class="v"><?= h($invoice->cost_center->name ?? '—') ?></span>
                    </div>
                </div>
                <div>
                    <div class="spi-label" style="margin-bottom:6px;">Detalle</div>
                    <div class="field-row">
                        <span class="k">Fecha de Emisión</span>
                        <span class="v mono"><?= $invoice->issue_date?->format('d/m/Y') ?? '—' ?></span>
                    </div>
                    <div class="field-row">
                        <span class="k">Registrado por</span>
                        <span class="v"><?= $invoice->hasValue('registered_by_user') ? h($invoice->registered_by_user->full_name ?? '—') : '—' ?></span>
                    </div>
                    <div class="field-row">
                        <span class="k">Fecha de Registro</span>
                        <span class="v mono"><?= $invoice->created?->format('d/m/Y H:i') ?? '—' ?></span>
                    </div>
                    <div class="field-row align-items-start is-last">
                        <span class="k">Concepto</span>
                        <span class="v"><?= $invoice->detail ? nl2br(h($invoice->detail)) : '—' ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Desembolso al beneficiario -->
        <?php if (!empty($invoice->invoice_payments)): ?>
        <div class="spi-card">
            <?= $this->element('payment_section', [
                'payments' => $invoice->invoice_payments,
                'bankingEntities' => [],
                'addPaymentUrl' => ['action' => 'view', $invoice->id],
                'paymentStatus' => null,
                'totalAmount' => (float)$invoice->amount,
                'mode' => 'view',
                'canRegisterPayment' => false,
                'canAuthorize' => false,
                'canDelete' => false,
                'sectionTitle' => 'Desembolso al beneficiario',
                'sectionIcon' => 'bi-cash-stack',
            ]) ?>
        </div>
        <?php endif; ?>

        <!-- Soportes del anticipo -->
        <?= $this->element('documents_section', [
            'groups'        => [['label' => null, 'pillKind' => null, 'rows' => $viewModel->documentRows]],
            'totalDocs'     => $viewModel->totalDocs,
            'canUpload'     => false,
            'uploadModalId' => null,
            'emptyTitle'    => 'Sin soportes adjuntos',
        ]) ?>

        <!-- Legalización -->
        <?php if ($viewModel->hasLegalization):
            $sum = $viewModel->legalizationSummary;
            $leg = $viewModel->legalization;
        ?>
        <!-- Legalización: resumen (card propia; los detalles van en cards hermanas para evitar card-en-card) -->
        <div class="spi-card" style="position:relative;">
            <div class="accent-strip accent-green"></div>
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2" style="margin-bottom:12px;">
                <span class="spi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-clipboard-check" aria-hidden="true"></i>Legalización
                    <span class="pill <?= h($sum->statusBadge[1]) ?>"><?= h($sum->statusBadge[0]) ?></span>
                </span>
                <?php if (!empty($userPermissions['advances']['can_edit'])): ?>
                <?= $this->Html->link(
                    'Gestionar legalización<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>',
                    ['action' => 'legalization', $invoice->id],
                    ['class' => 'btn btn-primary btn-sm', 'escape' => false]
                ) ?>
                <?php endif; ?>
            </div>

            <?php if ($sum->pipelineIdx >= 0): ?>
            <div class="pipeline-mini <?= h($sum->pipelineVariant) ?>" aria-hidden="true" style="margin-bottom:16px;max-width:100%;">
                <?php for ($s = 0; $s < count($sum->casePipelineSteps); $s++): ?>
                    <div class="<?= $s <= $sum->pipelineIdx ? 'on' : '' ?>"></div>
                <?php endfor; ?>
            </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px;">
                <div>
                    <div class="spi-label">Anticipo</div>
                    <div class="mono" style="font-size:var(--fs-body-lg);font-weight:700;color:var(--text-default);margin-top:2px;">
                        $ <?= number_format($sum->advanceTotal, 0, ',', '.') ?>
                    </div>
                </div>
                <div>
                    <div class="spi-label">Vinculado</div>
                    <div class="mono" style="font-size:var(--fs-body-lg);font-weight:700;color:var(--text-default);margin-top:2px;">
                        $ <?= number_format($sum->linkedTotal, 0, ',', '.') ?>
                    </div>
                </div>
                <div>
                    <div class="spi-label">Diferencia</div>
                    <div style="margin-top:4px;">
                        <span class="pill <?= $sum->diffBadgeClass ?>" style="font-family:var(--font-mono);">
                            $ <?= number_format($sum->diff, 0, ',', '.') ?>
                        </span>
                        <?php if ($sum->caseLabel !== ''): ?>
                        <span style="font-size:11px;color:var(--text-muted);margin-left:6px;">· <?= h($sum->caseLabel) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Legalización: facturas vinculadas (read-only) -->
        <?= $this->element('advance_legalization/_linked_invoices', [
            'leg' => $leg,
            'invoice' => $invoice,
            'linkedInvoices' => $viewModel->linkedInvoices,
            'linkedTotal' => $sum->linkedTotal,
            'linkedCount' => $sum->linkedCount,
            'editable' => false,
        ]) ?>

        <!-- Legalización: soportes (read-only) -->
        <?= $this->element('advance_legalization/_soportes', [
            'leg' => $leg,
            'relationDocument' => $sum->relationDocument,
            'signatureHistory' => $sum->signatureHistory,
            'editable' => false,
            'documents' => $sum->documents,
            'totalDocs' => $sum->totalDocs,
            'canManageDocuments' => false,
        ]) ?>
        <?php else: ?>
        <div class="banner info">
            <div class="banner-icon"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
            <div class="banner-body">
                <div class="banner-msg">La legalización iniciará automáticamente cuando este anticipo llegue al estado <strong>Pagada</strong>.</div>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div>

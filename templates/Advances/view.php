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
<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="sgi-breadcrumb">
            <?= $this->Html->link('Anticipos', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current"><?= h($advIdLabel) ?></span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="sgi-page-title">Ver Anticipo</span>
            <span class="sgi-edit-id-chip"><?= h($advIdLabel) ?></span>
            <span class="pill <?= $advStatusPill ?>"><?= h($advStatusLabel) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
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

<div class="sgi-invoice-view-grid view-anim">

    <!-- ═══════════════════ SIDEBAR ═══════════════════ -->
    <aside class="sgi-invoice-view-left">
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
    <main class="sgi-invoice-view-right">

        <!-- Beneficiario + Detalle -->
        <div class="sgi-card">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;">
                <div>
                    <div class="sgi-label" style="margin-bottom:6px;">Beneficiario</div>
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
                    <div class="sgi-label" style="margin-bottom:6px;">Detalle</div>
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

        <div class="banner info">
            <div class="banner-icon"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
            <div class="banner-body">
                <div class="banner-msg">La legalización iniciará automáticamente cuando este anticipo llegue al estado <strong>Pagada</strong>.</div>
            </div>
        </div>

    </main>
</div>

<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Refund $record
 */
use App\Constants\InvoiceConstants;
use App\Constants\RefundConstants;
use App\View\Presentation\InvoicePresentation;
use App\View\Presentation\RefundPresentation;

$this->assign('title', 'Reintegro ' . $record->code);

$statusBadge  = RefundPresentation::STATUS_BADGES;
$statusLabels = RefundConstants::STATUS_LABELS;

$rfStatusPills = [
    RefundConstants::STATUS_AGRUPACION        => 'pill-muted',
    RefundConstants::STATUS_CONTABILIDAD      => 'pill-primary-soft',
    RefundConstants::STATUS_TESORERIA         => 'pill-info-soft',
    RefundConstants::STATUS_AUTORIZACION_PAGO => 'pill-info-soft',
    RefundConstants::STATUS_VERIFICACION_PAGO => 'pill-warning-soft',
    RefundConstants::STATUS_PAGADA            => 'pill-primary-soft',
];
$rfStatusPill  = $rfStatusPills[$record->status] ?? 'pill-muted';
$rfStatusLabel = $statusLabels[$record->status] ?? $record->status;

$isTerminal = $record->status === RefundConstants::STATUS_PAGADA;
$invoiceCount = count($record->invoices ?? []);
$docs = $record->refund_documents ?? [];

$bName  = $record->getBeneficiaryName();
$bLabel = RefundConstants::BENEFICIARY_TYPES_LABELS[$record->beneficiary_type] ?? null;
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
            'amount'         => (float)$record->total_amount,
            'pipelineSteps'  => RefundConstants::STATUSES,
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

        <!-- Información + Beneficiario -->
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
                            <span class="pill <?= $rfStatusPill ?>"><?= h($rfStatusLabel) ?></span>
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
                        <span class="sgi-label">Beneficiario</span>
                    </div>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Tipo</span>
                        <span class="sgi-data-value"><?= h($bLabel ?? '—') ?></span>
                    </div>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Beneficiario</span>
                        <span class="sgi-data-value"><?= h($bName ?? '—') ?></span>
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

        <?= $this->element('observations/drawer', [
            'observations'    => $record->refund_observations ?? [],
            'count'           => count($record->refund_observations ?? []),
            'formUrl'         => ['action' => 'addObservation', $record->id],
            'currentUserName' => $currentUser->full_name
                ?? ($currentUser->username ?? 'Usuario'),
        ]) ?>

    </main>
</div>

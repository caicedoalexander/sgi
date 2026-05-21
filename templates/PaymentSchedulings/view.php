<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\PaymentScheduling $record
 * @var string $roleName
 * @var float $total
 * @var array $pipelineLabels
 */
use App\Constants\PaymentSchedulingConstants;
use App\View\Presentation\PaymentSchedulingPresentation;

$this->assign('title', 'Programación ' . h($record->code));

$statusLabels = PaymentSchedulingConstants::STATUS_LABELS;
$psStatusPills = PaymentSchedulingPresentation::STATUS_BADGES;
$psStatusPill = $psStatusPills[$record->pipeline_status] ?? 'pill-muted';
$psStatusLabel = $statusLabels[$record->pipeline_status] ?? $record->pipeline_status;

$isTerminal = $record->pipeline_status === PaymentSchedulingConstants::STATUS_PAGADA;
$itemCount = count($record->payment_scheduling_items ?? []);
$documents = $record->payment_scheduling_documents ?? [];
?>

<!-- Page header -->
<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="sgi-breadcrumb">
            <?= $this->Html->link('Programación de Pagos', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current"><?= h($record->code) ?></span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="sgi-page-title">Ver Programación</span>
            <span class="sgi-edit-id-chip"><?= h($record->code) ?></span>
            <span class="pill <?= $psStatusPill ?>"><?= h($psStatusLabel) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
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
            'pipelineSteps'  => PaymentSchedulingConstants::PIPELINE_STATUSES,
            'pipelineLabels' => $pipelineLabels,
            'currentStatus'  => $record->pipeline_status,
            'isTerminal'     => $isTerminal,
            'modifiedAt'     => $record->modified,
            'registryLines'  => $registryLines,
        ]);
        ?>
    </aside>

    <!-- ═══════════════════ CONTENIDO ═══════════════════ -->
    <main class="sgi-invoice-view-right">

        <!-- Información general -->
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
                        <span class="sgi-data-label">Título</span>
                        <span class="sgi-data-value"><?= h($record->title) ?: '—' ?></span>
                    </div>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Estado</span>
                        <span class="sgi-data-value">
                            <span class="pill <?= $psStatusPill ?>"><?= h($psStatusLabel) ?></span>
                        </span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="sgi-section-head" style="padding:14px 18px 0;">
                        <span class="sgi-label">Detalles</span>
                    </div>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Creado por</span>
                        <span class="sgi-data-value"><?= h($record->created_by_user->full_name ?? '—') ?></span>
                    </div>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Facturas</span>
                        <span class="sgi-data-value"><?= $itemCount ?></span>
                    </div>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Monto Total</span>
                        <span class="sgi-data-value mono" style="color:var(--primary-color);font-weight:700;">$ <?= number_format((float)$total, 0, ',', '.') ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Facturas Vinculadas -->
        <?php if ($itemCount > 0): ?>
        <div class="card" style="padding:18px 20px;">
            <div class="sgi-section-head" style="margin-bottom:12px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-receipt" aria-hidden="true"></i>
                    Facturas Vinculadas
                    <span class="sgi-folder-count"><?= $itemCount ?></span>
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
        <div class="card" style="padding:18px 20px;">
            <div class="sgi-section-head" style="margin-bottom:12px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-paperclip" aria-hidden="true"></i>
                    Soportes
                    <span class="sgi-folder-count"><?= count($documents) ?> doc<?= count($documents) !== 1 ? 's' : '' ?></span>
                </span>
            </div>

            <?php if (empty($documents)): ?>
            <div class="sgi-dropzone-empty">
                <i class="bi bi-paperclip" aria-hidden="true"></i>
                <div>Sin soportes adjuntos</div>
            </div>
            <?php else: ?>
            <div style="max-height:420px;overflow-y:auto;">
                <?php foreach ($documents as $att): ?>
                    <?= $this->element('document_row', [
                        'doc'       => $att,
                        'canDelete' => false,
                        'deleteUrl' => null,
                        'showBadge' => false,
                    ]) ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<?= $this->element('observations/drawer', [
    'observations'    => $record->payment_scheduling_observations ?? [],
    'count'           => count($record->payment_scheduling_observations ?? []),
    'formUrl'         => ['action' => 'addObservation', $record->id],
    'currentUserName' => $currentUser->full_name
        ?? ($currentUser->username ?? 'Usuario'),
]) ?>

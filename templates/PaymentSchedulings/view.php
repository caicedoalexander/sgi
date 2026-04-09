<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\PaymentScheduling $record
 * @var string $roleName
 * @var float $total
 * @var array $pipelineLabels
 */
use App\Constants\PaymentSchedulingConstants;

$this->assign('title', 'Programación ' . h($record->code));

$statusBadge = [
    PaymentSchedulingConstants::STATUS_BORRADOR => 'bg-secondary',
    PaymentSchedulingConstants::STATUS_TESORERIA => 'bg-warning text-dark',
    PaymentSchedulingConstants::STATUS_AUT_PAGO => 'bg-info text-dark',
    PaymentSchedulingConstants::STATUS_PAGADA => 'bg-success',
];
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <div>
        <span class="sgi-page-title"><?= h($record->code) ?></span>
        <span class="badge <?= $statusBadge[$record->pipeline_status] ?? 'bg-dark' ?> ms-2">
            <?= $pipelineLabels[$record->pipeline_status] ?? $record->pipeline_status ?>
        </span>
    </div>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-pencil me-1"></i>Editar',
            ['action' => 'edit', $record->id],
            ['class' => 'btn btn-sm btn-outline-primary', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-sm btn-outline-secondary', 'escape' => false]
        ) ?>
    </div>
</div>

<!-- Pipeline Progress -->
<div class="card card-primary mb-4">
    <div class="card-body py-3">
        <div class="d-flex align-items-center justify-content-between">
            <?php foreach (PaymentSchedulingConstants::PIPELINE_STATUSES as $i => $status): ?>
            <?php
                $isCurrent = $status === $record->pipeline_status;
                $isPast = array_search($status, PaymentSchedulingConstants::PIPELINE_STATUSES) < array_search($record->pipeline_status, PaymentSchedulingConstants::PIPELINE_STATUSES);
                $icon = PaymentSchedulingConstants::STATUS_ICONS[$status] ?? 'bi-circle';
            ?>
            <div class="d-flex align-items-center gap-2 <?= $isCurrent ? 'fw-bold' : '' ?>" style="<?= !$isCurrent && !$isPast ? 'opacity:.4;' : '' ?>">
                <i class="bi <?= $icon ?>" style="font-size:1.1rem;color:<?= $isPast ? 'var(--primary-color)' : ($isCurrent ? 'var(--primary-color)' : '#aaa') ?>;"></i>
                <span style="font-size:.78rem;"><?= $pipelineLabels[$status] ?? $status ?></span>
            </div>
            <?php if ($i < count(PaymentSchedulingConstants::PIPELINE_STATUSES) - 1): ?>
            <div style="flex:1;height:2px;margin:0 .5rem;background:<?= $isPast ? 'var(--primary-color)' : '#e0e0e0' ?>;"></div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Info -->
<div class="card card-primary mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="sgi-data-row">
                    <span class="sgi-data-label">Código</span>
                    <span class="sgi-data-value" style="font-family:monospace;"><?= h($record->code) ?></span>
                </div>
            </div>
            <div class="col-md-5">
                <div class="sgi-data-row">
                    <span class="sgi-data-label">Título</span>
                    <span class="sgi-data-value"><?= h($record->title) ?></span>
                </div>
            </div>
            <div class="col-md-2">
                <div class="sgi-data-row">
                    <span class="sgi-data-label">Creado por</span>
                    <span class="sgi-data-value"><?= h($record->created_by_user->full_name ?? '') ?></span>
                </div>
            </div>
            <div class="col-md-2">
                <div class="sgi-data-row">
                    <span class="sgi-data-label">Monto Total</span>
                    <span class="sgi-data-value fw-bold">$ <?= number_format($total, 0, ',', '.') ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Facturas -->
<?php if (!empty($record->payment_scheduling_items)): ?>
<div class="card card-primary mb-4">
    <div class="card-header">
        <span style="font-size:.85rem;font-weight:600;">
            <i class="bi bi-receipt me-1"></i>Facturas Vinculadas
            <span class="badge bg-secondary ms-1"><?= count($record->payment_scheduling_items) ?></span>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
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
                    <td style="font-family:monospace;"><?= h($item->invoice->invoice_number ?? 'ID:' . $item->invoice_id) ?></td>
                    <td><?= h($item->invoice->provider->name ?? '—') ?></td>
                    <td><?= h($item->banking_entity->name ?? '—') ?></td>
                    <td class="text-end fw-bold">$ <?= number_format((float)$item->amount, 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
                <tr>
                    <th colspan="3">Total</th>
                    <th class="text-end">$ <?= number_format($total, 0, ',', '.') ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Soportes -->
<?php if (!empty($record->payment_scheduling_attachments)): ?>
<div class="card card-primary mb-4">
    <div class="card-header">
        <span style="font-size:.85rem;font-weight:600;"><i class="bi bi-paperclip me-1"></i>Soportes</span>
    </div>
    <div class="card-body">
        <div class="list-group list-group-flush">
            <?php foreach ($record->payment_scheduling_attachments as $att): ?>
            <div class="list-group-item px-0">
                <a href="<?= $this->Url->build('/' . $att->file_path) ?>" target="_blank" style="font-size:.85rem;">
                    <i class="bi bi-file-earmark me-1"></i><?= h($att->file_name) ?>
                </a>
                <br><small class="text-muted"><?= h($att->uploaded_by_user->full_name ?? '') ?> — <?= $att->created?->format('d/m/Y H:i') ?></small>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Observaciones -->
<?php if (!empty($record->payment_scheduling_observations)): ?>
<div class="card card-primary mb-4">
    <div class="card-header">
        <span style="font-size:.85rem;font-weight:600;"><i class="bi bi-chat-left-text me-1"></i>Observaciones</span>
    </div>
    <div class="card-body">
        <?php foreach ($record->payment_scheduling_observations as $obs): ?>
        <div class="mb-3 pb-3" style="border-bottom:1px solid var(--border-color);">
            <div class="d-flex justify-content-between">
                <strong style="font-size:.82rem;"><?= h($obs->user->full_name ?? '') ?></strong>
                <small class="text-muted"><?= $obs->created?->format('d/m/Y H:i') ?></small>
            </div>
            <p class="mb-0 mt-1" style="font-size:.85rem;"><?= nl2br(h($obs->message)) ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

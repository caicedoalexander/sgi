<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Refund $record
 */
use App\Constants\RefundConstants;

$this->assign('title', 'Reintegro ' . $record->code);

$statusBadge = [
    'agrupacion' => 'bg-info text-dark',
    'contabilidad' => 'bg-primary',
    'tesoreria' => 'bg-warning text-dark',
    'autorizacion_pago' => 'bg-secondary',
    'pagada' => 'bg-success',
];
$statusLabels = RefundConstants::STATUS_LABELS;

?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Ver Registro de Reintegro</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?php if (!empty($userPermissions['refunds']['can_edit']) && !$record->isPagada()): ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil me-1"></i>Editar',
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
                <i class="bi bi-wallet2"></i>
            </div>
            <div>
                <div style="font-size:1.25rem;font-weight:700;letter-spacing:-.03em;color:#111;line-height:1.15;font-family:monospace;">
                    <?= h($record->code) ?>
                </div>
                <div class="mt-1 d-flex align-items-center gap-2">
                    <span class="badge <?= $statusBadge[$record->status] ?? 'bg-dark' ?>">
                        <?= $statusLabels[$record->status] ?? $record->status ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="text-end flex-shrink-0">
            <div style="font-size:.55rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:#bbb;margin-bottom:.2rem;">Total</div>
            <div style="font-size:1.55rem;font-weight:700;letter-spacing:-.04em;color:var(--primary-color);line-height:1;white-space:nowrap;">
                $ <?= $this->Number->format($record->total_amount, ['places' => 2]) ?>
            </div>
        </div>
    </div>

    <!-- Progress -->
    <div style="background:#fafafa;border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color);padding:1.25rem 1.5rem;">
        <?= $this->element('refund_progress', ['status' => $record->status]) ?>
    </div>

    <!-- Info -->
    <div class="row g-0" style="border-bottom:1px solid var(--border-color);">
        <div class="col-md-6" style="border-right:1px solid var(--border-color);">
            <div class="sgi-section-title">Información</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Código</span>
                <span class="sgi-data-value" style="font-family:monospace;"><?= h($record->code) ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Estado</span>
                <span class="sgi-data-value">
                    <span class="badge <?= $statusBadge[$record->status] ?? 'bg-dark' ?>">
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
            <div class="sgi-section-title">Beneficiario</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Tipo</span>
                <span class="sgi-data-value"><?= h(RefundConstants::BENEFICIARY_TYPES_LABELS[$record->beneficiary_type] ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Beneficiario</span>
                <span class="sgi-data-value"><?= h($record->getBeneficiaryName() ?? '—') ?></span>
            </div>
        </div>
    </div>
</div>


<!-- Facturas agrupadas -->
<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-receipt"></i>
        <span>Facturas Agrupadas</span>
        <span class="sgi-folder-count"><?= count($record->invoices ?? []) ?></span>
    </div>
    <?php if (empty($record->invoices)): ?>
    <div class="p-3 text-center text-muted" style="font-size:.875rem;">
        <i class="bi bi-inbox me-1"></i>No hay facturas agrupadas
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
                    <td style="font-family:monospace;font-weight:600;"><?= h($inv->invoice_number ?? '#' . $inv->id) ?></td>
                    <td><?= $inv->hasValue('provider') ? h($inv->provider->name) : '—' ?></td>
                    <td class="text-end">$ <?= $this->Number->format($inv->amount, ['places' => 2]) ?></td>
                    <td><?= $inv->issue_date?->format('d/m/Y') ?? '—' ?></td>
                    <td>
                        <?php
                        $pBadge = match($inv->pipeline_status) {
                            'aprobacion' => 'bg-info text-dark',
                            'contabilidad' => 'bg-primary',
                            'tesoreria' => 'bg-warning text-dark',
                            'pagada' => 'bg-success',
                            default => 'bg-dark',
                        };
                        ?>
                        <span class="badge <?= $pBadge ?>"><?= h($inv->pipeline_status) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php $docs = $record->refund_documents ?? []; ?>
<div class="card card-primary mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip" style="font-size:.85rem;"></i>
            <span style="font-size:.85rem;font-weight:600;">Soportes</span>
            <span class="sgi-folder-count"><?= count($docs) ?> doc<?= count($docs) !== 1 ? 's' : '' ?></span>
        </span>
    </div>
    <?php if (empty($docs)): ?>
        <div style="padding:2rem 1rem;text-align:center;color:#c8c8c8;">
            <i class="bi bi-file-earmark-x d-block mb-2" style="font-size:1.5rem;"></i>
            <span style="font-size:.8rem;">Sin soportes adjuntos</span>
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
<?php $obsList = $record->refund_observations ?? []; ?>
<?php if (!empty($obsList)): ?>
<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-chat-left-text"></i>
        <span>Observaciones</span>
        <span class="sgi-folder-count"><?= count($obsList) ?></span>
    </div>
    <div class="p-3" style="max-height:400px;overflow-y:auto;">
        <?php foreach ($obsList as $obs): ?>
        <?php
            $isRegression = ($obs->type ?? null) === RefundConstants::OBSERVATION_TYPE_REGRESSION;
            $meta = $obs->metadata ?? [];
            $fromLbl = $statusLabels[$meta['from_status'] ?? ''] ?? null;
            $toLbl = $statusLabels[$meta['to_status'] ?? ''] ?? null;
        ?>
        <div class="d-flex align-items-start gap-2 mb-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:32px;height:32px;background:<?= $isRegression ? '#CD6A15' : 'var(--primary-color)' ?>;color:#fff;font-size:.7rem;font-weight:700;">
                <?php
                $names = explode(' ', $obs->user->full_name ?? '');
                echo strtoupper(substr($names[0] ?? '', 0, 1) . substr($names[1] ?? '', 0, 1));
                ?>
            </div>
            <div style="flex:1;min-width:0;">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span style="font-size:.8rem;font-weight:600;color:#222;">
                        <?= h($obs->user->full_name ?? '') ?>
                    </span>
                    <?php if ($isRegression): ?>
                        <span class="badge bg-warning text-dark" style="font-size:.65rem;">Regresión</span>
                    <?php endif; ?>
                    <span style="font-size:.7rem;color:#aaa;">
                        <?= $obs->created ? $obs->created->format('d/m/Y H:i') : '' ?>
                    </span>
                </div>
                <?php if ($isRegression && $fromLbl && $toLbl): ?>
                    <div style="font-size:.74rem;color:#666;margin-top:.1rem;">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                        <?= h($fromLbl) ?> &rarr; <?= h($toLbl) ?>
                    </div>
                <?php endif; ?>
                <div style="font-size:.84rem;color:#444;line-height:1.5;margin-top:.15rem;">
                    <?= nl2br(h($obs->message)) ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

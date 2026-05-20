<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 * @var string $roleName
 * @var bool $isRejected
 * @var bool $isApproved
 * @var bool $canShowEdit
 * @var bool $showPettyCashLock
 * @var bool $showSchedulingLock
 * @var string[] $pipelineStatuses
 * @var string[] $pipelineLabels
 * @var array<string,\App\Model\Entity\InvoiceDocument[]> $documentsByStatus
 * @var array<string,string> $fieldLabels
 */

use App\Constants\InvoiceConstants;

$this->assign('title', 'Factura ' . ($invoice->invoice_number ?? '#' . $invoice->id));

// ─── Datos derivados de presentación ────────────────────────────────
$currentStatus = $invoice->pipeline_status ?? '';
$statusLabel   = InvoiceConstants::STATUS_LABELS[$currentStatus] ?? 'Desconocido';

// Pill kind del estado del pipeline (soft variants).
$statusPills = [
    InvoiceConstants::STATUS_APROBACION        => 'pill-warning-soft',
    InvoiceConstants::STATUS_CONTABILIDAD      => 'pill-secondary-soft',
    InvoiceConstants::STATUS_TESORERIA         => 'pill-info-soft',
    InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'pill-warning-soft',
    InvoiceConstants::STATUS_VERIFICACION_PAGO => 'pill-warning-soft',
    InvoiceConstants::STATUS_PAGADA            => 'pill-primary-soft',
    InvoiceConstants::STATUS_LEGALIZADA        => 'pill-primary-soft',
];
$statusPill = $statusPills[$currentStatus] ?? 'pill-muted';

$readyForPaymentPills = [
    InvoiceConstants::READY_FOR_PAYMENT_SI          => 'pill-primary-soft',
    InvoiceConstants::READY_FOR_PAYMENT_PRIORITARIO => 'pill-danger-soft',
    InvoiceConstants::READY_FOR_PAYMENT_PSE         => 'pill-dark',
];

$pipelineSteps = $pipelineStatuses;
$currentIdx    = array_search($currentStatus, $pipelineSteps, true);
if ($currentIdx === false) {
    $currentIdx = count($pipelineSteps);
}

// Formateo monto.
$amountFmt = (float)$invoice->amount;
$amountInt = number_format(floor($amountFmt), 0, ',', '.');
$amountDec = sprintf(',%02d', (int)round(($amountFmt - floor($amountFmt)) * 100));

// Total de soportes.
$documentsByStatus = $documentsByStatus ?? [];
$totalDocs = array_sum(array_map('count', $documentsByStatus));

// Total pagado.
$pagosCount = is_array($invoice->invoice_payments ?? null) ? count($invoice->invoice_payments) : 0;
$pagosTotal = 0.0;
foreach ($invoice->invoice_payments ?? [] as $p) {
    $pagosTotal += (float)$p->amount;
}

// Aprobadores que ya aprobaron.
$approvedNames = [];
foreach ($invoice->invoice_approvals ?? [] as $a) {
    if ($a->status === InvoiceConstants::APPROVER_STATUS_APPROVED && $a->hasValue('user')) {
        $approvedNames[] = $a->user->full_name ?? $a->user->username ?? ('Usuario #' . $a->user_id);
    }
}

// Titular (recibo de caja vs. factura común).
$isReciboDeCaja = ($invoice->document_type ?? '') === InvoiceConstants::DOCTYPE_RECIBO_CAJA;
$providerName = '';
if ($isReciboDeCaja && ($invoice->equivalent_holder_type ?? '') === 'employee') {
    $providerName = $invoice->hasValue('employee') ? $invoice->employee->full_name : '—';
} elseif ($isReciboDeCaja && ($invoice->equivalent_holder_type ?? '') === 'manual') {
    $providerName = $invoice->manual_document_number ?? '—';
} else {
    $providerName = $invoice->hasValue('provider') ? $invoice->provider->name : '—';
}

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
?>

<?php if (($invoice->document_type ?? null) === InvoiceConstants::DOCTYPE_LEGALIZACION && !empty($invoice->advance_id)): ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center">
        <div>
            <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>
            Esta factura es una <strong>Legalización</strong> vinculada al
            <?= $this->Html->link('Anticipo #' . h($invoice->advance_id), ['controller' => 'Advances', 'action' => 'view', $invoice->advance_id]) ?>.
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($showPettyCashLock)): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-lock-fill fs-5" aria-hidden="true"></i>
    <div>
        Factura bloqueada: pertenece al registro de Caja Menor
        <strong><?= $this->Html->link(
            h($invoice->petty_cash_record->code ?? '#' . $invoice->petty_cash_record_id),
            ['controller' => 'PettyCashRecords', 'action' => 'view', $invoice->petty_cash_record_id],
            ['class' => 'alert-link']
        ) ?></strong>. Los cambios se gestionan desde allí.
    </div>
</div>
<?php endif; ?>

<?php if (!empty($showSchedulingLock)): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-lock-fill fs-5" aria-hidden="true"></i>
    <div>
        Factura bloqueada: tiene pagos aplicados desde una <strong>programación ya pagada</strong>.
    </div>
</div>
<?php endif; ?>

<!-- ─── Page header: breadcrumb + título + acciones ───────────────── -->
<div class="d-flex justify-content-between align-items-start" style="margin-bottom:16px;">
    <div>
        <div class="d-flex align-items-center gap-1" style="font-size:11.5px;color:var(--text-faint);margin-bottom:4px;">
            <?= $this->Html->link('Todas las Facturas', ['action' => 'index'], ['style' => 'color:inherit;text-decoration:none;']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:10px;"></i>
            <span style="color:var(--text-default);">Ver Factura</span>
        </div>
        <h1 class="sgi-title-page">Ver Factura</h1>
    </div>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-default', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-file-pdf" aria-hidden="true"></i>Descargar PDF',
            '#',
            ['class' => 'btn btn-default', 'escape' => false]
        ) ?>
        <?php if ($canShowEdit): ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $invoice->id],
            ['class' => 'btn btn-secondary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- ─── Grid principal (340px + 1fr) ──────────────────────────────── -->
<div class="view-anim" style="display:grid;grid-template-columns:340px 1fr;gap:16px;">

    <!-- ═════════════════════════ COLUMNA IZQUIERDA ═════════════════════════ -->
    <aside style="display:flex;flex-direction:column;gap:14px;min-width:0;">

        <!-- Hero card -->
        <div class="sgi-card">
            <div class="d-flex align-items-start" style="gap:12px;margin-bottom:16px;">
                <div style="width:40px;height:40px;background:var(--primary-soft-strong);color:var(--primary-color);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-file-earmark-text" aria-hidden="true" style="font-size:18px;"></i>
                </div>
                <div style="min-width:0;flex:1;">
                    <div class="mono" style="font-size:16px;font-weight:700;color:var(--text-strong);line-height:1.1;">
                        <?= h($invoice->invoice_number ?? ('#' . $invoice->id)) ?>
                    </div>
                    <div class="d-flex flex-wrap" style="gap:4px;margin-top:6px;">
                        <span class="pill pill-secondary-soft"><?= h($invoice->document_type) ?></span>
                        <?php if ($isRejected): ?>
                            <span class="pill pill-danger-soft">Rechazada</span>
                        <?php elseif ($isApproved): ?>
                            <span class="pill pill-primary-soft">
                                <i class="bi bi-check" aria-hidden="true" style="font-size:10px;"></i>Aprobada
                            </span>
                        <?php else: ?>
                            <span class="pill <?= h($statusPill) ?>"><?= h($statusLabel) ?></span>
                        <?php endif; ?>
                        <?php if ($currentStatus === InvoiceConstants::STATUS_TESORERIA && $invoice->payment_status === InvoiceConstants::PAYMENT_PARTIAL): ?>
                            <span class="pill pill-warning-soft">Pago Parcial</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="sgi-label">Proveedor</div>
            <div style="font-size:var(--fs-body);font-weight:600;color:var(--text-default);margin-top:4px;line-height:1.3;">
                <?= h($providerName) ?>
            </div>
            <?php if ($invoice->hasValue('operation_center')): ?>
            <div class="d-flex align-items-center gap-1" style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                <i class="bi bi-geo-alt" aria-hidden="true" style="font-size:11px;"></i>
                <span><?= h($invoice->operation_center->name) ?></span>
            </div>
            <?php endif; ?>

            <div class="hr"></div>

            <div class="sgi-label">Valor Factura</div>
            <div class="d-flex align-items-baseline" style="gap:4px;margin-top:4px;">
                <?php $amountColor = $currentStatus === InvoiceConstants::STATUS_PAGADA ? 'var(--primary-color)' : 'var(--text-strong)'; ?>
                <span class="sgi-display" style="color:<?= $amountColor ?>;">$ <?= $amountInt ?></span>
                <span style="font-size:13px;color:var(--text-faint);font-weight:500;"><?= $amountDec ?></span>
            </div>
            <?php if ($currentStatus === InvoiceConstants::STATUS_PAGADA && $invoice->full_payment_date): ?>
            <div class="d-flex align-items-center gap-1" style="font-size:11px;color:var(--text-muted);margin-top:6px;">
                <i class="bi bi-check-circle sgi-fg-primary" aria-hidden="true" style="font-size:11px;"></i>
                <span>Pagado · <span class="mono"><?= $invoice->full_payment_date->format('d/m/Y') ?></span></span>
            </div>
            <?php elseif ($invoice->payment_status === InvoiceConstants::PAYMENT_PARTIAL && $pagosCount > 0): ?>
            <div class="d-flex align-items-center gap-1" style="font-size:11px;color:var(--text-muted);margin-top:6px;">
                <i class="bi bi-clock sgi-fg-warning" aria-hidden="true" style="font-size:11px;"></i>
                <span>Pago parcial · <span class="mono">$ <?= number_format($pagosTotal, 0, ',', '.') ?></span></span>
            </div>
            <?php endif; ?>

            <div class="hr"></div>

            <div class="field-row">
                <span class="k">Emisión</span>
                <span class="v mono"><?= $invoice->issue_date?->format('d/m/Y') ?? '—' ?></span>
            </div>
            <div class="field-row">
                <span class="k">Vencimiento</span>
                <?php
                $isOverdue = $invoice->due_date && $invoice->due_date < new \DateTimeImmutable('today')
                    && $currentStatus !== InvoiceConstants::STATUS_PAGADA;
                ?>
                <span class="v mono" style="<?= $isOverdue ? 'color:var(--danger-color);' : '' ?>">
                    <?= $invoice->due_date?->format('d/m/Y') ?? '—' ?>
                </span>
            </div>
            <div class="field-row is-last">
                <span class="k">Registro</span>
                <span class="v mono"><?= $invoice->registration_date?->format('d/m/Y') ?? '—' ?></span>
            </div>
        </div>

        <!-- Pipeline vertical -->
        <div class="sgi-card">
            <div class="d-flex justify-content-between align-items-center" style="margin-bottom:6px;">
                <span class="sgi-label">Pipeline</span>
            </div>
            <div class="pipeline-v">
                <?php
                $isTerminal = in_array($currentStatus, [InvoiceConstants::STATUS_PAGADA, InvoiceConstants::STATUS_LEGALIZADA], true);
                foreach ($pipelineSteps as $idx => $stepKey):
                    $isDone    = $idx < $currentIdx || ($isTerminal && $idx === $currentIdx);
                    $isCurrent = !$isTerminal && $idx === $currentIdx;
                    $stepLabel = $pipelineLabels[$stepKey] ?? $stepKey;
                    $stepRejected = $isCurrent && $isRejected;

                    $cls = 'pv-step';
                    if ($stepRejected) { $cls .= ' is-rejected'; }
                    elseif ($isDone)   { $cls .= ' is-done'; }
                    elseif ($isCurrent){ $cls .= ' is-current'; }
                    else               { $cls .= ' is-pending'; }

                    $stepMeta = null;
                    if ($isCurrent || ($isTerminal && $idx === $currentIdx)) {
                        $stepMeta = $invoice->modified?->format('d/m H:i');
                    } elseif (!$isDone) {
                        $stepMeta = 'Pendiente';
                    }
                ?>
                <div class="<?= $cls ?>">
                    <div class="pv-marker">
                        <?php if ($stepRejected): ?>
                            <i class="bi bi-x" aria-hidden="true"></i>
                        <?php elseif ($isDone): ?>
                            <i class="bi bi-check" aria-hidden="true"></i>
                        <?php elseif ($isCurrent): ?>
                            <span class="dot"></span>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:0;padding-top:1px;">
                        <div class="pv-label"><?= h($stepLabel) ?></div>
                        <?php if ($stepMeta): ?>
                            <div class="pv-meta"><?= h($stepMeta) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Acciones rápidas -->
        <?php
        $actions = [];
        if ($canShowEdit) {
            $actions[] = [
                'icon'  => 'bi-pencil',
                'label' => 'Editar factura',
                'url'   => $this->Url->build(['action' => 'edit', $invoice->id]),
            ];
        }
        $actions[] = [
            'icon'  => 'bi-file-pdf',
            'label' => 'Descargar PDF',
            'url'   => '#',
        ];
        $actions[] = [
            'icon'  => 'bi-arrow-left',
            'label' => 'Volver al listado',
            'url'   => $this->Url->build(['action' => 'index']),
        ];
        ?>
        <?php if (!empty($actions)): ?>
        <div class="sgi-card compact">
            <div class="sgi-label" style="margin-bottom:10px;">Acciones</div>
            <div class="col-flex" style="gap:2px;">
                <?php foreach ($actions as $a): ?>
                    <?= $this->Html->link(
                        '<i class="bi ' . h($a['icon']) . '" aria-hidden="true"></i><span>' . h($a['label']) . '</span>',
                        $a['url'],
                        [
                            'class'   => 'btn btn-ghost btn-sm',
                            'escape'  => false,
                            'style'   => 'justify-content:flex-start;width:100%;gap:8px;',
                        ]
                    ) ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </aside>

    <!-- ═════════════════════════ COLUMNA DERECHA ═════════════════════════ -->
    <main style="display:flex;flex-direction:column;gap:14px;min-width:0;">

        <!-- Datos generales (Documento + Clasificación) -->
        <div class="sgi-card">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;">
                <div>
                    <div class="sgi-label" style="margin-bottom:6px;">Documento</div>
                    <div class="field-row">
                        <span class="k">Fecha Registro</span>
                        <span class="v mono"><?= $invoice->registration_date?->format('d/m/Y') ?? '—' ?></span>
                    </div>
                    <div class="field-row">
                        <span class="k">Fecha Emisión</span>
                        <span class="v mono"><?= $invoice->issue_date?->format('d/m/Y') ?? '—' ?></span>
                    </div>
                    <div class="field-row">
                        <span class="k">Fecha Vencimiento</span>
                        <span class="v mono"><?= $invoice->due_date?->format('d/m/Y') ?? '—' ?></span>
                    </div>
                    <div class="field-row is-last">
                        <span class="k">Orden de Compra</span>
                        <?php if ($invoice->purchase_order): ?>
                            <span class="v mono"><?= h($invoice->purchase_order) ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <div class="sgi-label" style="margin-bottom:6px;">Clasificación</div>
                    <div class="field-row">
                        <span class="k">Titular</span>
                        <span class="v"><?= h($providerName) ?></span>
                    </div>
                    <div class="field-row">
                        <span class="k">Tipo de Gasto</span>
                        <?php if ($invoice->hasValue('expense_type')): ?>
                            <span class="v"><?= h($invoice->expense_type->name) ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="field-row">
                        <span class="k">Centro de Costos</span>
                        <?php if ($invoice->hasValue('cost_center')): ?>
                            <span class="v"><?= h($invoice->cost_center->name) ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="field-row is-last">
                        <span class="k">Centro Operación</span>
                        <?php if ($invoice->hasValue('operation_center')): ?>
                            <span class="v"><?= h($invoice->operation_center->name) ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($invoice->detail): ?>
            <div class="hr"></div>
            <div class="sgi-label" style="margin-bottom:8px;">Detalle</div>
            <div style="font-size:var(--fs-body-lg);color:var(--text-default);line-height:1.5;">
                <?= nl2br(h($invoice->detail)) ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Revisión + Contabilidad + Tesorería -->
        <div class="sgi-card">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px;">
                <!-- Revisión -->
                <div>
                    <div class="sgi-label" style="margin-bottom:6px;">Revisión</div>
                    <div class="field-row">
                        <span class="k">Aprobador</span>
                        <?php if ($invoice->hasValue('approver_user')): ?>
                            <span class="v"><?= h($invoice->approver_user->full_name) ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="field-row">
                        <span class="k">Aprobado Por</span>
                        <?php if (!empty($approvedNames)): ?>
                            <span class="v" title="<?= h(implode(', ', $approvedNames)) ?>"><?= h(implode(', ', $approvedNames)) ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="field-row">
                        <span class="k">Aprobación Área</span>
                        <span class="v">
                            <?php
                            $approval = $invoice->area_approval ?? InvoiceConstants::APPROVAL_PENDING;
                            $approvalPill = match ($approval) {
                                InvoiceConstants::APPROVAL_APPROVED => 'pill-primary-soft',
                                InvoiceConstants::APPROVAL_REJECTED => 'pill-danger-soft',
                                default => 'pill-warning-soft',
                            };
                            ?>
                            <span class="pill pill-sm <?= $approvalPill ?>"><?= h($approval) ?></span>
                        </span>
                    </div>
                    <div class="field-row">
                        <span class="k">Fecha Aprobación</span>
                        <?php if ($invoice->area_approval_date): ?>
                            <span class="v mono"><?= $invoice->area_approval_date->format('d/m/Y') ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="field-row is-last">
                        <span class="k">Validación DIAN</span>
                        <span class="v">
                            <?php
                            $dian = $invoice->dian_validation ?? InvoiceConstants::DIAN_PENDING;
                            $dianPill = match ($dian) {
                                InvoiceConstants::DIAN_APPROVED => 'pill-primary-soft',
                                InvoiceConstants::DIAN_REJECTED => 'pill-danger-soft',
                                default => 'pill-warning-soft',
                            };
                            ?>
                            <span class="pill pill-sm <?= $dianPill ?>"><?= h($dian) ?></span>
                        </span>
                    </div>
                </div>

                <!-- Contabilidad -->
                <div>
                    <div class="sgi-label" style="margin-bottom:6px;">Contabilidad</div>
                    <div class="field-row">
                        <span class="k">Causada</span>
                        <span class="v">
                            <?php if ($invoice->accrued): ?>
                                <span class="pill pill-sm pill-primary-soft">Sí</span>
                            <?php else: ?>
                                <span class="pill pill-sm pill-warning-soft">No</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="field-row">
                        <span class="k">Fecha Causación</span>
                        <?php if ($invoice->accrual_date): ?>
                            <span class="v mono"><?= $invoice->accrual_date->format('d/m/Y') ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="field-row is-last">
                        <span class="k">Lista para Pago</span>
                        <span class="v">
                            <?php if (!empty($invoice->ready_for_payment)): ?>
                                <?php $rfpPill = $readyForPaymentPills[$invoice->ready_for_payment] ?? 'pill-muted'; ?>
                                <span class="pill pill-sm <?= $rfpPill ?>"><?= h($invoice->ready_for_payment) ?></span>
                            <?php else: ?>
                                <span class="pill pill-sm pill-warning-soft">No</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <!-- Tesorería -->
                <div>
                    <div class="sgi-label" style="margin-bottom:6px;">Tesorería</div>
                    <div class="field-row">
                        <span class="k">Estado Pago</span>
                        <span class="v">
                            <?php if ($invoice->payment_status === InvoiceConstants::PAYMENT_PARTIAL): ?>
                                <span class="pill pill-sm pill-warning-soft"><?= h($invoice->payment_status) ?></span>
                            <?php elseif ($invoice->payment_status === InvoiceConstants::PAYMENT_FULL): ?>
                                <span class="pill pill-sm pill-primary-soft"><?= h($invoice->payment_status) ?></span>
                            <?php else: ?>
                                <span class="v dash">—</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="field-row is-last">
                        <span class="k">Fecha Pago Total</span>
                        <?php if ($invoice->full_payment_date): ?>
                            <span class="v mono"><?= $invoice->full_payment_date->format('d/m/Y') ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pagos Registrados -->
        <?php if ($pagosCount > 0): ?>
        <div class="sgi-card" style="padding:0;overflow:hidden;">
            <div class="d-flex justify-content-between align-items-center" style="padding:18px 20px 14px;">
                <div>
                    <div class="sgi-title-card">Pagos Registrados</div>
                    <div style="font-size:11px;color:var(--text-faint);margin-top:2px;">
                        <?= $pagosCount ?> movimiento<?= $pagosCount === 1 ? '' : 's' ?> · Total
                        <span class="mono">$ <?= number_format($pagosTotal, 0, ',', '.') ?></span>
                    </div>
                </div>
            </div>

            <?php foreach ($invoice->invoice_payments as $i => $payment):
                $pSt = $payment->status ?? ($payment->authorized ? 'authorized' : 'pending');
                $bankName = $payment->banking_entity->name ?? '—';
                $registeredBy = $payment->created_by_user->full_name ?? $payment->created_by_user->username ?? '—';
                $payDate = $payment->payment_date?->format('d/m/Y') ?? '—';
            ?>
            <div class="row-flex" style="padding:14px 20px;gap:14px;border-top:1px solid var(--rule);align-items:center;">
                <div class="bank-chip">
                    <i class="bi bi-bank" aria-hidden="true"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:var(--fs-body);font-weight:600;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= h($bankName) ?>
                    </div>
                    <div class="mono" style="font-size:10.5px;color:var(--text-faint);margin-top:2px;">
                        <?= h($payDate) ?> · por <?= h($registeredBy) ?>
                    </div>
                    <?php if ($pSt === 'rejected' && !empty($payment->rejection_reason)): ?>
                    <div style="font-size:10.5px;color:var(--danger-color);margin-top:3px;line-height:1.3;">
                        <?= h($payment->rejection_reason) ?>
                    </div>
                    <?php elseif ($pSt === 'authorized' && $payment->authorized_by_user): ?>
                    <div class="mono" style="font-size:10px;color:var(--text-faint);margin-top:2px;">
                        Autorizado por <?= h($payment->authorized_by_user->full_name ?? '') ?>
                        <?= $payment->authorized_date ? ' · ' . $payment->authorized_date->format('d/m/Y') : '' ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div style="text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
                    <?php if ($pSt === 'authorized'): ?>
                        <span class="pill pill-primary-soft">
                            <i class="bi bi-check" aria-hidden="true" style="font-size:10px;"></i>Autorizado
                        </span>
                    <?php elseif ($pSt === 'rejected'): ?>
                        <span class="pill pill-danger-soft">
                            <i class="bi bi-x" aria-hidden="true" style="font-size:10px;"></i>Rechazado
                        </span>
                    <?php else: ?>
                        <span class="pill pill-warning-soft">
                            <i class="bi bi-clock" aria-hidden="true" style="font-size:10px;"></i>Pendiente
                        </span>
                    <?php endif; ?>
                    <?php
                    $amountColorPay = $pSt === 'authorized' ? 'var(--primary-color)' : 'var(--text-strong)';
                    ?>
                    <span class="mono" style="font-size:14px;font-weight:700;color:<?= $amountColorPay ?>;">
                        $ <?= number_format((float)$payment->amount, 0, ',', '.') ?>
                    </span>
                    <?php if (!empty($payment->payment_scheduling_id)): ?>
                        <?= $this->Html->link(
                            '<i class="bi bi-calendar-check" aria-hidden="true"></i> ' . h($payment->payment_scheduling->code ?? '#' . $payment->payment_scheduling_id),
                            ['controller' => 'PaymentSchedulings', 'action' => 'view', $payment->payment_scheduling_id],
                            ['class' => 'mono', 'escape' => false, 'style' => 'font-size:10px;color:var(--text-muted);text-decoration:none;']
                        ) ?>
                    <?php elseif (!empty($payment->petty_cash_record_id)): ?>
                        <?= $this->Html->link(
                            '<i class="bi bi-wallet2" aria-hidden="true"></i> ' . h($payment->petty_cash_record->code ?? '#' . $payment->petty_cash_record_id),
                            ['controller' => 'PettyCashRecords', 'action' => 'view', $payment->petty_cash_record_id],
                            ['class' => 'mono', 'escape' => false, 'style' => 'font-size:10px;color:var(--text-muted);text-decoration:none;']
                        ) ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Total -->
            <div class="row-flex" style="padding:14px 20px;background:var(--bg-subtle);border-top:1px solid var(--rule);">
                <span style="font-weight:700;color:var(--text-strong);font-size:var(--fs-body-lg);">Total Pagado</span>
                <span class="mono" style="margin-left:auto;font-weight:700;color:var(--primary-color);font-size:var(--fs-title-card);">
                    $ <?= number_format($pagosTotal, 0, ',', '.') ?>
                </span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Observaciones -->
        <?php
        $obsCount = is_array($invoice->invoice_observations ?? null) ? count($invoice->invoice_observations) : 0;
        $statusLabelsMap = InvoiceConstants::STATUS_LABELS;
        ?>
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
                    <div class="es-msg">No se han registrado observaciones para esta factura.</div>
                </div>
            <?php else: ?>
                <?php foreach ($invoice->invoice_observations as $i => $obs):
                    $obsType            = $obs->type ?? null;
                    $isRegression       = $obsType === InvoiceConstants::OBSERVATION_TYPE_REGRESSION;
                    $isExternalApproval = $obsType === InvoiceConstants::OBSERVATION_TYPE_EXTERNAL_APPROVAL;
                    $meta = $obs->metadata ?? [];
                    if (is_string($meta)) {
                        $decoded = json_decode($meta, true);
                        $meta = is_array($decoded) ? $decoded : [];
                    }
                    $fromLbl    = $statusLabelsMap[$meta['from_status'] ?? ''] ?? null;
                    $toLbl      = $statusLabelsMap[$meta['to_status'] ?? ''] ?? null;
                    $extAction  = $meta['action'] ?? null;
                    $extActionLabel = $extAction === 'approve' ? 'Aprobada' : ($extAction === 'reject' ? 'Rechazada' : null);
                    $userName = $obs->user->full_name ?? '';
                    $isLast   = $i === $obsCount - 1;
                ?>
                <div class="d-flex" style="gap:12px;padding:10px 0;<?= $isLast ? '' : 'border-bottom:1px solid var(--rule);' ?>">
                    <div class="av av-md"><?= h($initialsOf($userName)) ?></div>
                    <div style="flex:1;min-width:0;">
                        <div class="d-flex align-items-center flex-wrap" style="gap:8px;margin-bottom:4px;">
                            <span style="font-size:var(--fs-body);font-weight:600;color:var(--text-strong);"><?= h($userName) ?></span>
                            <?php if ($isRegression): ?>
                                <span class="pill pill-warning-soft">Regresión</span>
                            <?php elseif ($isExternalApproval): ?>
                                <span class="pill <?= $extAction === 'reject' ? 'pill-danger-soft' : 'pill-primary-soft' ?>">
                                    Aprobación Externa<?= $extActionLabel ? ': ' . h($extActionLabel) : '' ?>
                                </span>
                            <?php endif; ?>
                            <span class="mono" style="margin-left:auto;font-size:10.5px;color:var(--text-faint);">
                                <?= $obs->created ? $obs->created->format('d/m/Y H:i') : '' ?>
                            </span>
                        </div>
                        <?php if ($isRegression && $fromLbl && $toLbl): ?>
                            <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px;">
                                <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>
                                <?= h($fromLbl) ?> &rarr; <?= h($toLbl) ?>
                            </div>
                        <?php endif; ?>
                        <div style="font-size:var(--fs-body);color:var(--text-default);line-height:1.5;">
                            <?= nl2br(h($obs->message)) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Documentos / Soportes -->
        <div class="sgi-card">
            <div class="d-flex justify-content-between align-items-center" style="margin-bottom:14px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-paperclip" aria-hidden="true"></i>
                    Soportes
                    <span class="sgi-folder-count"><?= $totalDocs ?></span>
                </span>
                <button type="button" class="btn btn-default btn-sm" disabled>
                    <i class="bi bi-upload" aria-hidden="true"></i>Subir
                </button>
            </div>

            <?php if ($totalDocs === 0): ?>
                <div class="empty-state">
                    <div class="es-icon es-icon-neutral"><i class="bi bi-file-earmark" aria-hidden="true"></i></div>
                    <div class="es-title">Sin documentos adjuntos</div>
                    <div class="es-msg">PDF, JPG, PNG · máximo 10&nbsp;MB por archivo</div>
                </div>
            <?php else: ?>
                <div class="col-flex" style="gap:0;">
                <?php
                $rowIndex = 0;
                foreach ($documentsByStatus as $status => $docs):
                    foreach ($docs as $doc):
                        $docStatusPill = $statusPills[$status] ?? 'pill-muted';
                        $docStatusLbl  = $pipelineLabels[$status] ?? $status;
                        $uploadedBy    = $doc->has('uploaded_by_user') ? $doc->uploaded_by_user->full_name : '—';
                ?>
                    <div class="d-flex align-items-center" style="gap:12px;padding:11px 0;<?= $rowIndex === 0 ? '' : 'border-top:1px solid var(--rule);' ?>">
                        <i class="bi <?= h($this->DocumentIcon->iconClass($doc->mime_type)) ?>"
                           style="color:<?= h($this->DocumentIcon->iconColor($doc->mime_type)) ?>;font-size:20px;flex-shrink:0;" aria-hidden="true"></i>
                        <div style="flex:1;min-width:0;">
                            <div class="mono" style="font-size:var(--fs-body);font-weight:600;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($doc->file_name) ?>">
                                <?= h($doc->document_type ?: $doc->file_name) ?>
                            </div>
                            <div class="d-flex align-items-center" style="gap:8px;margin-top:2px;font-size:10.5px;color:var(--text-faint);">
                                <span class="pill pill-sm <?= $docStatusPill ?>"><?= h($docStatusLbl) ?></span>
                                <span><?= h($uploadedBy) ?></span>
                                <?php if ($doc->file_size): ?>
                                <span class="mono">· <?= h($this->Number->toReadableSize($doc->file_size)) ?></span>
                                <?php endif; ?>
                                <?php if ($doc->created): ?>
                                <span class="mono" style="margin-left:auto;">
                                    <?= $doc->created->format('d/m/Y H:i') ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex" style="gap:4px;flex-shrink:0;">
                            <?= $this->Html->link(
                                '<i class="bi bi-eye" aria-hidden="true"></i>',
                                '/' . $doc->file_path,
                                ['class' => 'btn-icon', 'escape' => false, 'target' => '_blank', 'title' => 'Ver']
                            ) ?>
                            <?= $this->Html->link(
                                '<i class="bi bi-download" aria-hidden="true"></i>',
                                '/' . $doc->file_path,
                                ['class' => 'btn-icon', 'escape' => false, 'download' => $doc->file_name, 'title' => 'Descargar']
                            ) ?>
                        </div>
                    </div>
                <?php
                        $rowIndex++;
                    endforeach;
                endforeach;
                ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Historial de cambios -->
        <?php $histCount = count($invoice->invoice_histories ?? []); ?>
        <div class="sgi-card">
            <div class="d-flex justify-content-between align-items-center" style="margin-bottom:14px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                    Historial de Cambios
                    <span class="sgi-folder-count"><?= $histCount ?></span>
                </span>
            </div>

            <?php if ($histCount === 0): ?>
                <div class="empty-state">
                    <div class="es-icon es-icon-neutral"><i class="bi bi-clock-history" aria-hidden="true"></i></div>
                    <div class="es-title">Sin eventos registrados</div>
                    <div class="es-msg">Aún no se han registrado cambios en esta factura.</div>
                </div>
            <?php else: ?>
                <div class="col-flex">
                <?php foreach ($invoice->invoice_histories as $hi => $history):
                    $hUser = $history->hasValue('user') ? $history->user->full_name : '—';
                    $hField = $fieldLabels[$history->field_changed] ?? $history->field_changed;
                ?>
                    <div class="d-flex align-items-center" style="gap:12px;padding:10px 0;<?= $hi === 0 ? '' : 'border-top:1px solid var(--rule);' ?>font-size:var(--fs-body-sm);">
                        <span class="mono" style="color:var(--text-muted);flex-shrink:0;min-width:110px;">
                            <?= $history->created ? $history->created->format('d/m/Y H:i') : '' ?>
                        </span>
                        <span class="d-inline-flex align-items-center" style="gap:6px;flex-shrink:0;min-width:140px;">
                            <span class="av av-sm"><?= h($initialsOf($hUser)) ?></span>
                            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($hUser) ?></span>
                        </span>
                        <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= h($hField) ?>
                        </span>
                        <span style="color:var(--text-muted);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= $history->old_value ? h($history->old_value) : '—' ?>
                        </span>
                        <i class="bi bi-arrow-right" aria-hidden="true" style="color:var(--text-faint);font-size:11px;flex-shrink:0;"></i>
                        <span style="color:var(--primary-color);font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= $history->new_value ? h($history->new_value) : '—' ?>
                        </span>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

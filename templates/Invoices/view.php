<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 * @var string $roleName
 * @var bool $isRejected
 * @var string[] $pipelineStatuses
 * @var string[] $pipelineLabels
 * @var string[] $fieldLabels
 */

use App\Constants\InvoiceConstants;
use App\View\Presentation\InvoicePresentation;
use App\View\Presentation\SharedPresentation;

$this->assign('title', 'Factura ' . ($invoice->invoice_number ?? '#' . $invoice->id));

// ─── Datos derivados de presentación ────────────────────────────────
$currentStatus = $invoice->pipeline_status ?? '';
$statusLabel = InvoiceConstants::STATUS_LABELS[$currentStatus] ?? 'Desconocido';

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

// Pill kind para flags Sí/No y validación DIAN / aprobación.
$yesPill = 'pill-primary-soft';
$noPill = 'pill-warning-soft';
$readyForPaymentPills = [
    InvoiceConstants::READY_FOR_PAYMENT_SI          => 'pill-primary-soft',
    InvoiceConstants::READY_FOR_PAYMENT_PRIORITARIO => 'pill-danger-soft',
    InvoiceConstants::READY_FOR_PAYMENT_PSE         => 'pill-dark',
];

$pipelineSteps = $pipelineStatuses;
$currentIdx = array_search($currentStatus, $pipelineSteps, true);
if ($currentIdx === false) {
    $currentIdx = count($pipelineSteps); // estado fuera del pipeline visible → todo done.
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

// Aprobadores que ya aprobaron (para Aprobado Por).
$approvedNames = [];
foreach ($invoice->invoice_approvals ?? [] as $a) {
    if ($a->status === InvoiceConstants::APPROVER_STATUS_APPROVED && $a->hasValue('user')) {
        $approvedNames[] = $a->user->full_name ?? $a->user->username ?? ('Usuario #' . $a->user_id);
    }
}
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

<!-- Encabezado de página: breadcrumb + título + acciones -->
<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div>
        <div class="sgi-breadcrumb">
            <?= $this->Html->link('Todas las Facturas', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current">Ver Factura</span>
        </div>
        <span class="sgi-page-title">Ver Factura</span>
    </div>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
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

<div class="sgi-invoice-view-grid view-anim">

    <!-- ═══════════════════════════ COLUMNA IZQUIERDA ═══════════════════════════ -->
    <aside class="sgi-invoice-view-left">

        <!-- Hero summary -->
        <div class="card" style="padding:20px;">
            <div class="d-flex align-items-start" style="gap:12px;margin-bottom:16px;">
                <div class="sgi-hero-icon">
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                </div>
                <div style="min-width:0;flex:1;">
                    <div class="sgi-hero-id"><?= h($invoice->invoice_number ?? ('# ' . $invoice->id)) ?></div>
                    <div class="d-flex flex-wrap" style="gap:4px;margin-top:6px;">
                        <span class="pill pill-secondary"><?= h($invoice->document_type) ?></span>
                        <?php if ($isRejected): ?>
                            <span class="pill pill-danger-soft">Rechazada</span>
                        <?php elseif (!empty($isApproved)): ?>
                            <span class="pill pill-primary-soft">
                                <i class="bi bi-check2" aria-hidden="true" style="font-size:9px;"></i>Aprobada
                            </span>
                        <?php else: ?>
                            <span class="pill <?= $statusPill ?>"><?= h($statusLabel) ?></span>
                        <?php endif; ?>
                        <?php if ($currentStatus === InvoiceConstants::STATUS_TESORERIA && $invoice->payment_status === InvoiceConstants::PAYMENT_PARTIAL): ?>
                            <span class="pill pill-warning-soft">Pago Parcial</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="sgi-label">Proveedor</div>
            <div style="font-size:var(--fs-body);font-weight:600;color:var(--text-default);margin-top:4px;line-height:1.3;">
                <?php $isReciboDeCaja = ($invoice->document_type ?? '') === InvoiceConstants::DOCTYPE_RECIBO_CAJA; ?>
                <?php if ($isReciboDeCaja && ($invoice->equivalent_holder_type ?? '') === 'employee'): ?>
                    <?= $invoice->hasValue('employee') ? h($invoice->employee->full_name) : '—' ?>
                <?php elseif ($isReciboDeCaja && ($invoice->equivalent_holder_type ?? '') === 'manual'): ?>
                    <?= h($invoice->manual_document_number ?? '—') ?>
                <?php else: ?>
                    <?= $invoice->hasValue('provider') ? h($invoice->provider->name) : '—' ?>
                <?php endif; ?>
            </div>
            <?php if ($invoice->hasValue('operation_center')): ?>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;" class="d-flex align-items-center gap-1">
                <i class="bi bi-geo-alt" aria-hidden="true" style="font-size:11px;"></i>
                <span><?= h($invoice->operation_center->name) ?></span>
            </div>
            <?php endif; ?>

            <div class="sgi-hero-divider">
                <div class="sgi-label">Valor Factura</div>
                <div class="d-flex align-items-baseline" style="gap:4px;margin-top:4px;">
                    <span class="sgi-hero-display">$ <?= $amountInt ?></span>
                    <span class="sgi-hero-decimals"><?= $amountDec ?></span>
                </div>
                <?php if ($currentStatus === InvoiceConstants::STATUS_PAGADA && $invoice->full_payment_date): ?>
                <div style="font-size:11px;color:var(--text-muted);margin-top:4px;" class="d-flex align-items-center gap-1">
                    <i class="bi bi-check-circle-fill sgi-fg-primary" aria-hidden="true" style="font-size:11px;"></i>
                    <span>Pagado · <span class="mono"><?= $invoice->full_payment_date->format('d/m/Y') ?></span></span>
                </div>
                <?php elseif ($invoice->payment_status === InvoiceConstants::PAYMENT_PARTIAL && $pagosCount > 0): ?>
                <div style="font-size:11px;color:var(--text-muted);margin-top:4px;" class="d-flex align-items-center gap-1">
                    <i class="bi bi-clock sgi-fg-warning" aria-hidden="true" style="font-size:11px;"></i>
                    <span>Pago parcial · <span class="mono">$ <?= number_format($pagosTotal, 0, ',', '.') ?></span></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pipeline vertical -->
        <div class="card" style="padding:20px;">
            <div class="sgi-section-head"><span class="sgi-label">Pipeline</span></div>
            <div class="sgi-pipeline-v">
                <?php
                $isTerminal = in_array($currentStatus, [InvoiceConstants::STATUS_PAGADA, InvoiceConstants::STATUS_LEGALIZADA], true);
                foreach ($pipelineSteps as $idx => $stepKey):
                    // En estado terminal el último paso se renderiza como done (check),
                    // no como current (dot) — la factura ya cerró el flujo.
                    $isDone = $idx < $currentIdx || ($isTerminal && $idx === $currentIdx);
                    $isCurrent = !$isTerminal && $idx === $currentIdx;
                    $stepLabel = $pipelineLabels[$stepKey] ?? $stepKey;
                    $stepClasses = 'sgi-pipeline-v-step';
                    if ($isDone) { $stepClasses .= ' is-done'; }
                    if ($isCurrent) { $stepClasses .= ' is-current'; }
                    if ($isCurrent && $isRejected) { $stepClasses .= ' is-rejected'; }

                    // Timestamp del paso actual (no podemos derivar el de cada paso pasado sin
                    // ir al historial; mostramos modified para el paso actual, "Pendiente" para
                    // los futuros, y dejamos sin meta los completados — match con la lectura
                    // visual del mockup).
                    $stepMeta = null;
                    if ($isCurrent || ($isTerminal && $idx === $currentIdx)) {
                        $stepMeta = $invoice->modified?->format('d/m H:i') ?? null;
                    } elseif (!$isDone) {
                        $stepMeta = 'Pendiente';
                    }
                ?>
                <div class="<?= $stepClasses ?>">
                    <div class="sgi-pipeline-v-marker">
                        <?php if ($isCurrent && $isRejected): ?>
                            <i class="bi bi-x" aria-hidden="true"></i>
                        <?php elseif ($isDone): ?>
                            <i class="bi bi-check2" aria-hidden="true"></i>
                        <?php elseif ($isCurrent): ?>
                            <span class="dot"></span>
                        <?php endif; ?>
                    </div>
                    <div class="sgi-pipeline-v-content">
                        <div class="sgi-pipeline-v-label"><?= h($stepLabel) ?></div>
                        <?php if ($stepMeta): ?>
                            <div class="sgi-pipeline-v-meta"><?= h($stepMeta) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Barra de auditoría / contacto -->
        <div class="card" style="padding:16px 20px;">
            <div class="sgi-section-head" style="margin-bottom:10px;">
                <span class="sgi-label">Registro</span>
            </div>
            <?php if ($invoice->hasValue('registered_by_user')): ?>
            <div style="font-size:var(--fs-body-sm);color:var(--text-muted);" class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-person sgi-fg-faint" aria-hidden="true"></i>
                <span><?= h($invoice->registered_by_user->full_name) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($invoice->created): ?>
            <div style="font-size:var(--fs-body-sm);color:var(--text-muted);" class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-calendar3 sgi-fg-faint" aria-hidden="true"></i>
                <span>Creado · <span class="mono"><?= $invoice->created->format('d/m/Y') ?></span></span>
            </div>
            <?php endif; ?>
            <?php if ($invoice->modified): ?>
            <div style="font-size:var(--fs-body-sm);color:var(--text-muted);" class="d-flex align-items-center gap-2">
                <i class="bi bi-pencil-square sgi-fg-faint" aria-hidden="true"></i>
                <span>Modificado · <span class="mono"><?= $invoice->modified->format('d/m/Y') ?></span></span>
            </div>
            <?php endif; ?>
        </div>

    </aside>

    <!-- ═══════════════════════════ COLUMNA DERECHA ═══════════════════════════ -->
    <main class="sgi-invoice-view-right">

        <!-- Documento + Clasificación -->
        <div class="card" style="padding:20px;">
            <div class="sgi-card-cols-2">
                <div>
                    <div class="sgi-section-head"><span class="sgi-label">Documento</span></div>
                    <div class="sgi-field-row">
                        <span class="k">Fecha Registro</span>
                        <span class="v mono"><?= $invoice->registration_date?->format('d/m/Y') ?? '—' ?></span>
                    </div>
                    <div class="sgi-field-row">
                        <span class="k">Fecha Emisión</span>
                        <span class="v mono"><?= $invoice->issue_date?->format('d/m/Y') ?? '—' ?></span>
                    </div>
                    <div class="sgi-field-row">
                        <span class="k">Fecha Vencimiento</span>
                        <span class="v mono"><?= $invoice->due_date?->format('d/m/Y') ?? '—' ?></span>
                    </div>
                    <div class="sgi-field-row">
                        <span class="k">Orden de Compra</span>
                        <?php if ($invoice->purchase_order): ?>
                            <span class="v"><?= h($invoice->purchase_order) ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <div class="sgi-section-head"><span class="sgi-label">Clasificación</span></div>
                    <div class="sgi-field-row">
                        <span class="k">Titular</span>
                        <span class="v">
                            <?php if ($isReciboDeCaja && ($invoice->equivalent_holder_type ?? '') === 'employee'): ?>
                                <?= $invoice->hasValue('employee') ? h($invoice->employee->full_name) : '—' ?>
                            <?php elseif ($isReciboDeCaja && ($invoice->equivalent_holder_type ?? '') === 'manual'): ?>
                                <?= h($invoice->manual_document_number ?? '—') ?>
                            <?php else: ?>
                                <?= $invoice->hasValue('provider') ? h($invoice->provider->name) : '—' ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="sgi-field-row">
                        <span class="k">Tipo de Gasto</span>
                        <?php if ($invoice->hasValue('expense_type')): ?>
                            <span class="v"><?= h($invoice->expense_type->name) ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="sgi-field-row">
                        <span class="k">Centro de Costos</span>
                        <?php if ($invoice->hasValue('cost_center')): ?>
                            <span class="v"><?= h($invoice->cost_center->name) ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="sgi-field-row">
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
            <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--rule);">
                <div class="sgi-section-head" style="margin-bottom:8px;"><span class="sgi-label">Detalle</span></div>
                <div style="font-size:var(--fs-body-lg);color:var(--text-default);line-height:1.5;">
                    <?= nl2br(h($invoice->detail)) ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Revisión + Contabilidad + Tesorería -->
        <div class="card" style="padding:20px;">
            <div class="sgi-card-cols-3">
                <!-- Revisión -->
                <div>
                    <div class="sgi-section-head"><span class="sgi-label">Revisión</span></div>
                    <div class="sgi-field-row">
                        <span class="k">Aprobador</span>
                        <?php if ($invoice->hasValue('approver_user')): ?>
                            <span class="v"><?= h($invoice->approver_user->full_name) ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="sgi-field-row">
                        <span class="k">Aprobado Por</span>
                        <?php if (!empty($approvedNames)): ?>
                            <span class="v"><?= h(implode(', ', $approvedNames)) ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="sgi-field-row">
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
                            <span class="pill <?= $approvalPill ?>"><?= h($approval) ?></span>
                        </span>
                    </div>
                    <div class="sgi-field-row">
                        <span class="k">Fecha Aprobación</span>
                        <?php if ($invoice->area_approval_date): ?>
                            <span class="v mono"><?= $invoice->area_approval_date->format('d/m/Y') ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="sgi-field-row">
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
                            <span class="pill <?= $dianPill ?>"><?= h($dian) ?></span>
                        </span>
                    </div>
                </div>

                <!-- Contabilidad -->
                <div>
                    <div class="sgi-section-head"><span class="sgi-label">Contabilidad</span></div>
                    <div class="sgi-field-row">
                        <span class="k">Causada</span>
                        <span class="v">
                            <?php if ($invoice->accrued): ?>
                                <span class="pill pill-primary-soft">Sí</span>
                            <?php else: ?>
                                <span class="pill pill-warning-soft">No</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="sgi-field-row">
                        <span class="k">Fecha Causación</span>
                        <?php if ($invoice->accrual_date): ?>
                            <span class="v mono"><?= $invoice->accrual_date->format('d/m/Y') ?></span>
                        <?php else: ?>
                            <span class="v dash">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="sgi-field-row">
                        <span class="k">Lista para Pago</span>
                        <span class="v">
                            <?php if (!empty($invoice->ready_for_payment)): ?>
                                <?php $rfpPill = $readyForPaymentPills[$invoice->ready_for_payment] ?? 'pill-muted'; ?>
                                <span class="pill <?= $rfpPill ?>"><?= h($invoice->ready_for_payment) ?></span>
                            <?php else: ?>
                                <span class="pill pill-warning-soft">No</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <!-- Tesorería -->
                <div>
                    <div class="sgi-section-head"><span class="sgi-label">Tesorería</span></div>
                    <div class="sgi-field-row">
                        <span class="k">Estado Pago</span>
                        <span class="v">
                            <?php if ($invoice->payment_status === InvoiceConstants::PAYMENT_PARTIAL): ?>
                                <span class="pill pill-warning-soft"><?= h($invoice->payment_status) ?></span>
                            <?php elseif ($invoice->payment_status === InvoiceConstants::PAYMENT_FULL): ?>
                                <span class="pill pill-primary-soft"><?= h($invoice->payment_status) ?></span>
                            <?php else: ?>
                                <span class="v dash">—</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="sgi-field-row">
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

        <!-- Observaciones -->
        <?php if (!empty($invoice->invoice_observations)): ?>
        <?php $statusLabelsMap = InvoiceConstants::STATUS_LABELS; ?>
        <div class="card" style="padding:20px;">
            <div class="sgi-section-head">
                <span class="sgi-label">Observaciones · <?= count($invoice->invoice_observations) ?></span>
            </div>
            <?php foreach ($invoice->invoice_observations as $i => $obs):
                $obsType = $obs->type ?? null;
                $isRegression = $obsType === InvoiceConstants::OBSERVATION_TYPE_REGRESSION;
                $isExternalApproval = $obsType === InvoiceConstants::OBSERVATION_TYPE_EXTERNAL_APPROVAL;
                $meta = $obs->metadata ?? [];
                if (is_string($meta)) {
                    $decoded = json_decode($meta, true);
                    $meta = is_array($decoded) ? $decoded : [];
                }
                $fromLbl = $statusLabelsMap[$meta['from_status'] ?? ''] ?? null;
                $toLbl = $statusLabelsMap[$meta['to_status'] ?? ''] ?? null;
                $extAction = $meta['action'] ?? null;
                $extActionLabel = $extAction === 'approve' ? 'Aprobada' : ($extAction === 'reject' ? 'Rechazada' : null);
                if ($isExternalApproval) {
                    $avatarBg = $extAction === 'reject' ? 'var(--danger-color)' : 'var(--primary-color)';
                } elseif ($isRegression) {
                    $avatarBg = 'var(--secondary-color)';
                } else {
                    $avatarBg = 'var(--primary-color)';
                }
                $allowedAvatarBg = ['var(--danger-color)', 'var(--primary-color)', 'var(--secondary-color)'];
                if (!in_array($avatarBg, $allowedAvatarBg, true)) {
                    $avatarBg = 'var(--primary-color)';
                }
                $names = explode(' ', $obs->user->full_name ?? '');
                $initials = strtoupper(substr($names[0] ?? '', 0, 1) . substr($names[1] ?? '', 0, 1));
                $isLast = $i === count($invoice->invoice_observations) - 1;
            ?>
            <div class="d-flex" style="gap:12px;padding:10px 0;<?= $isLast ? '' : 'border-bottom:1px solid var(--rule);' ?>">
                <div style="width:32px;height:32px;background:<?= $avatarBg ?>;color:#fff;font-size:11px;font-weight:700;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;">
                    <?= h($initials) ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="d-flex align-items-center flex-wrap" style="gap:8px;margin-bottom:4px;">
                        <span style="font-size:var(--fs-body);font-weight:600;color:var(--text-strong);"><?= h($obs->user->full_name ?? '') ?></span>
                        <?php if ($isRegression): ?>
                            <span class="pill pill-warning-soft">Regresión</span>
                        <?php elseif ($isExternalApproval): ?>
                            <span class="pill <?= $extAction === 'reject' ? 'pill-danger-soft' : 'pill-primary-soft' ?>">
                                Aprobación Externa<?= $extActionLabel ? ': ' . h($extActionLabel) : '' ?>
                            </span>
                        <?php endif; ?>
                        <span style="margin-left:auto;font-size:var(--fs-label);color:var(--text-faint);font-family:var(--font-mono);">
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
        </div>
        <?php endif; ?>

        <!-- Pagos Registrados -->
        <?php if (!empty($invoice->invoice_payments)): ?>
        <div class="card" style="padding:0;overflow:hidden;">
            <div style="padding:18px 20px 14px;" class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="sgi-title-card">Pagos Registrados</div>
                    <div style="font-size:11px;color:var(--text-faint);margin-top:2px;">
                        <?= $pagosCount ?> movimiento<?= $pagosCount === 1 ? '' : 's' ?> · Total
                        <span class="mono">$ <?= number_format($pagosTotal, 0, ',', '.') ?></span>
                    </div>
                </div>
            </div>

            <div class="sgi-mini-table-head" style="grid-template-columns:1.4fr 1fr 1fr 1.2fr 1.3fr 0.8fr;">
                <span>Entidad Bancaria</span>
                <span>Monto</span>
                <span>Fecha</span>
                <span>Estado</span>
                <span>Registrado por</span>
                <span>Origen</span>
            </div>

            <?php foreach ($invoice->invoice_payments as $payment):
                $pSt = $payment->status ?? ($payment->authorized ? 'authorized' : 'pending');
            ?>
            <div class="sgi-mini-table-row" style="grid-template-columns:1.4fr 1fr 1fr 1.2fr 1.3fr 0.8fr;">
                <div class="d-flex align-items-center" style="gap:10px;min-width:0;">
                    <div class="sgi-bank-chip">
                        <i class="bi bi-bank" aria-hidden="true"></i>
                    </div>
                    <span style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= h($payment->banking_entity->name ?? '—') ?>
                    </span>
                </div>
                <span style="font-weight:700;color:var(--primary-color);font-family:var(--font-mono);">
                    $ <?= number_format((float)$payment->amount, 0, ',', '.') ?>
                </span>
                <span style="font-family:var(--font-mono);"><?= $payment->payment_date?->format('d/m/Y') ?? '—' ?></span>
                <span>
                    <?php if ($pSt === 'authorized'): ?>
                        <span class="pill pill-primary-soft">
                            <i class="bi bi-check2" aria-hidden="true" style="font-size:9px;"></i>Autorizado
                        </span>
                        <?php if ($payment->authorized_by_user): ?>
                        <div style="font-size:var(--fs-meta);color:var(--text-faint);margin-top:3px;">
                            <?= h($payment->authorized_by_user->full_name ?? '') ?> · <span class="mono"><?= $payment->authorized_date?->format('d/m/Y') ?? '' ?></span>
                        </div>
                        <?php endif; ?>
                    <?php elseif ($pSt === 'rejected'): ?>
                        <span class="pill pill-danger-soft">
                            <i class="bi bi-x" aria-hidden="true" style="font-size:9px;"></i>Rechazado
                        </span>
                        <?php if (!empty($payment->rejection_reason)): ?>
                        <div style="font-size:var(--fs-meta);color:var(--text-faint);margin-top:3px;line-height:1.3;">
                            <?= h($payment->rejection_reason) ?>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="pill pill-warning-soft">
                            <i class="bi bi-clock" aria-hidden="true" style="font-size:9px;"></i>Pendiente
                        </span>
                    <?php endif; ?>
                </span>
                <div style="min-width:0;">
                    <div style="font-size:var(--fs-body-sm);font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= h($payment->created_by_user->full_name ?? $payment->created_by_user->username ?? '—') ?>
                    </div>
                    <?php if ($payment->created): ?>
                    <div style="font-size:var(--fs-label);color:var(--text-faint);font-family:var(--font-mono);">
                        <?= $payment->created->format('d/m/Y') ?>
                    </div>
                    <?php endif; ?>
                </div>
                <span style="color:var(--text-muted);font-size:var(--fs-body-sm);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?php if (!empty($payment->payment_scheduling_id)): ?>
                        <?= $this->Html->link(
                            '<i class="bi bi-calendar-check me-1" aria-hidden="true"></i>' . h($payment->payment_scheduling->code ?? '#' . $payment->payment_scheduling_id),
                            ['controller' => 'PaymentSchedulings', 'action' => 'view', $payment->payment_scheduling_id],
                            ['class' => 'btn-link', 'escape' => false]
                        ) ?>
                    <?php elseif (!empty($payment->petty_cash_record_id)): ?>
                        <?= $this->Html->link(
                            '<i class="bi bi-wallet2 me-1" aria-hidden="true"></i>' . h($payment->petty_cash_record->code ?? '#' . $payment->petty_cash_record_id),
                            ['controller' => 'PettyCashRecords', 'action' => 'view', $payment->petty_cash_record_id],
                            ['class' => 'btn-link', 'escape' => false]
                        ) ?>
                    <?php else: ?>
                        Individual
                    <?php endif; ?>
                </span>
            </div>
            <?php endforeach; ?>

            <div class="sgi-mini-table-row is-total" style="grid-template-columns:1.4fr 1fr 1fr 1.2fr 1.3fr 0.8fr;">
                <span style="font-weight:700;color:var(--text-strong);font-size:var(--fs-body-lg);">Total Pagado</span>
                <span style="font-weight:700;color:var(--primary-color);font-family:var(--font-mono);font-size:var(--fs-title-card);">
                    $ <?= number_format($pagosTotal, 0, ',', '.') ?>
                </span>
                <span></span><span></span><span></span><span></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Soportes -->
        <div class="card" style="padding:18px 20px;">
            <div class="sgi-section-head" style="margin-bottom:12px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-paperclip" aria-hidden="true"></i>
                    Soportes
                    <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
                </span>
            </div>

            <?php if (empty($documentsByStatus)): ?>
                <div class="sgi-dropzone-empty">
                    <i class="bi bi-paperclip" aria-hidden="true"></i>
                    <div>Arrastra archivos aquí o <span class="accent">examina</span></div>
                    <div style="font-size:var(--fs-label);margin-top:4px;">PDF, JPG, PNG · máximo 10 MB por archivo</div>
                </div>
            <?php else: ?>
                <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
                    <?php foreach ($documentsByStatus as $status => $docs): ?>
                        <?php foreach ($docs as $doc): ?>
                        <div class="col">
                            <div style="background:var(--bg-muted);height:100%;display:flex;flex-direction:column;">
                                <div style="padding:.6rem .875rem;border-bottom:1px solid var(--rule);background:#fff;display:flex;align-items:center;gap:.5rem;min-width:0;">
                                    <i class="bi <?= h($this->DocumentIcon->iconClass($doc->mime_type)) ?> flex-shrink-0"
                                       style="color:<?= h($this->DocumentIcon->iconColor($doc->mime_type)) ?>;font-size:1.1rem;"></i>
                                    <div style="min-width:0;flex:1;overflow:hidden;">
                                        <span style="font-size:var(--fs-body-sm);font-weight:600;color:var(--text-strong);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;" title="<?= h($doc->document_type ?: $doc->file_name) ?>">
                                            <?= h($doc->document_type ?: $doc->file_name) ?>
                                        </span>
                                        <?php if ($doc->document_type): ?>
                                        <span style="font-size:var(--fs-label);color:var(--text-faint);font-family:var(--font-mono);display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($doc->file_name) ?>">
                                            <?= h($doc->file_name) ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="padding:.6rem .875rem;flex:1;font-size:var(--fs-body-sm);color:var(--text-muted);display:flex;flex-direction:column;gap:.3rem;">
                                    <?php
                                    $statusKey = $status;
                                    $docStatusPill = $statusPills[$statusKey] ?? 'pill-muted';
                                    $statusLabel = $pipelineLabels[$statusKey] ?? $statusKey;
                                    ?>
                                    <div>
                                        <span class="pill <?= $docStatusPill ?>"><?= h($statusLabel) ?></span>
                                    </div>
                                    <div class="d-flex align-items-center gap-1" style="color:var(--text-muted);">
                                        <i class="bi bi-person sgi-fg-faint" aria-hidden="true" style="font-size:.8rem;"></i>
                                        <span><?= $doc->has('uploaded_by_user') ? h($doc->uploaded_by_user->full_name) : '—' ?></span>
                                    </div>
                                    <div class="d-flex align-items-center gap-1" style="color:var(--text-faint);font-family:var(--font-mono);font-size:var(--fs-label);">
                                        <i class="bi bi-clock sgi-fg-faint" aria-hidden="true" style="font-size:var(--fs-body-sm);font-family:'bootstrap-icons';"></i>
                                        <span><?= $doc->created?->format('d/m/Y H:i') ?></span>
                                    </div>
                                    <?php if ($doc->file_size): ?>
                                    <div style="color:var(--text-disabled);font-size:var(--fs-label);font-family:var(--font-mono);">
                                        <?= $this->Number->toReadableSize($doc->file_size) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div style="padding:.5rem .875rem;border-top:1px solid var(--rule);text-align:right;background:#fff;">
                                    <?= $this->Html->link(
                                        '<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Abrir',
                                        '/' . $doc->file_path,
                                        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false, 'target' => '_blank']
                                    ) ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Historial de cambios -->
        <div class="card" style="padding:0;overflow:hidden;">
            <div style="padding:18px 20px 14px;">
                <div class="sgi-title-card">Historial de Cambios</div>
                <div style="font-size:11px;color:var(--text-faint);margin-top:2px;">
                    <?= count($invoice->invoice_histories ?? []) ?> evento<?= count($invoice->invoice_histories ?? []) === 1 ? '' : 's' ?> registrado<?= count($invoice->invoice_histories ?? []) === 1 ? '' : 's' ?>
                </div>
            </div>

            <?php if (!empty($invoice->invoice_histories)): ?>
            <div class="sgi-mini-table-head" style="grid-template-columns:1.3fr 1.3fr 1.6fr 1.4fr 1.4fr;">
                <span>Fecha</span>
                <span>Usuario</span>
                <span>Campo</span>
                <span>Valor Anterior</span>
                <span>Valor Nuevo</span>
            </div>
            <?php foreach ($invoice->invoice_histories as $history): ?>
            <div class="sgi-mini-table-row" style="grid-template-columns:1.3fr 1.3fr 1.6fr 1.4fr 1.4fr;font-size:var(--fs-body-sm);">
                <span style="font-family:var(--font-mono);color:var(--text-muted);">
                    <?= $history->created ? $history->created->format('d/m/Y H:i') : '' ?>
                </span>
                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= $history->hasValue('user') ? h($history->user->full_name) : '' ?>
                </span>
                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= h($fieldLabels[$history->field_changed] ?? $history->field_changed) ?>
                </span>
                <span style="color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= h($history->old_value) ?: '—' ?>
                </span>
                <span style="color:var(--primary-color);font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= h($history->new_value) ?: '—' ?>
                </span>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div style="padding:20px;text-align:center;color:var(--text-faint);font-size:var(--fs-body);background:var(--bg-muted);">
                Sin eventos registrados aún
            </div>
            <?php endif; ?>
        </div>

    </main>
</div>
